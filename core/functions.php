<?php
/**
 * SHA 2026 - Fonctions Core (RAM Powered)
 */

/**
 * Récupère la puissance d'un appareil directement depuis le cache RAM global
 */
function getSmartMeter($ip) {
    if (empty($ip)) return 0;

    $cache_file = '/dev/shm/sha_live.json';
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
     * Traite les actions interactives (Tasmota Relais ou Signal Wake-On-LAN / Graceful Windows Shutdown)
     */
    function handle_device_action() {
        if (isset($_POST['action']) && isset($_POST['ip'])) {
            header('Content-Type: application/json');

            $ip = filter_var($_POST['ip'], FILTER_VALIDATE_IP);
            $target_state = ($_POST['action'] === 'ON') ? 'ON' : 'OFF';
            $type = $_POST['type'] ?? 'socket';
            $relay_or_mac = $_POST['relay'] ?? '';

            if (!$ip) {
                echo json_encode(['success' => false, 'message' => 'Ungültige Parameter']);
                exit;
            }

            // --- CAS EXCLUSIF : MACHINE WINDOWS PC ---
            if ($type === 'pc') {
                if ($target_state === 'ON') {
                    // Envoi du signal de réveil physique (WOL)
                    $success = send_wake_on_lan($relay_or_mac);
                    if ($success) {
                        echo json_encode(['success' => true, 'new_state' => 'ON']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'WOL-Paket Fehler']);
                    }
                } else {
                    // 💡 LECTURE SÉCURISÉE DES IDENTIFIANTS DEPUIS APP.CONF
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

                    // On fusionne l'authentification dans la syntaxe Samba requise (user%password)
                    $user_auth = $win_user . '%' . $win_pass;

                    // Commande RPC Samba native (t 0 = immédiat / f = force la fermeture des apps)
                    $cmd = "sudo /usr/bin/net rpc shutdown -I " . escapeshellarg($ip) . " -U " . escapeshellarg($user_auth) . " -t 0 -f 2>&1";
                    @exec($cmd, $output, $return_var);

                    if ($return_var === 0) {
                        echo json_encode(['success' => true, 'new_state' => 'OFF']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'RPC Fehler: ' . implode(' ', $output)]);
                    }
                }
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
function get_sha_live_cache($cache_path = 'data/sha_live.json') {
    if (file_exists($cache_path) && filesize($cache_path) > 0) {
        return json_decode(@file_get_contents($cache_path), true) ?? [];
    }
    return [];
}

if (!function_exists('send_wake_on_lan')) {
    /**
     * Envoie un Magic Packet Wake-On-LAN en UDP Broadcast (Pure PHP, aucun paquet Linux requis)
     */
    function send_wake_on_lan($mac) {
        $mac = preg_replace('/[^0-9a-fA-F]/', '', $mac);
        if (strlen($mac) !== 12) return false;

        $hex_mac = pack('H*', $mac);
        $packet = str_repeat(chr(255), 6) . str_repeat($hex_mac, 16);

        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock) {
            socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
            socket_sendto($sock, $packet, strlen($packet), 0, '255.255.255.255', 9);
            socket_close($sock);
            return true;
        }
        return false;
    }
}