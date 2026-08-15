<?php
ob_start();
include("header.php");
require_once("core/functions.php");

// Contrôleur des commandes AJAX et tickets
handle_heating_action();

// --- 1. FILTRAGE DES PIÈCES COMPORTANT DU CHAUFFAGE, CONTACTS OU CLIM ---
$heizung_rooms = [];
foreach ($rooms as $name => $data) {
    if ($name == 'System' || $name == 'Defaults') continue;
    
    $has_climate = false;
    if (isset($data['heizung_id']) && trim($data['heizung_id']) !== 'None' && trim($data['heizung_id']) !== '') {
        $has_climate = true;
    }
    if (!$has_climate && !empty($data['devices'])) {
        foreach ($data['devices'] as $dev) {
            if (strpos($dev, 'heizung|') !== false || strpos($dev, 'fensterkontakt|') !== false || strpos($dev, 'klima|') !== false) { 
                $has_climate = true; 
                break; 
            }
        }
    }
    if ($has_climate) {
        $heizung_rooms[$name] = $data;
    }
}

// 2. Télémétrie CUL (Cube 2 a-culfw) depuis data/heiz_cul_out.json
$cul_live = get_maxcube_cul_live_data();
$system_status = $cul_live['system'];
$live_devices = $cul_live['devices'];

// 3. Télémétrie Live RAM (Daikin / Tasmota / MQTT)
$sha_live = get_sha_live_cache();
$sha_devices = $sha_live['devices'] ?? [];
?>

