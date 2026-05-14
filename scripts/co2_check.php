<?php
/**
 * S.H.A. - CO2 Auto-Ventilation v1.2
 * Seuil ON : 1000 ppm | Seuil OFF : 800 ppm
 * Log circulaire : 15 lignes max (3 Mo -> 1 Ko !)
 */

$weather_file = '/var/www/html/sha/data/weather.json';
$log_file     = '/var/www/html/sha/logs/co2_history.log';
$fan_ip       = '192.168.0.32';
$relay        = '4';
$max_history  = 15;

// 1. Charger les données Netatmo (weather.json)
$json_raw = @file_get_contents($weather_file);
$data = json_decode($json_raw, true);

if (!$data) {
    exit("Erreur: Impossible de lire ou décoder le fichier météo.\n");
}

$co2 = null;
foreach ($data as $item) {
    // Correction : On utilise la clé 'name' pour identifier le module
    if (isset($item['name']) && $item['name'] == 'Keller') {
        $co2 = $item['co2'];
        break;
    }
}

if ($co2 === null) {
    exit("Erreur: Capteur CO2 'Keller' non trouvé dans le fichier JSON.\n");
}

// 2. Récupérer l'état actuel du ventilateur (Relay 4)
$status_raw = @file_get_contents("http://$fan_ip/cm?cmnd=Power$relay");
$status_json = json_decode($status_raw, true);
$current_state = $status_json["POWER$relay"] ?? 'UNKNOWN';

// 3. Logique de décision
$action_msg = "ℹ️ Rien à faire";

if ($co2 >= 1000) {
    if ($current_state !== 'ON') {
        $action_msg = "🚀 ALLUMAGE (Seuil > 1000)";
        @file_get_contents("http://$fan_ip/cm?cmnd=Power$relay%20ON");
        $current_state = 'ON';
    } else {
        $action_msg = "ℹ️ Déjà allumé";
    }
} 
elseif ($co2 <= 800) {
    if ($current_state !== 'OFF') {
        $action_msg = "🍃 ARRÊT (Seuil < 800)";
        @file_get_contents("http://$fan_ip/cm?cmnd=Power$relay%20OFF");
        $current_state = 'OFF';
    } else {
        $action_msg = "ℹ️ Déjà arrêté";
    }
}

echo "📊 CO2: {$co2}ppm | État: $current_state | $action_msg\n";

// --- CONFIGURATION AFFICHEUR SHA ---
$display_ip = '192.168.0.136'; // L'IP fixe choisie pour ton Wemos
$is_alert = ($co2 >= 1000); // Seuil d'alerte défini dans ton script

// Construction du message pour le MAX7219
// [f1] = police normale, [c1] = effacer l'écran
$text = $is_alert ? "!! " . $co2 . " !!" : $co2;
$cmd = urlencode("DisplayText [f1c1]" . $text);

// Envoi asynchrone (via curl ou file_get_contents)
$ctx = stream_context_create(['http' => ['timeout' => 1]]);
@file_get_contents("http://$display_ip/cm?cmnd=$cmd", false, $ctx);

// 4. Gestion du log circulaire (Auto-nettoyage)
$timestamp = date('Y-m-d H:i:s');
$new_entry = "[$timestamp] CO2: {$co2}ppm | État: $current_state | $action_msg\n";

// Lecture du log actuel
$log_lines = file_exists($log_file) ? file($log_file) : [];

// Ajout de la nouvelle ligne
$log_lines[] = $new_entry;

// On ne garde que les 15 dernières lignes
if (count($log_lines) > $max_history) {
    $log_lines = array_slice($log_lines, -$max_history);
}

// Réécriture complète du fichier (écrase le contenu précédent)
file_put_contents($log_file, implode("", $log_lines));
