<?php
ini_set('display_errors', 0);
error_reporting(0);

$ip = $_SERVER["REMOTE_ADDR"] ?? '';
$ipexpl = explode(".", $ip);

$master = $_GET['master'] ?? '';
$slave = $_GET['slave'] ?? '';
$state = $_GET['state'] ?? '';
$ajax = isset($_GET['ajax']);

// 🟩 SÉCURITÉ : Whitelist stricte sur l'état pour bloquer toute injection Shell
$allowed_states = ['on', 'off'];
if (!in_array(strtolower($state), $allowed_states, true)) {
    $state = 'off';
}

if ($ipexpl[0] == 172 && $ipexpl[1] == 18) {
    $safe_state = escapeshellarg($state);
    if ($master === "all") {
        // 🟩 CORRECTION : Échappement obligatoire de $state dans shell_exec
        shell_exec("./senditgw433.py D 2 " . $safe_state . " > /dev/null 2>&1 &");
        shell_exec("sleep 1 && ./senditgw433.py D 3 " . $safe_state . " > /dev/null 2>&1 &");
        shell_exec("sleep 2 && ./senditgw433.py D 4 " . $safe_state . " > /dev/null 2>&1 &");
    } else {
        exec(__DIR__ . "/senditgw433.py " . escapeshellarg($master) . " " . escapeshellarg($slave) . " " . $safe_state);
    }
}

if ($ajax) {
    header('Content-Type: application/json');
    echo json_encode(["status" => "success"]);
    exit;
}

$return = $_GET['return'] ?? 'index';
// 🟩 SÉCURITÉ : Sanitization de l'en-tête de redirection
$clean_return = preg_replace('/[^a-zA-Z0-9_-]/', '', $return);
header("Location: {$clean_return}.php");
exit;