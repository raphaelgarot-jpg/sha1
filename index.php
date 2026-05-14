<?php
error_reporting(0);
ini_set('display_errors', 0);


// --- INCLUSION DES MODULES ---
include("header.php");
include("menu.php");


?>

<div class="nav-tiles">
    
    <a href="steckdose.php" class="tile"><span class="tile-icon">🔌</span><span class="tile-label">Dosen</span></a>
    <a href="rolladen.php" class="tile"><span class="tile-icon">🪟</span><span class="tile-label">Rollladen</span></a>
    <a href="weather.php"  class="tile"><span class="tile-icon">☁️</span><span class="tile-label">Wetter</span></a>
    <a href="heiz.php"     class="tile"><span class="tile-icon">🔥</span><span class="tile-label">Heizung</span></a>
    <a href="strom.php" class="tile"><span class="tile-icon">⚡</span><span class="tile-label">Strom</span></a>
    <a href="router.php"   class="tile"><span class="tile-icon">🌐</span><span class="tile-label">Netzwerk</span></a>
    <a href="info.php"     class="tile"><span class="tile-icon">ℹ️</span><span class="tile-label">Info</span></a>
</div>

<div class="container">
    <div style="margin-bottom: 30px;">
        <h2 style="font-size: 0.8rem; letter-spacing: 2px; color: #444; text-transform: uppercase;">Tableau de bord / Pièces</h2>
    </div>

    <div class="room-grid">
        <?php foreach ($rooms as $name => $data): 
            // Déterminer si la carte doit être large (ex: Bureau)
            $is_wide = (isset($data['wide_display']) && $data['wide_display']) ? 'wide' : '';
            
            // Simulation des données (en attendant le branchement réel)
            $temp = "--"; 
            $hum = "--";
        ?>
            <div class="room-card <?= $is_wide ?>">
                <div class="room-head">
                    <div class="room-title">
                        <span style="margin-right: 10px;"><?= $data['icon'] ?? '🏠' ?></span> 
                        <?= strtoupper($name) ?>
                    </div>
                    
                    <div class="room-badges">
                        <span class="badge badge-green">🌡️ <?= $temp ?>°</span>
                    </div>
                </div>

                <div class="room-body" style="padding: 20px; min-height: 100px; display: flex; align-items: center; justify-content: center;">
                    <div style="text-align: center; color: #333;">
                        <div style="font-size: 0.6rem; font-weight: 900; text-transform: uppercase; letter-spacing: 1px;">
                            <?php 
                                if (!empty($data['devices'])) {
                                    echo count($data['devices']) . " Appareils configurés";
                                } else {
                                    echo "Aucun appareil";
                                }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include("footer.php"); ?>