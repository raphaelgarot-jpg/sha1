<?php

// 2. LOGIQUE MULTI-APPAREILS
$sub_file = 'config/devices.json';
$raw_data = file_get_contents('php://input');
$new_sub = json_decode($raw_data, true);

if ($new_sub && isset($new_sub['endpoint'])) {
    $current_subs = [];

    // Charger l'existant
    if (file_exists($sub_file)) {
        $json_content = json_decode(file_get_contents($sub_file), true);
        
        // Gestion de la migration : si c'est un objet seul, on le met en tableau
        if (is_array($json_content)) {
            $current_subs = isset($json_content['endpoint']) ? [$json_content] : $json_content;
        }
    }

    // Vérifier si l'appareil (endpoint) est déjà dans la liste
    $exists = false;
    foreach ($current_subs as $s) {
        if ($s['endpoint'] === $new_sub['endpoint']) {
            $exists = true;
            break;
        }
    }

    // Ajouter si c'est un nouveau device
    if (!$exists) {
        $current_subs[] = $new_sub;
        if (file_put_contents($sub_file, json_encode($current_subs, JSON_PRETTY_PRINT))) {
            echo json_encode(["status" => "ok", "message" => "Nouvel appareil enregistré !"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Impossible d'écrire dans devices.json"]);
        }
    } else {
        echo json_encode(["status" => "ok", "message" => "Appareil déjà connu."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Données invalides"]);
}
