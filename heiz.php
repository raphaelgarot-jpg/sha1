<?php
ob_start(); // 🟩 ZONE MODIFIÉE : Bloque le HTML de header.php lors des requêtes AJAX
include("header.php");
require_once("core/functions.php");

// Contrôleur des requêtes d'action Cube
handle_heating_action();

// --- 1. FILTRAGE DES PIÈCES DE LA STRUCTURE ---
$heizung_rooms = [];
foreach ($rooms as $name => $data) {
    if ($name == 'System' || $name == 'Defaults') continue;
    
    $has_heizung = false;
    if (isset($data['heizung_id']) && trim($data['heizung_id']) !== 'None' && trim($data['heizung_id']) !== '') {
        $has_heizung = true;
    }
    if (!$has_heizung && !empty($data['devices'])) {
        foreach ($data['devices'] as $dev) {
            if (strpos($dev, 'heizung|') !== false) { $has_heizung = true; break; }
        }
    }
    if ($has_heizung) {
        $heizung_rooms[$name] = $data;
    }
}

$cube_live = get_maxcube_live_data();
$system_status = $cube_live['system'];
$live_devices = $cube_live['devices'];
?>

<main class="container">
    <div class="room-card" style="margin-bottom: 25px; border: 1px solid rgba(255, 145, 0, 0.25); border-top: 3px solid var(--orange); padding: 20px; display: flex; flex-direction: column; gap: 15px; box-shadow: 0 6px 15px rgba(0,0,0,0.15);">
        <div class="room-title" style="justify-content: center; color: var(--orange); text-shadow: 0 0 10px rgba(255, 145, 0, 0.15); margin: 0;">
            <span>♨️</span> HEIZUNG
        </div>
        <div style="display: flex; gap: 20px; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div style="color: #fff; font-size: 0.75rem; font-weight: 700; display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <div>CUBE LOAD : <span style="color: <?= $system_status['duty_color']; ?>; font-weight: 900;"><?= $system_status['duty_val']; ?>%</span></div>
                <div style="height: 15px; width: 1px; background: var(--border);"></div>
                <div>RAM FREE SLOTS : <span style="color: #bcaaa4; font-weight: 900;"><?= $system_status['slots_val']; ?></span></div>
                <div style="height: 15px; width: 1px; background: var(--border);"></div>
                <div>PROCHAINE MAJ : <span style="color: var(--green); font-weight: 900;"><?= $system_status['next_fmt']; ?></span></div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button onclick="location.href='heiz.php'" class="toggle-btn btn-off" style="min-width: 100px; font-weight: 900; text-transform: uppercase;">🔃 SOFT SCAN</button>
                <button onclick="triggerScan()" class="toggle-btn btn-on" style="min-width: 130px; font-weight: 900; text-transform: uppercase;">🔄 HARD SCAN</button>
            </div>
        </div>
    </div>

    <div class="room-grid">
        <?php foreach ($heizung_rooms as $name => $data):
            $room_thermostats = [];
            $target_id = isset($data['heizung_id']) ? trim($data['heizung_id']) : '';
            
            $cube_room_name = '';
            $primary_rf_id = '';

            if (!empty($target_id) && strcasecmp($target_id, 'None') !== 0) {
                if (strpos($target_id, '|') !== false) {
                    list($cube_room_name, $primary_rf_id) = explode('|', $target_id);
                    $cube_room_name = trim($cube_room_name);
                    $primary_rf_id = strtolower(trim($primary_rf_id));
                } else {
                    if (preg_match('/^[0-9a-fA-F]{6}$/', $target_id)) { $primary_rf_id = strtolower($target_id); } else { $cube_room_name = $target_id; }
                }

                $final_key = !empty($primary_rf_id) ? $primary_rf_id : $target_id;
                $room_thermostats[$final_key] = [
                    'id'        => $primary_rf_id,
                    'cube_room' => $cube_room_name
                ];
            }

            if (!empty($data['devices'])) {
                foreach ($data['devices'] as $conf_dev) {
                    if (strpos($conf_dev, 'heizung|') !== false) {
                        $parts = explode('|', $conf_dev);
                        if (count($parts) >= 2) {
                            $sub_rf = strtolower(trim($parts[1]));
                            $room_thermostats[$sub_rf] = [
                                'id'        => $sub_rf,
                                'cube_room' => $cube_room_name
                            ];
                        }
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
                    <?php foreach ($room_thermostats as $lookup_key => $th_conf):
                        $lookup_key = strtolower($lookup_key);
                        $live_dev = $live_devices[$lookup_key] ?? null;

                        if (!$live_dev && !empty($th_conf['cube_room'])) {
                            foreach ($live_devices as $rf_key => $ldev) {
                                if (strcasecmp($ldev['room'], $th_conf['cube_room']) === 0) { $live_dev = $ldev; $lookup_key = strtolower($rf_key); break; }
                            }
                        }
                        if (!$live_dev) {
                            foreach ($live_devices as $rf_key => $ldev) {
                                if (strcasecmp($ldev['room'], $name) === 0) { $live_dev = $ldev; $lookup_key = strtolower($rf_key); break; }
                            }
                        }

                        $no_log_info = ($live_dev === null);

                        $isWin     = $live_dev ? $live_dev['win'] : false; 
                        $isBoost   = $live_dev ? $live_dev['boost'] : false; 
                        $isErr     = $live_dev ? $live_dev['err'] : false;
                        $isBattLow = $live_dev ? $live_dev['batt'] : false;
                        $mode      = $live_dev ? $live_dev['mode'] : 'AUTO';
                        $soll      = $live_dev ? $live_dev['soll'] : 20.0;
                        $ist       = $live_dev ? $live_dev['ist'] : '--';

                        $status_color = 'var(--green)';
                        if ($isErr || $no_log_info) $status_color = 'var(--red)';
                        elseif ($isWin) $status_color = 'var(--accent)';
                        elseif ($isBoost) $status_color = 'var(--orange)';
                    ?>
                        <div class="heating-valve-block" <?= $no_log_info ? 'style="border-color: rgba(255, 23, 68, 0.25);"' : '' ?>>
                            
                            <div class="valve-status-line" style="display: flex; justify-content: flex-end; align-items: center; width: 100%;">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span class="valve-status-indicator" style="color: <?= $status_color ?>; margin-right: 2px;">
                                        • <?= $no_log_info ? 'NO LOG DATA' : ($isErr ? 'ERR' : ($isWin ? 'OFFEN' : ($isBoost ? 'BOOST' : $mode))); ?>
                                    </span>
                                    <?php if($isWin) echo '<span style="color:var(--accent);">🪟</span>'; if($isErr || $no_log_info) echo '<span style="color:var(--red);">⚠️</span>'; if($isBattLow) echo '<span style="color:var(--solar);">🪫</span>'; ?>
                                </div>
                            </div>

                            <?php if ($no_log_info): ?>
                                <div style="font-size: 0.65rem; color: var(--red); background: rgba(255, 23, 68, 0.05); padding: 5px 10px; border-radius: var(--radius-sm); text-align: center; border: 1px dashed rgba(255, 23, 68, 0.2); font-weight: 700; letter-spacing: 0.2px;">
                                    ⚠️ Statut introuvable (Attente synchronisation)
                                </div>
                            <?php endif; ?>

                            <div class="display-box-temp" <?= $no_log_info ? 'style="opacity: 0.6;"' : '' ?>>
                                <div class="temp-segment">
                                    <div class="label-min-temp">IST</div>
                                    <div class="val-ist"><?= $ist; ?>°</div>
                                </div>
                                <div class="temp-segment">
                                    <div class="label-min-temp">SOLL</div>
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
                                
                                <button type="button" onclick="setRoomTemperature('<?= $lookup_key; ?>', this.previousElementSibling.value, event)" class="btn-ok-temp" <?php if($isWin || $isBoost) echo 'disabled'; ?>>OK</button>
                                
                                <?php if ($isWin): ?>
                                    <button type="button" class="btn-boost-action" style="opacity:0.15; cursor:not-allowed; padding: 6px 10px; font-size: 0.7rem; flex: 1;" disabled>BOOST</button>
                                <?php elseif ($isBoost): ?>
                                    <button type="button" class="btn-boost-action active" style="padding: 6px 10px; font-size: 0.7rem; flex: 1;" disabled>BOOSTING</button>
                                <?php else: ?>
                                    <button type="button" onclick="triggerRoomBoost('<?= $lookup_key; ?>', event)" class="btn-boost-action" style="padding: 6px 10px; font-size: 0.7rem; flex: 1;">BOOST</button>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<?php include("footer.php"); ?>