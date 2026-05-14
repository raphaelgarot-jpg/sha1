<?php
/**
 * SHA 2026 - Fonctions Core
 */

/* function getSmartMeter($ip) {
    if (empty($ip)) return 0;
    
    $ctx = stream_context_create(['http' => ['timeout' => 1.5]]);
    $raw = @file_get_contents("http://$ip/cm?cmnd=Status%208", false, $ctx);
    
    if (!$raw) return 0;
    $json = json_decode($raw, true);
    $sns = $json['StatusSNS'] ?? [];

    // Ordre de priorité des capteurs
    if (isset($sns['ENERGY']['Power'])) return $sns['ENERGY']['Power'];    // Ta lampe Gaming
    if (isset($sns['GS303']['Power_cur'])) return $sns['GS303']['Power_cur']; // Ton Verteiler
    if (isset($sns['SML']['Power_cur'])) return $sns['SML']['Power_cur'];     // Autre compteur
    
    return 0;
} */

function getSmartMeter($ip) {
    if (empty($ip)) return 0;
    
    $ctx = stream_context_create(['http' => ['timeout' => 1.5]]);
    
    // 1. Tentative format Tasmota (Status 8)
    $raw_tasmota = @file_get_contents("http://$ip/cm?cmnd=Status%208", false, $ctx);
    if ($raw_tasmota) {
        $json = json_decode($raw_tasmota, true);
        $sns = $json['StatusSNS'] ?? [];
        if (isset($sns['ENERGY']['Power'])) return $sns['ENERGY']['Power'];
        if (isset($sns['GS303']['Power_cur'])) return $sns['GS303']['Power_cur'];
    }

    // 2. Tentative format Shelly Gen2/Gen3 (RPC)
    // On utilise 'Shelly.GetStatus' qui est le point d'entrée global pour tous les modèles
    $raw_shelly = @file_get_contents("http://$ip/rpc/Shelly.GetStatus", false, $ctx);
    if ($raw_shelly) {
        $json = json_decode($raw_shelly, true);
        
        // Cas A : C'est une prise ou un relais (Plus 1PM, Plug S Gen3)
        if (isset($json['switch:0']['apower'])) {
            return $json['switch:0']['apower'];
        }
        
        // Cas B : C'est un module de mesure seule (PM Mini Gen3)
        if (isset($json['pm1:0']['apower'])) {
            return $json['pm1:0']['apower'];
        }
    }
    
    return 0;
}

function getTasmotaState($ip, $relay = 1) {
    $ctx = stream_context_create(['http' => ['timeout' => 1.0]]);
    $raw = @file_get_contents("http://$ip/cm?cmnd=State", false, $ctx);
    if ($raw) {
        $json = json_decode($raw, true);
        $powerKey = ($relay > 1) ? "POWER$relay" : "POWER";
        return $json[$powerKey] ?? 'OFF';
    }
    return 'OFF';
}

/**
 * Calcule la puissance corrigée du solaire (Apparente)
 * Formule : Active Power / Power Factor
 */
function getSolarPower($ip) {
    if (empty($ip)) return 0;
    
    $ctx = stream_context_create(['http' => ['timeout' => 1.5]]);
    $raw = @file_get_contents("http://$ip/cm?cmnd=Status%208", false, $ctx);
    
    if (!$raw) return 0;
    $json = json_decode($raw, true);
    
    // On cible spécifiquement les données ENERGY de Tasmota
    $energy = $json['StatusSNS']['ENERGY'] ?? null;
    
    if ($energy) {
        $p_active = $energy['Power'] ?? 0;
        $factor = $energy['Factor'] ?? 1;
        
        // Sécurité pour éviter la division par zéro
        if ($factor > 0) {
            return round($p_active / $factor);
        }
        return $p_active;
    }
    return 0;
}
?>