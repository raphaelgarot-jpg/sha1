<?php
/**
 * SHA 2026 - Fonctions Core (RAM Powered)
 */

/**
 * Récupère la puissance d'un appareil directement depuis le cache RAM global
 */
function getSmartMeter($ip) {
    if (empty($ip)) return 0;

    $cache_file = '/var/www/html/sha/data/sha_live.json';
    if (!file_exists($cache_file)) return 0;

    $live_data = json_decode(@file_get_contents($cache_file), true);
    return $live_data['devices'][$ip]['power'] ?? 0;
}

/**
 * Permet de conserver la compatibilité si un ancien script requiert l'état On/Off
 */
function getTasmotaState($ip, $relay = 1) {
    $power = getSmartMeter($ip);
    return ($power > 1.0) ? 'ON' : 'OFF';
}

/**
 * Calcule la puissance corrigée du solaire depuis la RAM
 * Note : Le cache builder stocke déjà la puissance active calculée.
 */
function getSolarPower($ip) {
    return getSmartMeter($ip);
}

if (!function_exists('handle_device_action')) {
    /**
     * Traite les actions interactives (Tasmota, ADB Android, Gradation OpenBeken, etc.)
     */
    function handle_device_action() {
        // --- 1. LECTURE PRÉALABLE DE LA CONFIGURATION MQTT DEPUIS APP.CONF ---
        $mqtt_user = "";
        $mqtt_pass = "";
        $app_conf_path = dirname(__DIR__) . '/config/app.conf';
        if (file_exists($app_conf_path)) {
            $app_config = parse_ini_file($app_conf_path, true);
            if (isset($app_config['MQTT'])) {
                $mqtt_user = $app_config['MQTT']['user'] ?? $mqtt_user;
                $mqtt_pass = $app_config['MQTT']['password'] ?? $mqtt_pass;
                $mqtt_host = $app_config['MQTT']['host'] ?? $mqtt_host;
            }
        }
        $auth_part = "-h " . escapeshellarg($mqtt_host) . " -u " . escapeshellarg($mqtt_user) . " -P " . escapeshellarg($mqtt_pass);

        

// --- NOUVEAU CAS : REQUÊTE DE GRADATION VIA LE CURSEUR ---
        if (isset($_POST['ip']) && isset($_POST['action']) && isset($_POST['value'])) {
            header('Content-Type: application/json');
            $ip = filter_var($_POST['ip'], FILTER_VALIDATE_IP);
            $action = $_POST['action']; // 'dimmer'
            $value = intval($_POST['value']);

            if (!$ip) {
                echo json_encode(['success' => false, 'message' => 'Ungültige IP']);
                exit;
            }

            if ($action === 'dimmer') {
            // 💡 EXTRACTION DYNAMIQUE DU NOM MQTT DEPUIS LE CACHE RAM
            $mqtt_name = "";
            $cache_file = '/var/www/html/sha/data/sha_live.json';
            
            if (file_exists($cache_file)) {
                $live_data = json_decode(@file_get_contents($cache_file), true);
                // 🛡️ On ajoute trim() pour éliminer d'éventuels espaces ou retours à la ligne du script Python
                $mqtt_name = trim($live_data['devices'][$ip]['mqtt_name'] ?? "");
            }

            // Sécurité si le module n'a pas encore envoyé de trame au cache builder
            if (empty($mqtt_name)) {
                echo json_encode(['success' => false, 'message' => 'MQTT-Name nicht im Cache gefunden']);
                exit;
            }

            // 🔍 Détection spécifique du Shelly Dimmer 2
            $is_dimmer2 = (strpos(strtolower($mqtt_name), 'dimmer2') !== false);

            if ($is_dimmer2) {
                // ==========================================
                // CAS 1 : Shelly Dimmer 2 (Génération 1)
                // ==========================================
                $topic = "shellies/" . $mqtt_name . "/light/0/set";
                
                // On construit le JSON attendu par Shelly en sécurisant la valeur reçue (0-100)
                $payload = '{"turn":"on","brightness":' . intval($value) . '}';
                
                // Utilisation de escapeshellarg() pour éviter tout problème de guillemets dans le exec
                $cmd = "mosquitto_pub " . $auth_part . " -t " . escapeshellarg($topic) . " -m " . escapeshellarg($payload) . " > /dev/null 2>&1";
                @exec($cmd);

            } else {
                // ==========================================
                // CAS 2 : ALIGNEMENT DE SYNTAXE SUR LE BLOC OBK (OpenBeken)
                // ==========================================
                
                // Étape A : On applique l'intensité sur le canal 1
                @exec("mosquitto_pub $auth_part -t '{$mqtt_name}/1/set' -m '{$value}' > /dev/null 2>&1");

                // Étape B : Sécurité - On s'assure que la lampe est déverrouillée (1)
                @exec("mosquitto_pub $auth_part -t '{$mqtt_name}/led_enableAll' -m '1' > /dev/null 2>&1");

                // Étape C : On force le canal 0 (couleur) à 0 à chaque changement
                @exec("mosquitto_pub $auth_part -t '{$mqtt_name}/0/set' -m '0' > /dev/null 2>&1");
            }
                // 💾 [A.S.H.E.S. FEATURE] SAUVEGARDE PERSISTANTE LOCALE DU DIMMER
                            $local_states_file = dirname($cache_file) . '/dimmer_states.json';
                            $local_states = [];
                            if (file_exists($local_states_file)) {
                                $local_states = json_decode(@file_get_contents($local_states_file), true) ?? [];
                            }
                            // On associe l'intensité à l'IP de l'appareil
                            $local_states[$ip] = intval($value);
                            @file_put_contents($local_states_file, json_encode($local_states));

    echo json_encode(['success' => true, 'message' => 'Intensité et état appliqués']);
    exit;
}
            
            echo json_encode(['success' => false, 'message' => 'Aktion unbekannt']);
            exit;
        }

        // --- TRAITEMENT TRADITIONNEL DES ETATS (ON / OFF) ---
        if (isset($_POST['action']) && isset($_POST['ip'])) {
            header('Content-Type: application/json');

            $ip = filter_var($_POST['ip'], FILTER_VALIDATE_IP);
            $target_state = ($_POST['action'] === 'ON') ? 'ON' : 'OFF';
            $type = $_POST['type'] ?? 'socket';
            $relay_or_mac = $_POST['relay'] ?? '';
            
            // --- 💡 EXTRACTION DU NOM MQTT DEPUIS LE NOUVEAU CACHE PYTHON ---
            $mqtt_name = "";
            $cache_file = '/var/www/html/sha/data/sha_live.json';
            if (file_exists($cache_file)) {
                $live_data = json_decode(@file_get_contents($cache_file), true);
                // On récupère la clé 'mqtt_name' magiquement créée par le script Python
                $mqtt_name = $live_data['devices'][$ip]['mqtt_name'] ?? "";
            }

            if (!$ip) {
                echo json_encode(['success' => false, 'message' => 'Ungültige Parameter']);
                exit;
            }

           


            // Le flag $is_shelly s'active car le nom contient "shelly"
            $is_shelly = (strpos($mqtt_name, 'helly') !== false);

            if ($is_shelly) {
                // 🔍 Détection spécifique du Shelly Dimmer 2
                $is_dimmer2 = (strpos(strtolower($mqtt_name), 'dimmer2') !== false);

                if ($is_dimmer2) {
                    // ==========================================
                    // CAS 1 : Shelly Dimmer 2 (Génération 1)
                    // ==========================================
                    
                    // 💡 Note : Si ton $mqtt_name en BDD contient DÉJÀ toute la chaîne "Shelly_Dimmer2_BZ_...", 
                    // retire le "shellies/" ci-dessous pour ne pas doubler le préfixe.
                    $topic = "shellies/" . $mqtt_name . "/light/0/command"; 
                    
                    // Le Dimmer 1ère gen attend "on" ou "off" en minuscules
                    $payload = strtolower($target_state); 

                } else {
                    // ==========================================
                    // CAS 2 : Shelly Classique (Génération 2/3 - RPC)
                    // ==========================================
                    $topic = $mqtt_name . "/rpc";
                    $rpc_bool = ($target_state === 'ON') ? 'true' : 'false';
                    
                    // 💡 Gestion des IDs de Triggers
                    $trigger_id = intval($relay_or_mac); // ID du trigger pour le suivi (ex: 1, 2, 3...)
                    $switch_id = $trigger_id;        // Index du switch interne Shelly (ex: 0, 1, 2...)
                    if ($switch_id < 0) $switch_id = 0;

                    $payload = '{"id":' . $trigger_id . ',"src":"sha_backend","method":"Switch.Set","params":{"id":' . $switch_id . ',"on":' . $rpc_bool . '}}';
                }

                // 🚀 Génération de la commande commune mosquitto_pub
                $cmd = trim("mosquitto_pub " . $auth_part) . " -t " . escapeshellarg($topic) . " -m " . escapeshellarg($payload);
                
                // --- EXÉCUTION DU SCRIPT MQTT ---
                // Le "2>&1" permet de capturer les messages d'erreur système (droits, binaire manquant, etc.)
                @exec($cmd . " 2>&1", $output, $return_var);

                if ($return_var === 0) {
                    // Succès : On confirme le nouvel état au JavaScript pour mettre l'UI à jour
                    echo json_encode([
                        'success' => true, 
                        'new_state' => $target_state
                    ]);
                } else {
                    // Échec de la commande mosquitto_pub (Décommente pour débugger au besoin)
                    /*echo json_encode([
                        'success' => false, 
                        'message' => 'MQTT Exec Error (Code ' . $return_var . ')',
                        'error_details' => implode(' ', $output)
                    ]);*/
                }
                exit;
            }

            // --- CAS EXCLUSIF : MACHINE WINDOWS PC ---
            if ($type === 'pc') {
                if ($target_state === 'ON') {
                    $success = send_wake_on_lan($relay_or_mac);
                    if ($success) {
                        echo json_encode(['success' => true, 'new_state' => 'ON']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'WOL-Paket Fehler']);
                    }
                } else {
                    $win_user = "guest";
                    $win_pass = "";
                    $app_conf_path = dirname(__DIR__) . '/config/app.conf';

                    if (file_exists($app_conf_path)) {
                        $app_config = parse_ini_file($app_conf_path, true);
                        if (isset($app_config['Windows'])) {
                            $win_user = $app_config['Windows']['user'] ?? $win_user;
                            $win_pass = $app_config['Windows']['password'] ?? $win_pass;
                        }
                    }

                    $user_auth = $win_user . '%' . $win_pass;
                    $cmd = "net rpc shutdown -I " . escapeshellarg($ip) . " -U " . escapeshellarg($user_auth) . " -t 0 -f 2>&1";
                    @exec($cmd, $output, $return_var);

                    if ($return_var === 0) {
                        echo json_encode(['success' => true, 'new_state' => 'OFF']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'RPC Fehler: ' . implode(' ', $output)]);
                    }
                }
                exit;
            }

            // --- CAS EXCLUSIF : ANDROID / FIRE TV ---
            if ($type === 'android') {
                if ($target_state === 'ON') {
                    $success = send_wake_on_lan($relay_or_mac);
                    if ($success) {
                        usleep(1500000);
                        @exec("adb connect " . escapeshellarg($ip));
                        @exec("adb -s " . escapeshellarg($ip) . " shell input keyevent 224");
                        echo json_encode(['success' => true, 'new_state' => 'ON']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'WOL-Paket Android Fehler']);
                    }
                } else {
                    @exec("adb -s " . escapeshellarg($ip) . " shell input keyevent 223");
                    echo json_encode(['success' => true, 'new_state' => 'OFF']);
                }
                exit;
            }

            // --- CAS EXCLUSIF : OPENBEKEN (UNIQUEMENT SI L'IDENTIFIANT MQTT EST EXPLICITEMENT OBK) ---
            if (strpos($relay_or_mac, 'obk') === 0) {
                if ($target_state === 'OFF') {
                    @exec("mosquitto_pub $auth_part -t '{$relay_or_mac}/led_enableAll' -m '0' > /dev/null 2>&1");
                    @exec("mosquitto_pub $auth_part -t '{$relay_or_mac}/1/set' -m '0' > /dev/null 2>&1");
                } else {
                    @exec("mosquitto_pub $auth_part -t '{$relay_or_mac}/led_enableAll' -m '1' > /dev/null 2>&1");
                    @exec("mosquitto_pub $auth_part -t '{$relay_or_mac}/1/set' -m '100' > /dev/null 2>&1");
                }
                @exec("mosquitto_pub $auth_part -t '{$relay_or_mac}/0/set' -m '0' > /dev/null 2>&1");

                echo json_encode(['success' => true, 'new_state' => $target_state]);
                exit;
            }


            // --- CAS TRADITIONNEL : MODULES TASMOTA RELAIS ---
            $relay_num = intval($relay_or_mac);
            if ($relay_num < 1) {
                echo json_encode(['success' => false, 'message' => 'Ungültiges Relais']);
                exit;
            }

            $url = "http://{$ip}/cm?cmnd=Power{$relay_num}%20{$target_state}";
            $ctx = stream_context_create(['http' => ['timeout' => 2]]);
            $result = @file_get_contents($url, false, $ctx);

            if ($result !== false) {
                echo json_encode(['success' => true, 'new_state' => $target_state]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gerät nicht erreichbar']);
            }
            exit;
        }
    }
}

