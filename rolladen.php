<?php
include("header.php");

// --- FILTRAGE PIÈCES ---
$shutter_rooms = [];
foreach ($rooms as $name => $data) {
    if ($name == 'System') continue;
    if (isset($data['shutter']) || !empty($data['devices'])) {
        $has_shutter = isset($data['shutter']);
        if (!$has_shutter && !empty($data['devices'])) {
            foreach ($data['devices'] as $dev) {
                if (strpos($dev, 'shutter|') !== false) { $has_shutter = true; break; }
            }
        }
        if ($has_shutter) $shutter_rooms[$name] = $data;
    }
}
?>

<div class="container">

    <?php if (!empty($shutter_rooms)): ?>
    <div class="room-card" style="margin-bottom: 25px; border: 1px solid #ff980066; padding: 20px; display: flex; flex-direction: column; gap: 15px;">
        <div class="room-title" style="justify-content: center; color: #ff9800;"><span>🏠</span> ZENTRALSTEUERUNG</div>
        <div style="display: flex; gap: 10px; justify-content: center;">
            <button onclick="sendRoll('all', '1', 'off', this)" class="btn-sw btn-on" style="min-width: 80px; text-align: center;">▲ AUF</button>
            <button onclick="sendRoll('all', '1', 'on', this)" class="btn-sw btn-off" style="min-width: 80px; text-align: center;">▼ ZU</button>
        </div>
    </div>
    <?php endif; ?>

    <div class="room-grid">
        <?php foreach ($shutter_rooms as $name => $data):
            $shutters = isset($data['shutter']) ? (is_array($data['shutter']) ? $data['shutter'] : [$data['shutter']]) : [];
            if (!empty($data['devices'])) {
                foreach ($data['devices'] as $dev) {
                    if (strpos($dev, 'shutter|') !== false) {
                        $parts = explode('|', $dev);
                        $shutters[] = $parts[1] . '|' . $parts[2] . '|' . ($parts[3] ?? 'Rollo');
                    }
                }
            }
            ?>
            <div class="room-card">
                <div class="room-head">
                    <div class="room-title">
                        <span><?= $data['icon'] ?? '🪟' ?></span> <?= strtoupper($name) ?>
                    </div>
                </div>
                <div class="room-body" style="padding: 10px 0;">
                    <?php foreach ($shutters as $shutter):
                        $parts = explode('|', $shutter);
                        if(count($parts) < 2) continue;
                        $ip = $parts[0]; $relay = $parts[1]; $label = $parts[2] ?? 'Rollo';
                    ?>
                        <div style="padding: 10px 20px; display: flex; align-items: center; justify-content: space-between; <?= $shutter !== end($shutters) ? 'border-bottom: 1px solid #1a1a1a;' : '' ?>">
                            <div style="font-weight: 900; font-size: 0.85rem; padding-right: 15px;"><?= $label ?></div>
                            <div style="display: flex; gap: 10px;">
                                <button onclick="sendRoll('<?= $ip ?>', '<?= $relay ?>', 'off', this)" class="btn-sw btn-on" style="min-width: 80px; text-align: center;">▲ AUF</button>
                                <button onclick="sendRoll('<?= $ip ?>', '<?= $relay ?>', 'on', this)" class="btn-sw btn-off" style="min-width: 80px; text-align: center;">▼ ZU</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>


<?php include("footer.php"); ?>