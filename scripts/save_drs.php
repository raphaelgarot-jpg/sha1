<?php
// ... reste du code (file_put_contents, etc.)
// On autorise uniquement les requêtes POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = file_get_contents('php://input');
    if ($data) {
        // On écrit directement dans le fichier que strom.php lit
        file_put_contents('../data/drs_data.json', $data);
        echo json_encode(["status" => "ok"]);
    }
}
?>
