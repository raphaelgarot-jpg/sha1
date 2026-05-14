<?php
include("header.php");

// 1. Chargement config
$rooms = parse_ini_file('config/home_structure.conf', true);
$sys = $rooms['System'];

// Cache pour éviter de solliciter plusieurs fois le même Tasmota
$tas_cache = [];

// 2. Lecture des compteurs principaux (Verteiler)
$v_haus = getSmartMeter($sys['ip_verteiler_haus']);
$v_bbh  = getSmartMeter($sys['ip_verteiler_bbh']);

// 3. Lecture et ajustement des données DRS (RAM)
$drs_file = $sys['drs_data_file'];
$drs_data = [];
$drs_warning = false;

if (file_exists($drs_file)) {
    $drs_data = json_decode(@file_get_contents($drs_file), true);
    
    // --- LOGIQUE DE SOUSTRACTION ---
    // On récupère et cache la conso du moniteur (192.168.0.64)
    $p_bildschirm = getSmartMeter('192.168.0.64');
    $tas_cache['192.168.0.64'] = $p_bildschirm;

    // Soustraction de la mesure globale DRS pour éviter le doublon
    if (isset($drs_data['PC Raf + TV'])) {
        $drs_data['PC Raf + TV'] = max(0, $drs_data['PC Raf + TV'] - $p_bildschirm);
    }
    // -------------------------------

    if ((time() - filemtime($drs_file)) > 7200) { $drs_warning = true; }
} else {
    $drs_warning = true;
}

// 4. Solaire
//solar_data = json_decode(@file_get_contents($sys['solar_data_file']), true);
//$solar_watt = $solar_data['power'] ?? 0;

// 4. Solaire (Lecture directe depuis le Tasmota .63)
$solar_watt = getSolarPower($sys['ip_solar_tasmota']);

// 5. Calcul de la somme des pièces (Tasmota + DRS)
$sum_rooms_consumption = 0;
foreach ($rooms as $name => $data) {
    if ($name == 'System') continue;
    
    // Somme Tasmota (Prises et Light_P)
    if (!empty($data['devices'])) {
        foreach ($data['devices'] as $dev) {
            $parts = explode('|', $dev);
            if (count($parts) < 4) continue;
            list($type, $ip, $relay, $label) = $parts;

            if ($type == 'socket' || $type == 'light_p') { 
                if (!isset($tas_cache[$ip])) { $tas_cache[$ip] = getSmartMeter($ip); }
                $sum_rooms_consumption += $tas_cache[$ip]; 
            }
        }
    }
    
    // Somme DRS
    if (!empty($data['drs_keys'])) {
        foreach ($data['drs_keys'] as $drs_entry) {
            $parts_drs = explode('|', $drs_entry);
            if (count($parts_drs) < 2) continue;
            list($key, $label) = $parts_drs;
            $sum_rooms_consumption += ($drs_data[$key] ?? 0);
        }
    }
}

// 6. Calculs Globaux
$gesamt_conso = $v_haus + $solar_watt;
$abdeckung = ($gesamt_conso > 0) ? min(round(($sum_rooms_consumption / $gesamt_conso) * 100), 100) : 0;

// Autarkie: Relation entre Solaire et Gesamtverbrauch
$autarkie = ($gesamt_conso > 0) ? min(round(($solar_watt / $v_haus) * 100), 100) : 0;

$netzbezug = max(0, $gesamt_conso - $solar_watt);
$netzeinspeisung = max(0, $solar_watt - $gesamt_conso);
?>

<div class="container">
    <div class="room-card" style="margin-bottom: 20px; padding: 20px;">
        <div style="display: flex; flex-direction: column; align-items: center; text-align: center; margin-bottom: 20px;">
            <div style="font-size: 0.55rem; font-weight: 900; color: #555; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 5px;">Gesamtverbrauch</div>
                <div style="font-size: 2.8rem; font-weight: 900; line-height: 1;"><?= $gesamt_conso ?> <span style="font-size: 1rem; color: #444;">Watt</span></div>
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
        <?php foreach ($rooms as $name => $data): 
            if ($name == 'System') continue;

            $dev_list = [];
            $room_total = 0;

            // 1. Collecte Tasmota
            if (!empty($data['devices'])) {
                foreach ($data['devices'] as $dev) {
                    $parts = explode('|', $dev);
                    if (count($parts) < 4) continue;
                    list($type, $ip, $relay, $label) = $parts;

                    if ($type == 'socket' || $type == 'light_p') {
                        if (!isset($tas_cache[$ip])) { $tas_cache[$ip] = getSmartMeter($ip); }
                        $p = $tas_cache[$ip];
                        $room_total += $p;
                        $dev_list[] = ['label' => $label, 'power' => $p, 'source' => 'tas'];
                    }
                }
            }

            // 2. Collecte DRS
            if (!empty($data['drs_keys'])) {
                foreach ($data['drs_keys'] as $drs_entry) {
                    $parts_drs = explode('|', $drs_entry);
                    if (count($parts_drs) < 2) continue;
                    list($key, $label) = $parts_drs;
                    $p = $drs_data[$key] ?? 0;
                    $room_total += $p;
                    $dev_list[] = ['label' => $label, 'power' => $p, 'source' => 'drs'];
                }
            }

            if (empty($dev_list)) continue;

            $card_style = ($name == "Arbeitszimmer" && $drs_warning) ? "border: 2px solid var(--red); box-shadow: 0 0 15px rgba(231, 76, 60, 0.4);" : "";
        ?>
            <div class="room-card" style="<?= $card_style ?>">
                <div class="room-head">
                    <div class="room-title">
                        <span><?= $data['icon'] ?></span> <?= strtoupper($name) ?>
                        <?php if ($name == "Arbeitszimmer" && $drs_warning): ?>
                            <small style="color: var(--red); font-size: 0.5rem; margin-left: 10px;">⚠️ RPIA Offline</small>
                        <?php endif; ?>
                    </div>
                    <span class="badge badge-blue">⚡ <?= round($room_total) ?> W</span>
                </div>
                <div class="room-body" style="padding: 10px 20px;">
                    <?php foreach ($dev_list as $d): ?>
                        <div class="dev-row" style="display: flex; justify-content: space-between; padding: 5px 0;">
                            <span class="dev-name" style="font-size: 0.85rem; color: #ccc;"><?= $d['label'] ?></span>
                            <span style="font-weight: 900; color: #eee; <?= ($d['source'] == 'drs' && $drs_warning) ? 'color: var(--red);' : '' ?>">
                                <?= round($d['power']) ?> <small style="color:#444; font-size: 0.6rem;">W</small>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include("footer.php"); ?>