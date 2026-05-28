<?php
// 🔒 SÉCURISATION DE LA PRODUCTION (Fin de la phase de débogage MQTT)
error_reporting(0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

include("menu.php");
include("core/functions.php");

$config_path = 'config/home_structure.conf';
if (!file_exists($config_path)) {
    die("Erreur : Fichier de configuration introuvable dans $config_path");
}
$rooms = parse_ini_file($config_path, true);

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="S.H.A. 2026">
    <link rel="apple-touch-icon" href="/sha/assets/img/favicon.png">
    <link rel="apple-touch-icon-precomposed" href="/sha/assets/img/favicon.png">
    <link rel="manifest" href="/sha/manifest.json">
    <title>S.H.A. 2026</title>

    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">

    <script src="core/functions.js"></script>
    <script>
        // --- iOS REFRESH ON RESUME ---
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;

        if (isIOS) {
            document.addEventListener("visibilitychange", () => {
                if (document.visibilityState === "visible") {
                    window.location.reload();
                }
            });
        }

        // Nettoyage de la pastille rouge
        document.addEventListener("visibilitychange", () => {
            if (document.visibilityState === "visible" && typeof clearBadge === 'function') {
                clearBadge();
            }
        });

        // --- VERROU ABSOLU PLEIN ÉCRAN IOS ---
        if (("standalone" in window.navigator) && window.navigator.standalone) {
            document.addEventListener("click", function(e) {
                let element = e.target;
                while (element && element.nodeName !== "A") {
                    element = element.parentNode;
                }
                if (element && element.href) {
                    if (element.href.indexOf(location.hostname) !== -1) {
                        e.preventDefault();
                        window.location.href = element.href;
                    }
                }
            }, false);
        }
    </script>
</head>
<body>

<header class="sha-header">
    <div style="width: 35px;"></div>
    <div class="header-title">S.H.A. 2026</div>
    <div class="hamburger-btn" onclick="toggleSHA()">
        <span></span><span></span><span></span>
    </div>
</header>

<main class="container">