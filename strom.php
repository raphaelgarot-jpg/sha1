<?php
include("header.php");

// 1. Chargement de la configuration
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


// --- RECHERCHE DES ÉTATS ÉLECTROMÉNAGERS ---
$machine_badges = ['192.168.0.54' => '', '192.168.0.49' => ''];
$monitor_files = [
    '192.168.0.54' => '/dev/shm/sha_monitor_state_wm.json',
    '192.168.0.49' => '/dev/shm/sha_monitor_state_gs.json'
];

foreach ($monitor_files as $ip => $file) {
    if (file_exists($file)) {
        $monitor_data = json_decode(@file_get_contents($file), true);
        if (is_array($monitor_data)) {
            $state = $monitor_data['state'] ?? 'IDLE';
            if ($state === 'RUNNING') {
                $machine_badges[$ip] = " <span class='badge badge-blue' style='font-size:0.55rem; padding: 2px 5px; margin-left: 5px;'>🔄 LÄUFT</span>";
            } elseif ($state === 'FINISHED') {
                $time_left = $monitor_data['time_left_display'] ?? 0;
                if ($time_left > 0) {
                    $countdown_str = sprintf("%02dm", ceil($time_left / 60));
                    $machine_badges[$ip] = " <span class='badge' style='background: #27ae60; color: #fff; font-size:0.55rem; padding: 2px 5px; margin-left: 5px;'>✨ FERTIG ({$countdown_str})</span>";
                }
            }
        }
    }
}


// 3. Lecture des compteurs principaux (Shelly 3EM / Pro 3EM)
$v_haus = $tas_cache[$sys['ip_verteiler_haus']]['power'] ?? 0;
$v_bbh  = $tas_cache[$sys['ip_verteiler_bbh']]['power'] ?? 0;

// 4. Solaire (Lecture directe depuis le cache global)
$solar_watt = $tas_cache[$sys['ip_solar_tasmota']]['power'] ?? 0;


// 💡 4.5 PASSE DE PRÉ-CALCUL : Extraction des puissances temps réel pour soustractions croisées
$p_pc_famille = 0;
$p_bildschirm_raf = 0;

foreach ($rooms as $r_name => $r_data) {
    if (in_array($r_name, ['System', 'Defaults'])) continue;
    if (!empty($r_data['devices']) && is_array($r_data['devices'])) {
        foreach ($r_data['devices'] as $dev) {
            $parts = explode('|', $dev);
            if (count($parts) < 4) continue;
            list($type, $ip, $relay, $label) = $parts;
            
            // On extrait la puissance brute pour analyse
            $dev_power = 0;
            $dev_data = $tas_cache[$ip] ?? null;
            if ($dev_data !== null && abs(time() - ($dev_data['last_seen'] ?? 0)) < 600) {
                if (isset($dev_data['channel_states']) && isset($dev_data['channels'][$relay])) {
                    $dev_power = $dev_data['channels'][$relay];
                } else {
                    $dev_power = $dev_data['power'] ?? 0.0;
                }
            }

            // Capture dynamique pour la soustraction Famille
            if (strpos(strtolower($label), 'pc famille') !== false) {
                $p_pc_famille = $dev_power;
            }
            // Capture dynamique pour la soustraction Raf
            if (strpos(strtolower($label), 'bildschirm raf') !== false) {
                $p_bildschirm_raf = $dev_power;
            }
        }
    }
}


// 5. Initialisation pour le calcul global
$sum_rooms_consumption = 0;
$rendered_cards_html = ""; 

