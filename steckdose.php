<?php
include("header.php");
require_once("core/functions.php");

handle_device_action();

// Diagnostic app.conf
$debug_conf_path = __DIR__ . '/config/app.conf';
$debug_msg = "";
if (file_exists($debug_conf_path) && is_readable($debug_conf_path)) {
    $debug_arr = parse_ini_file($debug_conf_path, true);
    if ($debug_arr !== false && isset($debug_arr['Windows'])) {
        $debug_msg = "<span style='color: green; font-weight: bold;'>✅ Configuration app.conf chargée !</span>";
    }
}

$rooms = parse_ini_file('config/home_structure.conf', true);
$defaults = $rooms['Defaults'] ?? [];
$live_data = get_sha_live_cache();
$tas_cache = $live_data['devices'] ?? [];
$rendered_cards_html = "";

if (is_array($rooms)) {
    foreach ($rooms as $name => $data) {
        if ($name == 'System' || $name == 'Defaults') continue;
        $dev_list = []; $room_active_count = 0;

        $all_raw_devices = array_merge($data['devices'] ?? [], $data['pcs'] ?? []);

        foreach ($all_raw_devices as $dev) {
            $parts = explode('|', $dev);
            if (count($parts) < 4) continue;
            list($type, $ip, $relay_or_mac, $label) = $parts;

            // 💡 SÉCURITÉ : On ignore immédiatement l'appareil s'il est marqué sans relais
            if (isset($parts[5]) && trim($parts[5]) === 'no_relay') {
                continue;
            }

            if (in_array($type, ['socket', 'light', 'light_p', 'pc'])) {
                $dev_data = $tas_cache[$ip] ?? null;
                $is_offline = true; $current_state = "OFF";

                if ($dev_data !== null) {
                    if ($type === 'pc') {
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

                $dev_list[] = [
                    'type' => $type, 'label' => $label, 'ip' => $ip, 'relay' => $relay_or_mac,
                    'icon' => $icon, 'state' => $current_state, 'offline' => $is_offline
                ];
            }
        }

        if (empty($dev_list)) continue;
        $is_az = ($name == "Arbeitszimmer");

        $rendered_cards_html .= '<div class="room-card" style="' . ($is_az ? "grid-column: span 2;" : "") . '">';
        $rendered_cards_html .= '  <div class="room-head"><div class="room-title"><span>' . ($data['icon'] ?? '🏠') . '</span> ' . strtoupper($name) . '</div><span class="badge badge-blue">🔌 ' . $room_active_count . ' aktiv</span></div>';
        $rendered_cards_html .= '  <div class="room-body ' . ($is_az ? "grid-2-columns" : "flex-column") . '" style="padding: 15px 20px;">';

        foreach ($dev_list as $d) {
            $is_on = ($d['state'] === 'ON');
            $row_class = $d['offline'] ? "dev-row offline" : ($is_on ? "dev-row state-on" : "dev-row state-off");

            $rendered_cards_html .= '      <div class="' . $row_class . '">';
            $rendered_cards_html .= '          <span class="dev-name"><span>' . $d['icon'] . ' ' . $d['label'] . '</span>';
            $rendered_cards_html .= '          <span class="status-text ' . ($d['offline'] ? 'offline' : ($is_on ? 'on' : 'off')) . '">' . ($d['offline'] ? '⚠️ Offline' : ($is_on ? '🟢 ON' : '⚫ OFF')) . '</span></span>';
            $rendered_cards_html .= '          <button class="toggle-btn ' . ($is_on ? 'btn-on' : 'btn-off') . '" data-type="' . $d['type'] . '" data-ip="' . $d['ip'] . '" data-relay="' . $d['relay'] . '" data-state="' . $d['state'] . '" data-label="' . htmlspecialchars($d['label'], ENT_QUOTES) . '">' . ($is_on ? 'OFF' : 'ON') . '</button>';
            $rendered_cards_html .= '      </div>';
        }
        $rendered_cards_html .= '  </div></div>';
    }
}
?>
<div class="container">
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px; margin-top: 20px;">
        <?= $rendered_cards_html ?>
    </div>
</div>
<?php include("footer.php"); ?>