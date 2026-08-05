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

/* =====================================================================
   ♨️ SOUS-SYSTÈME THERMIQUE A.S.H.E.S. (DOCKER QUEUE ENGINE)
   ===================================================================== */

/**
 * Intercepte les clics et dépose un fichier d'ordre pour le Worker Docker
 */
/**
 * Intercepte les clics AJAX et dépose un fichier d'ordre pour le Worker Docker
 */
function handle_heating_action() {
    $cmd_file = '/var/www/html/sha/data/heiz_cmd.json';

    // Interception des requêtes asynchrones AJAX
    if (isset($_GET['ajax_cmd'])) {
        $cmd = [
            'fct'       => trim($_GET['fct']),
            'id'        => trim($_GET['id']),
            'temp'      => isset($_GET['temp']) ? trim($_GET['temp']) : 'BOOST',
            'timestamp' => time()
        ];
        @file_put_contents($cmd_file, json_encode($cmd));
        
        // 🟩 ZONE MODIFIÉE : On jette le HTML généré par header.php pour ne pas polluer le JSON
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Ordre transmis à la file Docker']);
        exit;
    }

    // Ordre de Hard Scan global
    if (isset($_GET['scan'])) {
        $cmd = [
            'fct'       => 'scan',
            'timestamp' => time()
        ];
        @file_put_contents($cmd_file, json_encode($cmd));
        header("Location: heiz.php?update=1");
        exit;
    }
}

/**
 * Lit le cache JSON unifié généré en tâche de fond par le conteneur Node
 */
function get_maxcube_live_data($json_path = '/var/www/html/sha/data/heiz_out.json') {
    $fallback = [
        'system'  => ['duty_val' => 0, 'slots_val' => 50, 'duty_color' => 'var(--green)', 'next_fmt' => 'MAJ...'],
        'devices' => []
    ];

    if (!file_exists($json_path)) {
        return $fallback;
    }

    $data = json_decode(@file_get_contents($json_path), true);
    if (!$data) return $fallback;

    // Calcul du rafraîchissement restant basé sur l'âge réel du fichier JSON
    $next_up = (filemtime($json_path) + 300) - time();
    $data['system']['next_fmt'] = ($next_up > 0) ? sprintf("%02dm %02ds", floor($next_up/60), $next_up%60) : "MAJ...";

    return $data;
}

// core/functions.php : Logique de mise à jour basée sur un fichier (ex: JSON)
function update_task_modification_date($task_id, $custom_date, $storage_file = 'data/tasks.json') {
    if (!file_exists($storage_file)) {
        return false;
    }

    $file_content = file_get_contents($storage_file);
    $tasks = json_decode($file_content, true) ?? [];

    // Recherche et mise à jour de la tâche par ID (ou index selon ta structure)
    foreach ($tasks as &$task) {
        if ($task['id'] === $task_id) {
            // Convertit le format HTML5 (Y-m-d\TH:i) en format standard (Y-m-d H:i:s)
            $task['last_modified_date'] = date('Y-m-d H:i:s', strtotime($custom_date));
            break;
        }
    }

    // Écriture synchrone pour éviter la perte de données (verrouillage optionnel si accès concurrents)
    return file_put_contents($storage_file, json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}
// 1. core/functions.php : Migration de la logique métier et du CRUD
function handle_task_ajax_request($tasks_file, $post_data) {
    header('Content-Type: application/json');
    $tasks = file_exists($tasks_file) ? json_decode(file_get_contents($tasks_file), true) : [];
    
    $action = $post_data['action'];
    $room = $post_data['room'] ?? '';
    $id = $post_data['id'] ?? uniqid();

    if ($action === 'add' || ($action === 'edit' && isset($tasks[$room][$id]))) {
        $raw_freq = $post_data['freq'] ?? 1;
        $f_val = isset($post_data['freq_value']) ? max(1, (int)$post_data['freq_value']) : max(1, (int)$raw_freq);
        $f_unit = in_array($post_data['freq_unit'] ?? 'd', ['d', 'w', 'm']) ? $post_data['freq_unit'] : 'd';
        
        $f_days = $f_val;
        if ($f_unit === 'w') $f_days *= 7;
        if ($f_unit === 'm') $f_days *= 30;

        $last_done_ts = !empty($post_data['last_done']) ? strtotime($post_data['last_done']) : time();

        if ($action === 'add') {
            $tasks[$room][$id] = [
                'label' => trim($post_data['label']),
                'effort' => max(1, min(5, (int)$post_data['effort'])),
                'freq_value' => $f_val,
                'freq_unit' => $f_unit,
                'freq' => $f_days,
                'comment' => trim($post_data['comment'] ?? ''),
                'last_done' => $last_done_ts
            ];
        } else {
            if (isset($post_data['label'])) $tasks[$room][$id]['label'] = trim($post_data['label']);
            if (isset($post_data['effort'])) $tasks[$room][$id]['effort'] = max(1, min(5, (int)$post_data['effort']));
            if (isset($post_data['comment'])) $tasks[$room][$id]['comment'] = trim($post_data['comment']);
            $tasks[$room][$id]['freq_value'] = $f_val;
            $tasks[$room][$id]['freq_unit'] = $f_unit;
            $tasks[$room][$id]['freq'] = $f_days;
            $tasks[$room][$id]['last_done'] = $last_done_ts;
        }
    } elseif ($action === 'done' && isset($tasks[$room][$id])) {
        $tasks[$room][$id]['last_done'] = time();
    } elseif ($action === 'delete') {
        unset($tasks[$room][$id]);
    }

    file_put_contents($tasks_file, json_encode($tasks, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true]);
    exit;
}

function calculate_tasks_scores($rooms, $tasks_file) {
    $tasks_data = file_exists($tasks_file) ? json_decode(file_get_contents($tasks_file), true) : [];
    $global_score_accum = 0;
    $global_effort_accum = 0;
    $room_stats = [];

    foreach ($rooms as $name => $data) {
        if (in_array($name, ['System', 'Defaults'])) continue;
        
        $room_tasks = $tasks_data[$name] ?? [];
        $r_score_accum = 0;
        $r_effort_accum = 0;

        foreach ($room_tasks as $tid => $t) {
            $days_elapsed = (time() - $t['last_done']) / 86400;
            $ratio = min(1, max(0, $days_elapsed / $t['freq']));
            $task_score = 100 * (1 - $ratio);

            $r_score_accum += $task_score * $t['effort'];
            $r_effort_accum += $t['effort'];
            
            $global_score_accum += $task_score * $t['effort'];
            $global_effort_accum += $t['effort'];
        }
        
        $room_stats[$name] = [
            'score' => $r_effort_accum > 0 ? round($r_score_accum / $r_effort_accum) : 100,
            'tasks' => $room_tasks
        ];
    }

    $gesamt_sauberkeit = $global_effort_accum > 0 ? round($global_score_accum / $global_effort_accum) : 100;
    $color_gesamt = $gesamt_sauberkeit < 50 ? 'var(--red)' : ($gesamt_sauberkeit < 80 ? 'var(--orange)' : 'var(--green)');

    return [
        'room_stats' => $room_stats,
        'gesamt_sauberkeit' => $gesamt_sauberkeit,
        'color_gesamt' => $color_gesamt
    ];
}