foreach ($rooms as $name => $data) {
    if (in_array($name, ['System', 'Defaults'])) continue;

    $dev_list = [];
    $room_total = 0;

    if (!empty($data['devices']) && is_array($data['devices'])) {
        foreach ($data['devices'] as $dev) {
            $parts = explode('|', $dev);
            if (count($parts) < 4) continue;
            list($type, $ip, $relay, $label) = $parts;

            if ($type == 'socket' || $type == 'light_p') {
                $dev_data = $tas_cache[$ip] ?? null;
                $p = 0;
                $is_offline = true;

                if ($dev_data !== null && abs(time() - ($dev_data['last_seen'] ?? 0)) < 600) {
                    $is_offline = false;
                    
                    if (isset($dev_data['channel_states']) && isset($dev_data['channels'][$relay])) {
                        $p = $dev_data['channels'][$relay];
                    } else {
                        $p = $dev_data['power'] ?? 0.0;
                    }

                    // 💡 SOUSTRACTION 1 : Le PC Famille est déduit du Netzwerkschrank
                    if (strpos(strtolower($label), 'netzwerkschrank') !== false) {
                        $p = max(0, $p - $p_pc_famille);
                    }

                    // 💡 SOUSTRACTION 2 : Le Bildschirm Raf est déduit du PC Raf
                    if (strpos(strtolower($label), 'pc raf') !== false) {
                        $p = max(0, $p - $p_bildschirm_raf);
                    }
                }

                if (!$is_offline) {
                    $room_total += $p;
                    $sum_rooms_consumption += $p;
                }

                $icon = $parts[4] ?? ($defaults[$type] ?? '🔌');
                
                $display_label = $label;
                if (array_key_exists($ip, $machine_badges)) {
                    $display_label .= $machine_badges[$ip];
                }
                if ($is_offline) {
                    $display_label .= " <small style='color: var(--red); font-size: 0.55rem;'>⚠️ Offline</small>";
                }

                $dev_list[] = [
                    'label' => $display_label,
                    'power' => $p,
                    'icon' => $icon,
                    'offline' => $is_offline
                ];
            }
        }
    }

    if (empty($dev_list)) continue;

    $is_arbeitszimmer = ($name == "Arbeitszimmer");
    $grid_span = $is_arbeitszimmer ? "grid-column: span 2;" : "";
    $body_class = $is_arbeitszimmer ? "grid-2-columns" : "flex-column";

    $rendered_cards_html .= '<div class="room-card" style="' . $grid_span . '">';
    $rendered_cards_html .= '  <div class="room-head">';
    $rendered_cards_html .= '      <div class="room-title"><span>' . ($data['icon'] ?? '🏠') . '</span> ' . strtoupper($name) . '</div>';
    $rendered_cards_html .= '      <span class="badge badge-blue">⚡ ' . round($room_total) . ' W</span>';
    $rendered_cards_html .= '  </div>';
    $rendered_cards_html .= '  <div class="room-body ' . $body_class . '">';

    foreach ($dev_list as $d) {
        $color_style = ($d['offline']) ? 'color: var(--red);' : '';
        $power_display = ($d['offline']) ? '---' : round($d['power']);

        $rendered_cards_html .= '      <div class="dev-row">';
        $rendered_cards_html .= '          <span class="dev-name"><span>' . $d['icon'] . '</span> ' . $d['label'] . '</span>';
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
?>

<div class="container">

    <div class="room-card" style="margin-bottom: 25px; border: 1px solid #ff980066; border-top: 3px solid #ff9800; padding: 20px; display: flex; flex-direction: column; gap: 15px;">
        <div class="room-title" style="justify-content: center; color: #ff9800;"><span>⚡</span> GESAMTVERBRAUCH</div>
        <div style="font-size: 2.8rem; font-weight: 900; line-height: 1; align-items: center; text-align: center;">
            <?= round($gesamt_conso) ?> <span style="font-size: 1rem; color: #444;">Watt</span>
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

    <div class="sha-counters-grid">
        <div class="room-card" style="padding: 15px; text-align: center; border: 1px solid #ff980066;">
            <div style="font-size: 0.55rem; font-weight: 900; color: #555;">VERTEILER HAUS</div>
            <div style="font-size: 1.4rem; font-weight: 900; color: var(--accent);"><?= round($v_haus) ?> W</div>
        </div>
        <div class="room-card" style="padding: 15px; text-align: center; border: 1px solid #ff980066;">
            <div style="font-size: 0.55rem; font-weight: 900; color: #555;">SOLAR</div>
            <div style="font-size: 1.4rem; font-weight: 900; color: var(--solar);"><?= round($solar_watt) ?> W</div>
        </div>
        <div class="room-card" style="padding: 15px; text-align: center; border: 1px solid #ff980066;">
            <div style="font-size: 0.55rem; font-weight: 900; color: #555;">VERTEILER BBH</div>
            <div style="font-size: 1.4rem; font-weight: 900; color: var(--green);"><?= round($v_bbh) ?> W</div>
        </div>
    </div>

    <div class="sha-main-grid">
        <?= $rendered_cards_html ?>
    </div>
</div>

<?php include("footer.php"); ?>