/**
 * Récupère le cache live de la RAM S.H.A.
 */
/**
 * Récupère le cache live de la RAM S.H.A. avec fusion des Dimmer de secours
 */
function get_sha_live_cache($cache_path = '/var/www/html/sha/data/sha_live.json') {
    $live_data = [];
    
    // 1. Lecture du cache Python standard
    if (file_exists($cache_path) && filesize($cache_path) > 0) {
        $live_data = json_decode(@file_get_contents($cache_path), true) ?? [];
    }
    
    // 2. 🐦‍🔥 FUSION DES ÉTATS PERSISTANTS DES DIMMERS A.S.H.E.S.
    $local_states_file = dirname($cache_path) . '/dimmer_states.json';
    if (file_exists($local_states_file)) {
        $local_states = json_decode(@file_get_contents($local_states_file), true) ?? [];
        foreach ($local_states as $device_ip => $brightness) {
            if (isset($live_data['devices'][$device_ip])) {
                // Si l'appareil existe déjà dans le cache live, on lui ajoute la clé brightness
                $live_data['devices'][$device_ip]['brightness'] = $brightness;
            } else {
                // S'il n'est pas encore apparu, on l'initialise proprement
                $live_data['devices'][$device_ip] = [
                    'mqtt_name' => '',
                    'power' => 0,
                    'brightness' => $brightness,
                    'last_seen' => time()
                ];
            }
        }
    }
    
    return $live_data;
}


    /**
     * Envoie un Magic Packet Wake-On-LAN en UDP Broadcast (Pure PHP)
     */
    function send_wake_on_lan($mac) {
    $mac = preg_replace('/[^0-9a-fA-F]/', '', $mac);
    if (strlen($mac) !== 12) return false;

    $hex_mac = pack('H*', $mac);
    $packet = str_repeat(chr(255), 6) . str_repeat($hex_mac, 16);

    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($sock) {
        // 🟢 ON ENVOIE EN UNICAST DIRECTEMENT AU PI (sur le port 9999)
        // Le NAT de Docker va laisser passer ce paquet sans broncher
        socket_sendto($sock, $packet, strlen($packet), 0, '192.168.0.10', 9999);
        socket_close($sock);
        return true;
    }
    return false;
}
