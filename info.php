<?php
/**
 * S.H.A. 2026 - Global Architecture Status
 * Fichier : /var/www/html/sha/info.php
 */
include("header.php");

// 1. Extraction des configurations globales via le cache RAM existant
$sys = $rooms['System'] ?? [];
$live_data = get_sha_live_cache();
$tas_cache = $live_data['devices'] ?? [];

// 2. Diagnostics Système Local
$os_version = php_uname('s') . ' ' . php_uname('r');
$uptime = @shell_exec('uptime -p') ?? 'Indisponible';

// État du cache volatile RAM
$cache_file = '/dev/shm/sha_live.json';
$cache_info = "Inactif";
$cache_class = "off";
if (file_exists($cache_file) && filesize($cache_file) > 0) {
    $cache_info = "Opérationnel (" . round(filesize($cache_file) / 1024, 1) . " Ko)";
    $cache_class = "on";
}

// 3. Analyse du statut des Backups
$backup_log = __DIR__ . '/logs/backup.log';
$last_backup_line = "Aucun historique de sauvegarde trouvé.";
$backup_status_class = "off";

if (file_exists($backup_log)) {
    $log_lines = file($backup_log);
    if (!empty($log_lines)) {
        $last_backup_line = trim(end($log_lines));
        if (strpos($last_backup_line, 'terminé') !== false) {
            $backup_status_class = "on";
        }
    }
}
?>

<div class="container">
    <div class="sha-main-grid">
        
        <div class="room-card">
            <div class="room-head">
                <div class="room-title"><span>🖥️</span> SERVEURS S.H.A.</div>
                <span class="badge badge-blue">INFRA</span>
            </div>
            <div class="room-body flex-column" style="padding: 10px 0;">
                <?php $srv1 = get_device_architecture_status($sys['ip_Serveur_1'] ?? '', $tas_cache); ?>
                <div class="dev-row <?= $srv1['class'] ?>">
                    <span class="dev-name">Serveur Principal (SHA-1)</span>
                    <span style="font-weight: 700; color: #3498db; font-size: 0.85rem;"><?= $sys['ip_Serveur_1'] ?? 'N/A' ?></span>
                </div>
                <div class="dev-row <?= $srv1['class'] ?>">
                    <span class="dev-name">Statut S.H.A. Core</span>
                    <?= $srv1['html'] ?>
                </div>

                <?php $srv2 = get_device_architecture_status($sys['ip_Serveur_2'] ?? '', $tas_cache); ?>
                <div class="dev-row <?= $srv2['class'] ?>">
                    <span class="dev-name">Serveur Stockage (NAS)</span>
                    <span style="font-weight: 700; color: #eee; font-size: 0.85rem;"><?= $sys['ip_Serveur_2'] ?? 'N/A' ?></span>
                </div>
                <div class="dev-row <?= $srv2['class'] ?>">
                    <span class="dev-name">Statut Réseau NAS</span>
                    <?= $srv2['html'] ?>
                </div>
            </div>
        </div>

        <div class="room-card">
            <div class="room-head">
                <div class="room-title"><span>🌐</span> MAILLAGE ROUTEURS</div>
                <span class="badge badge-blue">OPENWRT</span>
            </div>
            <div class="room-body flex-column" style="padding: 10px 0;">
                <?php for($i = 1; $i <= 4; $i++):
                    $router_ip = $sys["ip_routeur_$i"] ?? null;
                    $status = get_device_architecture_status($router_ip, $tas_cache);
                ?>
                <div class="dev-row <?= $status['class'] ?>">
                    <span class="dev-name">Routeur #<?= $i ?> <small style="color:#555; font-size:0.7rem;">(<?= $router_ip ?? 'Non configuré' ?>)</small></span>
                    <?= $status['html'] ?>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <div class="room-card">
            <div class="room-head">
                <div class="room-title"><span>⚙️</span> SYSTÈME ET REPLI</div>
                <span class="badge badge-blue">KERNEL</span>
            </div>
            <div class="room-body flex-column" style="padding: 10px 0;">
                <div class="dev-row">
                    <span class="dev-name">OS Node</span>
                    <span style="font-weight: 700; color: #aaa; font-size: 0.85rem;"><?= htmlspecialchars($os_version) ?></span>
                </div>
                <div class="dev-row">
                    <span class="dev-name">Cache RAM Data</span>
                    <span class="status-text <?= $cache_class ?>" style="font-weight: 700; font-size:0.85rem;"><?= $cache_info ?></span>
                </div>
                <div class="dev-row">
                    <span class="dev-name">Sauvegardes (Log)</span>
                    <span class="status-text <?= $backup_status_class ?>" style="font-size: 0.75rem; font-weight: 700; text-align: right; max-width: 55%; word-break: break-all;">
                        <?= htmlspecialchars($last_backup_line) ?>
                    </span>
                </div>
            </div>
        </div>

    </div>
</div>

<?php 
include("footer.php");
?>