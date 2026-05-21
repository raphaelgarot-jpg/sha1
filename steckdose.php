<?php
include("header.php");

// --- 1. TRAITEMENT DE L'ACTION INTERACTIVE (AJAX COUPLÉ MQTT) ---
if (isset($_POST['action']) && isset($_POST['ip'])) {
    header('Content-Type: application/json');
    $ip = filter_var($_POST['ip'], FILTER_VALIDATE_IP);
    $target_state = ($_POST['action'] === 'ON') ? 'ON' : 'OFF';

    if (!$ip) {
        echo json_encode(['success' => false, 'message' => 'IP invalide']);
        exit;
    }

    // Commande HTTP Tasmota unifiée (fonctionne aussi sur les relais Shelly)
    $url = "http://{$ip}/cm?cmnd=Power1%20{$target_state}";
    
    $ctx = stream_context_create(['http' => ['timeout' => 2]]);
    $result = @file_get_contents($url, false, $ctx);

    if ($result !== false) {
        echo json_encode(['success' => true, 'new_state' => $target_state]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Appareil injoignable']);
    }
    exit;
}

// 2. Chargement de la structure de la maison
$rooms = parse_ini_file('config/home_structure.conf', true);
$sys = $rooms['System'];
$defaults = $rooms['Defaults'] ?? [];

// 3. LECTURE DU CACHE RAM S.H.A.
$cache_file = 'data/sha_live.json';
$tas_cache = [];

if (file_exists($cache_file) && filesize($cache_file) > 0) {
    $live_data = json_decode(@file_get_contents($cache_file), true);
    $tas_cache = $live_data['devices'] ?? [];
}

// 4. Traitement et regroupement des pièces
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

            // 💡 AJOUT STRICT : On prend désormais les "socket" et les "light" tout court
            if ($type == 'socket' || $type == 'light') {
                
                // 🛑 FILTRAGE STRICT : Si marqué no_relay, on l'ignore (uniquement pour les sockets concernées)
                if (isset($parts[5]) && trim($parts[5]) === 'no_relay') {
                    continue;
                }

                $dev_data = $tas_cache[$ip] ?? null;
                $is_offline = true;
                $current_state = "OFF";

                if ($dev_data !== null && is_array($dev_data)) {
                    $last_seen = $dev_data['last_seen'] ?? 0;
                    if (abs(time() - $last_seen) < 600) {
                        $is_offline = false;
                        // On récupère l'état d'activation
                        $current_state = $dev_data['state'] ?? (($dev_data['power'] ?? 0) > 2 ? "ON" : "OFF");
                    }
                }

                if (!$is_offline && $current_state === "ON") {
                    $room_active_count++;
                }

                $icon = $parts[4] ?? $defaults[$type];

                $dev_list[] = [
                    'label' => $label,
                    'ip' => $ip,
                    'icon' => $icon,
                    'state' => $current_state,
                    'offline' => $is_offline
                ];
            }
        }
    }

    if (empty($dev_list)) continue;

    // --- CONFIGURATION DYNAMIQUE DU DOUBLE SLOT (ARBEITSZIMMER) ---
    if ($name == "Arbeitszimmer") {
        $grid_span = "grid-column: span 2;";
        $body_style = "display: grid; grid-template-columns: repeat(2, 1fr); gap: 0 40px; padding: 10px 20px;";
    } else {
        $grid_span = "";
        $body_style = "display: flex; flex-direction: column; gap: 10px; padding: 15px 20px;";
    }
    
    $rendered_cards_html .= '<div class="room-card" style="' . $grid_span . '">';
    $rendered_cards_html .= '  <div class="room-head">';
    $rendered_cards_html .= '      <div class="room-title"><span>' . ($data['icon'] ?? '🏠') . '</span> ' . strtoupper($name) . '</div>';
    $rendered_cards_html .= '      <span class="badge badge-blue">🔌 ' . $room_active_count . ' Active(s)</span>';
    $rendered_cards_html .= '  </div>';
    $rendered_cards_html .= '  <div class="room-body" style="' . $body_style . '">';

    foreach ($dev_list as $d) {
        $rendered_cards_html .= '      <div class="dev-row" style="display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #22222244;">';
        $rendered_cards_html .= '          <span class="dev-name" style="font-size: 0.85rem; color: #ccc;"><span style="margin-right: 8px;">' . $d['icon'] . '</span>' . $d['label'] . '</span>';
        
        if ($d['offline']) {
            $rendered_cards_html .= '      <span style="font-size: 0.75rem; color: var(--red); font-weight: bold; padding: 4px 10px;">OFFLINE</span>';
        } else {
            $is_on = ($d['state'] === 'ON');
            $btn_color = $is_on ? '#27ae60' : '#444';
            $btn_text = $is_on ? 'ON' : 'OFF';
            
            $rendered_cards_html .= '      <button class="toggle-btn" data-ip="' . $d['ip'] . '" data-state="' . $d['state'] . '" data-label="' . htmlspecialchars($d['label'], ENT_QUOTES) . '" style="background: ' . $btn_color . '; color: #fff; border: none; padding: 4px 14px; font-size: 0.75rem; font-weight: 900; border-radius: 4px; cursor: pointer; min-width: 60px; transition: background 0.2s;">' . $btn_text . '</button>';
        }
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
            <div style="font-size: 0.7rem; color: #777;">Activer / Désactiver le groupe</div>
        </div>
        <div class="room-card" style="padding: 15px; text-align: center; border: 1px solid #ff980066; border-top: 3px solid #ff9800; cursor: pointer;">
            <div style="font-size: 0.8rem; font-weight: 900; color: #ff9800; text-transform: uppercase; margin-bottom: 5px;">⚙️ OPTION 2</div>
            <div style="font-size: 0.7rem; color: #777;">Action Multiple 2</div>
        </div>
        <div class="room-card" style="padding: 15px; text-align: center; border: 1px solid #ff980066; border-top: 3px solid #ff9800; cursor: pointer;">
            <div style="font-size: 0.8rem; font-weight: 900; color: #ff9800; text-transform: uppercase; margin-bottom: 5px;">💡 OPTION 3</div>
            <div style="font-size: 0.7rem; color: #777;">Action Multiple 3</div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px;">
        <?= $rendered_cards_html ?>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll('.toggle-btn').forEach(function(button) {
        button.addEventListener('click', function() {
            var btn = this;
            var ip = btn.getAttribute('data-ip');
            var currentState = btn.getAttribute('data-state');
            var label = btn.getAttribute('data-label');
            var nextAction = (currentState === 'ON') ? 'OFF' : 'ON';

            if (nextAction === 'OFF') {
                var confirmCut = confirm("⚠️ S.H.A. Sécurité : Êtes-vous sûr de vouloir ÉTEINDRE l'appareil \"" + label + "\" ?");
                if (!confirmCut) return;
            }

            btn.style.opacity = "0.5";
            btn.disabled = true;

            var formData = new FormData();
            formData.append('action', nextAction);
            formData.append('ip', ip);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                btn.style.opacity = "1";
                btn.disabled = false;

                if (data.success) {
                    if (data.new_state === 'ON') {
                        btn.style.background = "#27ae60";
                        btn.textContent = "ON";
                        btn.setAttribute('data-state', 'ON');
                    } else {
                        btn.style.background = "#444";
                        btn.textContent = "OFF";
                        btn.setAttribute('data-state', 'OFF');
                    }
                } else {
                    alert("❌ Échec : " + (data.message || "Erreur de communication."));
                }
            })
            .catch(error => {
                btn.style.opacity = "1";
                btn.disabled = false;
                alert("❌ Erreur réseau.");
            });
        });
    });
});
</script>

<?php include("footer.php"); ?>