<main class="container">
    <div class="room-card" style="margin-bottom: 25px; border: 1px solid rgba(255, 145, 0, 0.25); border-top: 3px solid var(--orange); padding: 20px; display: flex; flex-direction: column; gap: 15px; box-shadow: 0 6px 15px rgba(0,0,0,0.15);">
        <div class="room-title" style="justify-content: center; color: var(--orange); text-shadow: 0 0 10px rgba(255, 145, 0, 0.15); margin: 0;">
            <span>♨️</span> HEIZUNG & KLIMA
        </div>
        <div style="display: flex; gap: 20px; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div style="color: #fff; font-size: 0.75rem; font-weight: 700; display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <div>CUBE 2 DUTY : <span style="color: <?= $system_status['duty_color']; ?>; font-weight: 900;"><?= $system_status['duty_val']; ?>%</span></div>
                <div style="height: 15px; width: 1px; background: var(--border);"></div>
                <div>MODEM : <span style="color: #bcaaa4; font-weight: 900;"><?= htmlspecialchars($system_status['firmware'] ?? 'a-culfw 868MHz'); ?></span></div>
                <div style="height: 15px; width: 1px; background: var(--border);"></div>
                <div>PROCHAINE MAJ : <span style="color: var(--green); font-weight: 900;"><?= $system_status['next_fmt']; ?></span></div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button onclick="triggerScan()" class="toggle-btn btn-on" style="min-width: 130px; font-weight: 900; text-transform: uppercase;">🔄 HARD SCAN</button>
            </div>
        </div>
    </div>

    <div class="room-grid">
        <?php foreach ($heizung_rooms as $name => $data):
            $room_valves = [];
            $room_windows = [];
            $room_klimas = [];

            // Compatibilité ancienne clé heizung_id = "NOM|rf" ou "rf"
            if (isset($data['heizung_id']) && trim($data['heizung_id']) !== 'None' && trim($data['heizung_id']) !== '') {
                $target_id = trim($data['heizung_id']);
                $rf = (strpos($target_id, '|') !== false) ? explode('|', $target_id)[1] : $target_id;
                $rf = strtolower(trim($rf));
                if (!empty($rf)) {
                    $room_valves[$rf] = [
                        'rf'    => $rf,
                        'label' => 'Thermostat ' . $name,
                        'icon'  => '🔥'
                    ];
                }
            }

            // Parsing normalisé des devices déclarés sous la pièce
            if (!empty($data['devices'])) {
                foreach ($data['devices'] as $conf_dev) {
                    $parts = explode('|', $conf_dev);
                    $type = trim($parts[0] ?? '');
                    
                    if ($type === 'heizung' && count($parts) >= 2) {
                        $rf = strtolower(trim($parts[1]));
                        $label = trim($parts[2] ?? ('Thermostat ' . $name));
                        $icon = trim($parts[3] ?? '🔥');
                        if (count($parts) >= 5) {
                            $label = trim($parts[3]);
                            $icon = trim($parts[4]);
                        }
                        $room_valves[$rf] = [
                            'rf'    => $rf,
                            'label' => $label,
                            'icon'  => $icon
                        ];
                    } elseif ($type === 'fensterkontakt' && count($parts) >= 2) {
                        $rf = strtolower(trim($parts[1]));
                        $label = trim($parts[2] ?? ('Fenster ' . $name));
                        $icon = trim($parts[3] ?? '🪟');
                        if (count($parts) >= 5) {
                            $label = trim($parts[3]);
                            $icon = trim($parts[4]);
                        }
                        $room_windows[$rf] = [
                            'rf'    => $rf,
                            'label' => $label,
                            'icon'  => $icon
                        ];
                    } elseif ($type === 'klima' && count($parts) >= 2) {
                        $room_klimas[] = [
                            'ip'    => trim($parts[1]),
                            'relay' => trim($parts[2] ?? '1'),
                            'label' => trim($parts[3] ?? 'Klimaanlage'),
                            'icon'  => trim($parts[4] ?? '❄️')
                        ];
                    }
                }
            }
            ?>
            <div class="room-card">
                <div class="room-head">
                    <div class="room-title">
                        <span><?= $data['icon'] ?? '🔥' ?></span> <?= strtoupper($name) ?>
                    </div>
                </div>
                <div class="room-body heating-room-body">
                    
                    <!-- 1. BLOC CONTACTS DE FENÊTRE -->
                    <?php if (!empty($room_windows)): ?>
                        <?php foreach ($room_windows as $rf => $w_conf):
                            $w_live = $live_devices[$rf] ?? null;
                            $is_open = $w_live ? ($w_live['is_open'] ?? false) : false;
                            $is_batt_low = $w_live ? ($w_live['batt'] ?? false) : false;
                            $no_data = ($w_live === null);
                        ?>
                            <div class="dev-row" style="padding: 6px 10px; border-radius: var(--radius-sm); background: rgba(0,0,0,0.15); margin-bottom: 5px;">
                                <div style="display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 0.8rem; color: #fff;">
                                    <span><?= htmlspecialchars($w_conf['icon']) ?></span>
                                    <span><?= htmlspecialchars($w_conf['label']) ?></span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <?php if (!$no_data): ?>
                                        <?php if ($is_batt_low): ?>
                                            <span style="color: var(--solar);" title="Batterie faible">🪫</span>
                                        <?php else: ?>
                                            <span style="color: var(--green);" title="Batterie OK">🔋</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <span class="badge" style="background: <?= $no_data ? 'rgba(255,23,68,0.15)' : ($is_open ? 'rgba(255,69,0,0.2)' : 'rgba(0,230,118,0.15)') ?>; color: <?= $no_data ? 'var(--red)' : ($is_open ? 'var(--accent)' : 'var(--green)') ?>; border: 1px solid currentColor;">
                                        <?= $no_data ? 'STANDBY' : ($is_open ? 'OUVERT 🪟' : 'FERMÉ 🔒') ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- 2. BLOC VANNES RADIATEURS eQ-3 -->
                    <?php foreach ($room_valves as $rf => $v_conf):
                        $live_dev = $live_devices[$rf] ?? null;
                        $no_log_info = ($live_dev === null);

                        $isWin     = $live_dev ? ($live_dev['win'] ?? false) : false; 
                        $isBoost   = $live_dev ? ($live_dev['boost'] ?? false) : false; 
                        $isErr     = $live_dev ? ($live_dev['err'] ?? false) : false;
                        $isBattLow = $live_dev ? ($live_dev['batt'] ?? false) : false;
                        $mode      = $live_dev ? ($live_dev['mode'] ?? 'AUTO') : 'AUTO';
                        $soll      = $live_dev ? floatval($live_dev['soll'] ?? 20.0) : 20.0;
                        $ist       = $live_dev ? ($live_dev['ist'] ?? '--') : '--';
                        $valve_pos = $live_dev ? ($live_dev['valve_pos'] ?? '--') : '--';

                        $status_color = 'var(--green)';
                        if ($isErr || $no_log_info) $status_color = 'var(--red)';
                        elseif ($isWin) $status_color = 'var(--accent)';
                        elseif ($isBoost) $status_color = 'var(--orange)';
                    ?>
                        <div class="heating-valve-block" <?= $no_log_info ? 'style="border-color: rgba(255, 23, 68, 0.25);"' : '' ?>>
                            
                            <div class="valve-status-line" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                                <div style="font-weight: 800; font-size: 0.75rem; color: #eee; display: flex; align-items: center; gap: 6px;">
                                    <span><?= htmlspecialchars($v_conf['icon']) ?></span>
                                    <span><?= htmlspecialchars($v_conf['label']) ?></span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span class="valve-status-indicator" style="color: <?= $status_color ?>; margin-right: 2px;">
                                        • <?= $no_log_info ? 'EN ATTENTE' : ($isErr ? 'ERR' : ($isWin ? 'FENÊTRE' : ($isBoost ? 'BOOST' : $mode))); ?>
                                    </span>
                                    <?php if($isWin) echo '<span style="color:var(--accent);">🪟</span>'; if($isErr || $no_log_info) echo '<span style="color:var(--red);">⚠️</span>'; if($isBattLow) echo '<span style="color:var(--solar);">🪫</span>'; ?>
                                </div>
                            </div>

                            <?php if ($no_log_info): ?>
                                <div style="font-size: 0.65rem; color: var(--red); background: rgba(255, 23, 68, 0.05); padding: 5px 10px; border-radius: var(--radius-sm); text-align: center; border: 1px dashed rgba(255, 23, 68, 0.2); font-weight: 700; letter-spacing: 0.2px;">
                                    ⚠️ Vanne non synchronisée (Attente trame CUL)
                                </div>
                            <?php endif; ?>

                            <div class="display-box-temp" <?= $no_log_info ? 'style="opacity: 0.6;"' : '' ?>>
                                <div class="temp-segment">
                                    <div class="label-min-temp">IST (MESURE)</div>
                                    <div class="val-ist"><?= $ist; ?><?= is_numeric($ist) ? '°' : '' ?></div>
                                </div>
                                <div class="temp-segment">
                                    <div class="label-min-temp">OUVERTURE</div>
                                    <div class="val-ist" style="font-size: 1.4rem; color: #bcaaa4;"><?= $valve_pos ?></div>
                                </div>
                                <div class="temp-segment">
                                    <div class="label-min-temp">CONSIGNE</div>
                                    <div class="val-soll"><?= number_format($soll, 1); ?>°</div>
                                </div>
                            </div>

                            <div class="control-row">
                                <select class="select-sha-temp" <?php if($isWin || $isBoost) echo 'disabled'; ?>>
                                    <?php for($i = 5.0; $i <= 30.0; $i += 0.5):
                                        $v = number_format($i, 1, '.', '');
                                        $sel = (abs((float)$v - (float)$soll) < 0.1) ? 'selected' : '';
                                        echo "<option value='$v' $sel>$v °C</option>";
                                    endfor; ?>
                                </select>
                                
                                <button type="button" onclick="setRoomTemperature('<?= $rf; ?>', this.previousElementSibling.value, event)" class="btn-ok-temp" <?php if($isWin || $isBoost) echo 'disabled'; ?>>OK</button>
                                
                                <?php if ($isWin): ?>
                                    <button type="button" class="btn-boost-action" style="opacity:0.15; cursor:not-allowed; padding: 6px 10px; font-size: 0.7rem; flex: 1;" disabled>BOOST</button>
                                <?php elseif ($isBoost): ?>
                                    <button type="button" class="btn-boost-action active" style="padding: 6px 10px; font-size: 0.7rem; flex: 1;" disabled>BOOSTING</button>
                                <?php else: ?>
                                    <button type="button" onclick="triggerRoomBoost('<?= $rf; ?>', event)" class="btn-boost-action" style="padding: 6px 10px; font-size: 0.7rem; flex: 1;">BOOST</button>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endforeach; ?>

                    <!-- 3. BLOC CLIMATISATION DAIKIN (KLIMA) -->
                    <?php foreach ($room_klimas as $klima):
                        $k_ip = $klima['ip'];
                        $k_dev = $sha_devices[$k_ip] ?? null;

                        $is_online = ($k_dev !== null && abs(time() - ($k_dev['last_seen'] ?? 0)) < 600);
                        $k_state = strtoupper($k_dev['state'] ?? 'OFF');
                        $is_on = ($k_state === 'ON');

                        $k_ist = isset($k_dev['room_temp']) ? $k_dev['room_temp'] : (isset($k_dev['temperature']) && $k_dev['temperature'] != 154 ? $k_dev['temperature'] : '--');
                        $k_soll = isset($k_dev['soll']) ? floatval($k_dev['soll']) : (isset($k_dev['target_temp']) ? floatval($k_dev['target_temp']) : 21.0);
                        $k_mode = strtoupper($k_dev['mode'] ?? ($is_on ? 'COOL' : 'OFF'));
                        $k_power = isset($k_dev['power']) ? round($k_dev['power']) : null;

                        $k_status_color = $is_on ? 'var(--green)' : '#8e7d7b';
                        if (!$is_online) $k_status_color = 'var(--red)';
                    ?>
                        <div class="heating-valve-block" style="border-color: <?= $is_on ? 'rgba(0, 230, 118, 0.3)' : 'var(--border)' ?>;">
                            <div class="valve-status-line" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                                <div style="font-weight: 800; font-size: 0.75rem; color: #fff; display: flex; align-items: center; gap: 6px;">
                                    <span><?= htmlspecialchars($klima['icon']) ?></span>
                                    <span><?= strtoupper(htmlspecialchars($klima['label'])) ?></span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <?php if ($k_power !== null && $k_power > 0): ?>
                                        <span class="badge badge-blue" style="font-size: 0.6rem; padding: 2px 6px;">⚡ <?= $k_power ?>W</span>
                                    <?php endif; ?>
                                    <span class="valve-status-indicator" style="color: <?= $k_status_color ?>;">
                                        • <?= !$is_online ? 'OFFLINE' : ($is_on ? $k_mode : 'OFF'); ?>
                                    </span>
                                    <?php if (!$is_online): ?><span style="color:var(--red);">⚠️</span><?php endif; ?>
                                </div>
                            </div>

                            <?php if (!$is_online): ?>
                                <div style="font-size: 0.65rem; color: var(--red); background: rgba(255, 23, 68, 0.05); padding: 5px 10px; border-radius: var(--radius-sm); text-align: center; border: 1px dashed rgba(255, 23, 68, 0.2); font-weight: 700; letter-spacing: 0.2px;">
                                    ⚠️ Climatisation non joignable (Hors ligne)
                                </div>
                            <?php endif; ?>

                            <div class="display-box-temp" <?= !$is_online ? 'style="opacity: 0.6;"' : '' ?>>
                                <div class="temp-segment">
                                    <div class="label-min-temp">IST (PIÈCE)</div>
                                    <div class="val-ist"><?= $k_ist; ?><?= is_numeric($k_ist) ? '°' : '' ?></div>
                                </div>
                                <div class="temp-segment">
                                    <div class="label-min-temp">CONSIGNE</div>
                                    <div class="val-soll"><?= number_format($k_soll, 1); ?>°</div>
                                </div>
                            </div>

                            <div class="control-row" style="display: flex; gap: 8px; align-items: center;">
                                <select class="select-sha-temp" <?php if(!$is_online) echo 'disabled'; ?>>
                                    <?php for($i = 16.0; $i <= 30.0; $i += 0.5):
                                        $v = number_format($i, 1, '.', '');
                                        $sel = (abs((float)$v - (float)$k_soll) < 0.1) ? 'selected' : '';
                                        echo "<option value='$v' $sel>$v °C</option>";
                                    endfor; ?>
                                </select>
                                
                                <button type="button" onclick="setRoomTemperature('klima_<?= $k_ip; ?>', this.previousElementSibling.value, event)" class="btn-ok-temp" <?php if(!$is_online) echo 'disabled'; ?>>OK</button>

                                <button type="button" 
                                        class="toggle-btn <?= $is_on ? 'btn-on' : 'btn-off' ?>" 
                                        data-type="klima" 
                                        data-ip="<?= $k_ip ?>" 
                                        data-relay="<?= $klima['relay'] ?>" 
                                        data-state="<?= $k_state ?>" 
                                        data-label="<?= htmlspecialchars($klima['label'], ENT_QUOTES) ?>"
                                        style="min-width: 70px; height: 34px; padding: 0 10px;"
                                        <?php if(!$is_online) echo 'disabled'; ?>>
                                    <?= $is_on ? 'OFF' : 'ON' ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>

                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<?php include("footer.php"); ?>