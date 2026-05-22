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

/**
 * Traite les actions interactives d'allumage/extinction via requête HTTP locale
 */
function handle_device_action() {
    if (isset($_POST['action']) && isset($_POST['ip']) && isset($_POST['relay'])) {
        header('Content-Type: application/json');
        
        $ip = filter_var($_POST['ip'], FILTER_VALIDATE_IP);
        $relay_num = intval($_POST['relay']);
        $target_state = ($_POST['action'] === 'ON') ? 'ON' : 'OFF';

        if (!$ip || $relay_num < 1) {
            echo json_encode(['success' => false, 'message' => 'Ungültige Parameter']);
            exit;
        }

        // Requête HTTP Tasmota multi-relais
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

/**
 * Récupère le cache live de la RAM S.H.A.
 */
function get_sha_live_cache($cache_path = 'data/sha_live.json') {
    if (file_exists($cache_path) && filesize($cache_path) > 0) {
        return json_decode(@file_get_contents($cache_path), true) ?? [];
    }
    return [];
}
?>