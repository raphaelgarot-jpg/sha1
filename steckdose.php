<?php
include("header.php");

// 1. Traitement de l'action interactive (déporté dans le core)
handle_device_action();

// 2. Chargement des structures et du cache
$rooms = parse_ini_file('config/home_structure.conf', true);
$defaults = $rooms['Defaults'] ?? [];
$live_data = get_sha_live_cache();
$tas_cache = $live_data['devices'] ?? [];

// 3. Génération du HTML
$rendered_cards_html = "";

foreach ($rooms as $name => $data) {
    if ($name == 'System' || $name == 'Defaults') continue;

    $dev_list = [];
    $room_active_count = 0;

    if (!empty($data['devices'])) {
        foreach ($data['devices'] as $dev) {
            $parts = explode('|', $dev);
            if (count($parts) < 4) continue;
            list($type, $ip, $relay, $label) = $parts;

            if ($type == 'socket' || $type == 'light' || $type == 'light_p') {
                if (isset($parts[5]) && trim($parts[5]) === 'no_relay') continue;

                $dev_data = $tas_cache[$ip] ?? null;
                $is_offline = true;
                $current_state = "OFF";

                if ($dev_data !== null && is_array($dev_data)) {
                    $last_seen = $dev_data['last_seen'] ?? 0;
                    if (abs(time() - $last_seen) < 600) {
                        $is_offline = false;
                        $current_state = isset($dev_data['channels'][$relay]) 
                            ? (($dev_data['channels'][$relay] > 0) ? "ON" : "OFF") 
                            : ($dev_data['state'] ?? "OFF");
                    }
                }

                if (!$is_offline && $current_state === "ON") $room_active_count++;

                $default_type_key = ($type == 'light_p') ? 'light' : $type;
                $icon = $parts[4] ?? ($defaults[$default_type_key] ?? '💡');

                $dev_list[] = [
                    'label' => $label, 'ip' => $ip, 'relay' => $relay,
                    'icon' => $icon, 'state' => $current_state, 'offline' => $is_offline
                ];
            }
        }
    }

    if (empty($dev_list)) continue;

    // Configuration des conteneurs de grille
    $is_arbeitszimmer = ($name == "Arbeitszimmer");
    $grid_span = $is_arbeitszimmer ? "grid-column: span 2;" : "";
    $body_class = $is_arbeitszimmer ? "grid-2-columns" : "flex-column"; 
    // Note: Définis .grid-2-columns et .flex-column dans style.css si tu veux aussi sortir ces inline styles !

    $rendered_cards_html .= '<div class="room-card" style="' . $grid_span . '">';
    $rendered_cards_html .= '  <div class="room-head">';
    $rendered_cards_html .= '      <div class="room-title"><span>' . ($data['icon'] ?? '🏠') . '</span> ' . strtoupper($name) . '</div>';
    $rendered_cards_html .= '      <span class="badge badge-blue">🔌 ' . $room_active_count . ' Active(s)</span>';
    $rendered_cards_html .= '  </div>';
    $rendered_cards_html .= '  <div class="room-body ' . $body_class . '" style="padding: 15px 20px;">';

    foreach ($dev_list as $d) {
        $is_on = ($d['state'] === 'ON');
        
        if ($d['offline']) {
            $row_class = "dev-row offline";
        } else {
            $row_class = $is_on ? "dev-row state-on" : "dev-row state-off";
        }

        $rendered_cards_html .= '      <div class="' . $row_class . '">';
        $rendered_cards_html .= '          <span class="dev-name">';
        $rendered_cards_html .= '              <span>' . $d['icon'] . ' ' . $d['label'] . '</span>';
        
        if ($d['offline']) {
            $rendered_cards_html .= '          <span class="status-text offline">⚠️ Offline</span>';
        } else {
            $rendered_cards_html .= '          <span class="status-text ' . ($is_on ? 'on' : 'off') . '">' . ($is_on ? '🟢 ON' : '⚫ OFF') . '</span>';
        }
        $rendered_cards_html .= '          </span>';

        $btn_class = $is_on ? 'btn-on' : 'btn-off';
        $btn_text = $is_on ? 'OFF' : 'ON';

        $rendered_cards_html .= '          <button class="toggle-btn ' . $btn_class . '" data-ip="' . $d['ip'] . '" data-relay="' . $d['relay'] . '" data-state="' . $d['state'] . '" data-label="' . htmlspecialchars($d['label'], ENT_QUOTES) . '">' . $btn_text . '</button>';
        $rendered_cards_html .= '      </div>';
    }

    $rendered_cards_html .= '  </div>';
    $rendered_cards_html .= '</div>';
}
?>

<div class="container">
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px;">
        <div class="room-card" style="padding: 15px; text-align: center; border: 1px solid #ff980066; border-top: 3px solid #ff9800; cursor: pointer;">
            <div style="font-size: 0.8rem; font-weight: 900; color: #ff9800; text-transform: uppercase; margin-bottom: 5px;">🎮 GAMING</div>
            <div style="font-size: 0.7rem; color: #777;">Gruppe aktivieren / deaktivieren</div>
        </div>
        <div class="room-card" style="padding: 15px; text-align: center; border: 1px solid #ff980066; border-top: 3px solid #ff9800; cursor: pointer;">
            <div style="font-size: 0.8rem; font-weight: 900; color: #ff9800; text-transform: uppercase; margin-bottom: 5px;">⚙️ OPTION 2</div>
            <div style="font-size: 0.7rem; color: #777;">Mehrfachaktion 2</div>
        </div>
        <div class="room-card" style="padding: 15px; text-align: center; border: 1px solid #ff980066; border-top: 3px solid #ff9800; cursor: pointer;">
            <div style="font-size: 0.8rem; font-weight: 900; color: #ff9800; text-transform: uppercase; margin-bottom: 5px;">💡 OPTION 3</div>
            <div style="font-size: 0.7rem; color: #777;">Mehrfachaktion 3</div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px;">
        <?= $rendered_cards_html ?>
    </div>
</div>

<?php include("footer.php"); ?>