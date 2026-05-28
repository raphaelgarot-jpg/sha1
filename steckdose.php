<?php
include("header.php");
require_once("core/functions.php");

handle_device_action();

$rooms = parse_ini_file('config/home_structure.conf', true);
$defaults = $rooms['Defaults'] ?? [];
$live_data = get_sha_live_cache();
$tas_cache = $live_data['devices'] ?? [];
$rendered_cards_html = "";
$mqtt_name = (isset($parts[5]) && trim($parts[5]) !== 'no_relay' && trim($parts[5]) !== 'dimmable') ? trim($parts[5]) : "";

if (is_array($rooms)) {
    foreach ($rooms as $name => $data) {
        if ($name == 'System' || $name == 'Defaults') continue;
        $dev_list = []; 
        $room_active_count = 0;

        $all_raw_devices = array_merge($data['devices'] ?? [], $data['pcs'] ?? []);

        foreach ($all_raw_devices as $dev) {
            $parts = explode('|', $dev);
            if (count($parts) < 4) continue;
            list($type, $ip, $relay_or_mac, $label) = $parts;

            // Sécurité : On ignore l'appareil s'il est explicitement marqué sans relais
            if (isset($parts[5]) && trim($parts[5]) === 'no_relay') {
                continue;
            }

            if (in_array($type, ['socket', 'light', 'light_p', 'pc', 'android'])) {
                $dev_data = $tas_cache[$ip] ?? null;
                $is_offline = true; 
                $current_state = "OFF";
                
                // Détection de l'option Dimmable
                $is_dimmable = false;
                foreach($parts as $part) {
                    if(trim(strtolower($part)) === 'dimmable') {
                        $is_dimmable = true;
                    }
                }

                if ($dev_data !== null) {
                    if ($type === 'pc' || $type === 'android') {
                        $is_offline = false;
                        $current_state = strtoupper($dev_data['state'] ?? "OFF");
                    } else {
                        if (abs(time() - ($dev_data['last_seen'] ?? 0)) < 600) {
                            $is_offline = false;

                            // Lecture de l'état réel du relais (même à 0 Watt)
                            if (isset($dev_data['channel_states'][$relay_or_mac])) {
                                $current_state = strtoupper($dev_data['channel_states'][$relay_or_mac]);
                            } else {
                                $current_state = ($dev_data['power'] ?? 0) > 1.0 ? "ON" : "OFF";
                            }
                        }
                    }
                }

                if (!$is_offline && $current_state === "ON") $room_active_count++;
                $icon = $parts[4] ?? ($defaults[$type === 'light_p' ? 'light' : $type] ?? '💡');

                // Lecture de la vraie valeur de gradation
                $current_dimmer = isset($dev_data['dimmer']) ? intval($dev_data['dimmer']) : 100;

                $dev_list[] = [
                    'type' => $type, 'label' => $label, 'ip' => $ip, 'relay' => $relay_or_mac,
                    'icon' => $icon, 'state' => $current_state, 'offline' => $is_offline,
                    'dimmable' => $is_dimmable, 'dimmer' => $current_dimmer,
                    'mqtt_name' => $mqtt_name
                ];
            }
        }

        if (empty($dev_list)) continue;
        $is_az = ($name == "Arbeitszimmer");
        $card_class = $is_az ? "room-card room-card-wide" : "room-card";
        $body_class = $is_az ? "room-body grid-2-columns" : "room-body flex-column";

        $rendered_cards_html .= '<div class="' . $card_class . '">';
        $rendered_cards_html .= '  <div class="room-head"><div class="room-title"><span>' . ($data['icon'] ?? '🏠') . '</span> ' . strtoupper($name) . '</div><span class="badge badge-blue">🔌 ' . $room_active_count . ' aktiv</span></div>';
        $rendered_cards_html .= '  <div class="' . $body_class . '">';

        foreach ($dev_list as $d) {
            $is_on = ($d['state'] === 'ON');
            $row_class = $d['offline'] ? "dev-row offline" : ($is_on ? "dev-row state-on" : "dev-row state-off");

            $rendered_cards_html .= '      <div class="' . $row_class . '">';
            $rendered_cards_html .= '          <span class="dev-name"><span>' . $d['icon'] . ' ' . $d['label'] . '</span>';
            
            $rendered_cards_html .= '          <span class="status-container">';
            $rendered_cards_html .= '              <span class="status-text ' . ($d['offline'] ? 'offline' : ($is_on ? 'on' : 'off')) . '">' . ($d['offline'] ? '⚠️ Offline' : ($is_on ? '🟢 ON' : '⚫ OFF')) . '</span>';
            
            // Rendu de la tirette en ligne directe sans fioritures (si ON)
            if ($is_on && $d['dimmable']) {
                $rendered_cards_html .= '          <span class="direct-dimmer-block">';
                $rendered_cards_html .= '              <input type="range" min="0" max="100" value="' . $d['dimmer'] . '" class="dimmer-range" oninput="this.nextElementSibling.innerText = this.value + \'%\'" onchange="sendOBKDimmer(\'' . $d['ip'] . '\', \'dimmer\', this.value)">';
                $rendered_cards_html .= '              <span class="dimmer-percent">' . $d['dimmer'] . '%</span>';
                $rendered_cards_html .= '          </span>';
            }
            
            $rendered_cards_html .= '          </span></span>';
            $rendered_cards_html .= '          <button class="toggle-btn ' . ($is_on ? 'btn-on' : 'btn-off') . '" data-type="' . $d['type'] . '" data-ip="' . $d['ip'] . '" data-relay="' . $d['relay'] . '" data-mqtt="' . $mqtt_name . '" data-state="' . $d['state'] . '" data-label="' . htmlspecialchars($d['label'], ENT_QUOTES) . '">' . ($is_on ? 'OFF' : 'ON') . '</button>';
            $rendered_cards_html .= '      </div>';
        }
        $rendered_cards_html .= '  </div></div>';
    }
}
?>
<div class="container">
    <div class="macro-grid">
        <div class="macro-card">
            <div class="macro-title">🎮 GAMING</div>
            <div class="macro-desc">Gruppe aktivieren / deaktivieren</div>
        </div>
        <div class="macro-card">
            <div class="macro-title">⚙️ OPTION 2</div>
            <div class="macro-desc">Mehrfachaktion 2</div>
        </div>
        <div class="macro-card">
            <div class="macro-title">💡 OPTION 3</div>
            <div class="macro-desc">Mehrfachaktion 3</div>
        </div>
    </div>

    <div id="main-grid" class="sha-main-grid">
        <?= $rendered_cards_html ?>
    </div>
</div>

<?php include("footer.php"); ?>