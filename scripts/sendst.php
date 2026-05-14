<?php
$ip = $_SERVER["REMOTE_ADDR"];
$ipexpl = explode(".", $ip);
$master = $_GET['master'];
$slave = $_GET['slave'];
$state = $_GET['state'];
// On vérifie si c'est un appel AJAX
$ajax = isset($_GET['ajax']);

if ($ipexpl[0] == 192 && $ipexpl[1] == 168) {
    if ($master == "all") {
        // Exécution en arrière-plan (shell_exec avec '&') pour ne pas bloquer PHP
        shell_exec("./senditgw433.py D 2 " . $state . " > /dev/null 2>&1 &");
        shell_exec("sleep 1 && ./senditgw433.py D 3 " . $state . " > /dev/null 2>&1 &");
        shell_exec("sleep 2 && ./senditgw433.py D 4 " . $state . " > /dev/null 2>&1 &");
    } else {
        exec("./senditgw433.py " . escapeshellarg($master) . " " . escapeshellarg($slave) . " " . escapeshellarg($state));
    }
}

// Si c'est AJAX, on répond en JSON et on arrête le script ici
if ($ajax) {
    header('Content-Type: application/json');
    echo json_encode(["status" => "success"]);
    exit;
}

// Sinon, redirection classique (pour la compatibilité)
$return = $_GET['return'] ?? 'index';
header("Location: $return.php");
?>
