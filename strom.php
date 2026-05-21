<?php
include("header.php");

// 1. Chargement config
$rooms = parse_ini_file('config/home_structure.conf', true);
$sys = $rooms['System'];
$defaults = $rooms['Defaults'] ?? [];

// 2. LECTURE DU CACHE RAM (Ultra-rapide avec détection d'erreur)
$cache_file = 'data/sha_live.json';
$tas_cache = [];

if (!file_exists($cache_file) || filesize($cache_file) === 0) {
    die("<div class='container'><div class='room-card' style='border: 2px solid var(--red); padding: 20px; text-align: center;'>
            <h3 style='color: var(--red); margin-bottom: 10px;'>⚠️ Erreur Critique : S.H.A. Cache hors ligne</h3>
            <p style='font-size: 0.85rem; color: #aaa;'>Le fichier de cache en RAM est vide ou introuvable. Le service <code>sha-worker.service</code> est probablement arrêté.</p>
         </div></div>");
}

$json_content = @file_get_contents($cache_file);
$live_data = json_decode($json_content, true);

if (!is_array($live_data) || !isset($live_data['devices']) || empty($live_data['devices'])) {
    die("<div class='container'><div class='room-card' style='border: 2px solid var(--red); padding: 20px; text-align: center;'>
            <h3 style='color: var(--red); margin-bottom: 10px;'>⚠️ Erreur : Données de cache MQTT invalides</h3>
            <p style='font-size: 0.85rem; color: #aaa;'>Le fichier de cache existe mais ne contient encore aucune mesure d'appareil valide.</p>
         </div></div>");
}

$tas_cache = $live_data['devices'];


 // --- RECHERCHE DE L'ÉTAT DE LA MACHINE À LAVER ---
 $monitor_file = '/dev/shm/sha_monitor_state.json';
 $machine_badge = "";
 
 if (file_exists($monitor_file)) {
     $monitor_data = json_decode(@file_get_contents($monitor_file), true);
     if (is_array($monitor_data)) {
         $state = $monitor_data['state'] ?? 'IDLE';
         $updated_at = $monitor_data['updated_at'] ?? time();
         
         if ($state === 'RUNNING') {
             $machine_badge = " <span class='badge badge-blue' style='font-size:0.55rem; padding: 2px 5px; margin-left: 5px;'>🔄 LÄUFT</span>";
         } elseif ($state === 'FINISHED') {
             $end_time = $monitor_data['end_time'] ?? $updated_at;
             $time_remaining = (30 * 60) - (time() - $end_time);
             
            if ($time_remaining > 0) {
                 $minutes = floor($time_remaining / 60);
                 $countdown_str = sprintf("%02dm", $minutes);
                 // Utilisation de la couleur verte via style inline pour respecter style.css
                 $machine_badge = " <span class='badge' style='background: #27ae60; color: #fff; font-size:0.55rem; padding: 2px 5px; margin-left: 5px;'>✨ FERTIG ({$countdown_str})</span>";
             }
         }
     }
 }
 // --------------------------------------------------


// 3. Lecture des compteurs principaux (Verteiler)
$v_haus = $tas_cache[$sys['ip_verteiler_haus']]['power'] ?? 0; 
$v_bbh  = $tas_cache[$sys['ip_verteiler_bbh']]['power'] ?? 0;

// 4. Lecture et ajustement des données DRS (RAM)
$drs_file = $sys['drs_data_file'];
$drs_data = [];
$drs_warning = false;

if (file_exists($drs_file)) {
    $drs_data = json_decode(@file_get_contents($drs_file), true);
    
    // --- LOGIQUE DE SOUSTRACTION ---
    $p_bildschirm = $tas_cache['192.168.0.64']['power'] ?? 0;

    if (isset($drs_data['PC Raf + TV'])) {
        $drs_data['PC Raf + TV'] = max(0, $drs_data['PC Raf + TV'] - $p_bildschirm);
    }
    // -------------------------------

    if ((time() - filemtime($drs_file)) > 7200) { $drs_warning = true; }
} else {
    $drs_warning = true;
}

// 4. Solaire (Lecture dans le json global avec le format objet)
$solar_watt = $tas_cache[$sys['ip_solar_tasmota']]['power'] ?? 0;

// 5. Initialisation pour le calcul global
$sum_rooms_consumption = 0;
$rendered_cards_html = ""; // Stockage temporaire du HTML des cartes

foreach ($rooms as $name => $data) { 
    if ($name == 'System') continue;

    $dev_list = [];
    $room_total = 0;
    
    // A. Collecte Tasmota avec détection d'appareil mort
    if (!empty($data['devices'])) {
        foreach ($data['devices'] as $dev) {
            $parts = explode('|', $dev);
            if (count($parts) < 4) continue;
            list($type, $ip, $relay, $label) = $parts;

            if ($type == 'socket' || $type == 'light_p') {
                $dev_data = $tas_cache[$ip] ?? null;
                $p = 0;
                $is_offline = true;

                if ($dev_data !== null && is_array($dev_data)) {
                    $p = $dev_data['power'] ?? 0;
                    $last_seen = $dev_data['last_seen'] ?? 0;
                    
                    if (abs(time() - $last_seen) < 600) {
                        $is_offline = false;
                    }
                }

                if (!$is_offline) {
                    $room_total += $p;
                    $sum_rooms_consumption += $p;
                }

                $icon = $parts[4] ?? $defaults[$type];
                $display_label = $label;
                // IP de ta machine à laver 
                 if ($ip === '192.168.0.54') {
                     $display_label .= $machine_badge;
                 }
                if ($is_offline) {
                    $display_label .= " <small style='color: var(--red); font-size: 0.55rem;'>⚠️ Offline</small>";
                }

                $dev_list[] = [
                    'label' => $display_label, 
                    'power' => $p, 
                    'source' => 'tas', 
                    'icon' => $icon,
                    'offline' => $is_offline
                ];
            }
        }
    }
    
    // B. Collecte DRS
    if (!empty($data['drs_keys'])) {
        foreach ($data['drs_keys'] as $drs_entry) {
            $parts_drs = explode('|', $drs_entry);
            if (count($parts_drs) < 2) continue;
            list($key, $label) = $parts_drs;
            $p = $drs_data[$key] ?? 0;
            
            $room_total += $p;
            $sum_rooms_consumption += $p;
            $icon = $parts_drs[2] ?? $defaults['drs']; 
            
            $dev_list[] = [
                'label' => $label, 
                'power' => $p, 
                'source' => 'drs', 
                'icon' => $icon, 
                'offline' => false
            ];
        }
    }

    if (empty($dev_list)) continue;

    // Génération dynamique du HTML pour chaque pièce
    $card_style = ($name == "Arbeitszimmer" && $drs_warning) ? "border: 2px solid var(--red); box-shadow: 0 0 15px rgba(231, 76, 60, 0.4);" : "";
    
    $rendered_cards_html .= '<div class="room-card" style="' . $card_style . '">';
    $rendered_cards_html .= '  <div class="room-head">';
    $rendered_cards_html .= '      <div class="room-title"><span>' . $data['icon'] . '</span> ' . strtoupper($name);
    if ($name == "Arbeitszimmer" && $drs_warning) {
        $rendered_cards_html .= ' <small style="color: var(--red); font-size: 0.5rem; margin-left: 10px;">⚠️ RPIA Offline</small>';
    }
    $rendered_cards_html .= '      </div>';
    $rendered_cards_html .= '      <span class="badge badge-blue">⚡ ' . round($room_total) . ' W</span>';
    $rendered_cards_html .= '  </div>';
    $rendered_cards_html .= '  <div class="room-body" style="padding: 10px 20px;">';
    
    foreach ($dev_list as $d) {
        $color_style = (($d['source'] == 'drs' && $drs_warning) || ($d['offline'] ?? false)) ? 'color: var(--red);' : '';
        $power_display = ($d['offline'] ?? false) ? '---' : round($d['power']);
        
        $rendered_cards_html .= '      <div class="dev-row" style="display: flex; justify-content: space-between; padding: 5px 0;">';
        $rendered_cards_html .= '          <span class="dev-name" style="font-size: 0.85rem; color: #ccc;"><span style="margin-right: 8px;">' . $d['icon'] . '</span>' . $d['label'] . '</span>';
        $rendered_cards_html .= '          <span style="font-weight: 900; color: #eee; ' . $color_style . '">' . $power_display . ' <small style="color:#444; font-size: 0.6rem;">W</small></span>';
        $rendered_cards_html .= '      </div>';
    }
    
    $rendered_cards_html .= '  </div>';
    $rendered_cards_html .= '</div>';
}

// 6. Calculs Globaux
$gesamt_conso = $v_haus + $solar_watt;
$abdeckung = ($gesamt_conso > 0) ? min(round(($sum_rooms_consumption / $gesamt_conso) * 100), 100) : 0;
$autarkie = ($v_haus > 0) ? min(round(($solar_watt / $gesamt_conso) * 100), 100) : (($solar_watt > 0) ? 100 : 0);

$netzbezug = max(0, $gesamt_conso - $solar_watt);
$netzeinspeisung = max(0, $solar_watt - $gesamt_conso);
?>

<div class="container">

    
    <div class="room-card" style="margin-bottom: 25px; border: 1px solid #ff980066; padding: 20px; display: flex; flex-direction: column; gap: 15px;">
        <div class="room-title" style="justify-content: center; color: #ff9800;"><span>⚡</span> GESAMTVERBRAUCH</div>
        <div style="font-size: 2.8rem; font-weight: 900; line-height: 1;align-items: center; text-align: center;">
            <?= $gesamt_conso ?> <span style="font-size: 1rem; color: #444;">Watt</span>
        </div>
        <div style="margin-bottom: 15px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                <div style="font-size: 0.55rem; font-weight: 900; color: #555; text-transform: uppercase;">Abdeckung (Summe Räume)</div>
                <div style="font-size: 0.8rem; font-weight: 900; color: var(--green);"><?= $abdeckung ?>%</div>
            </div>
            <div style="height: 8px; background: #111; border-radius: 4px; overflow: hidden; border: 1px solid #222;">
                <div style="width: <?= $abdeckung ?>%; background: var(--green); height:100%; transition: width 0.5s;"></div>
            </div>
        </div>

        <div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                <div style="font-size: 0.55rem; font-weight: 900; color: #555; text-transform: uppercase;">Autarkie (Solar)</div>
                <div style="font-size: 0.8rem; font-weight: 900; color: var(--solar);"><?= $autarkie ?>%</div>
            </div>
            <div style="height: 8px; background: #111; border-radius: 4px; overflow: hidden; border: 1px solid #222;">
                <div style="width: <?= $autarkie ?>%; background: var(--solar); height:100%; transition: width 0.5s;"></div>
            </div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px;">
        <div class="room-card" style="padding: 15px; text-align: center;">
            <div style="font-size: 0.5rem; font-weight: 900; color: #555;">VERTEILER HAUS</div>
            <div style="font-size: 1.4rem; font-weight: 900; color: var(--accent);"><?= $v_haus ?> W</div>
        </div>
        <div class="room-card" style="padding: 15px; text-align: center;">
            <div style="font-size: 0.5rem; font-weight: 900; color: #555;">SOLAR</div>
            <div style="font-size: 1.4rem; font-weight: 900; color: var(--solar);"><?= $solar_watt ?> W</div>
        </div>
        <div class="room-card" style="padding: 15px; text-align: center;">
            <div style="font-size: 0.5rem; font-weight: 900; color: #555;">VERTEILER BBH</div>
            <div style="font-size: 1.4rem; font-weight: 900; color: var(--green);"><?= $v_bbh ?> W</div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px;">
        <?= $rendered_cards_html ?>
    </div>
</div>

<?php include("footer.php"); ?>