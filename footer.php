</main> 
<?php
// Lecture du fichier de version généré par les hooks Git
$version_path = __DIR__ . '/data/version.txt'; 
$display_version = "V0.6.x"; // Fallback de secours

if (file_exists($version_path)) {
    $content = trim(file_get_contents($version_path));
    if (!empty($content)) {
        $display_version = htmlspecialchars($content);
    }
}
?>

<footer class="sha-footer">
    <div class="footer-container">
        <div class="footer-left">
            S.H.A. 🐾 <span>2026</span> 
        </div>
        
        <div class="footer-right">
            <span class="status-item">⚙️ <?php echo $display_version; ?></span>
            <span class="status-item">🕒 <?php echo date('H:i'); ?></span>
        </div>
    </div>
</footer>
</body>
</html>