Markdown

# 🛠️ S.H.A. - Smart Home Architecture (System Setup)

Ce document liste toutes les configurations système nécessaires au bon fonctionnement de S.H.A. qui **ne sont pas** incluses dans le dépôt Git (car externes au dossier `/var/www/html/sha` ou liées à l'OS).

---

## 1. 🚀 Service Worker (Cache RAM Tasmota)

Pour garantir un affichage instantané du dashboard, un script Python (`scripts/sha_cache_builder.py`) tourne en boucle pour interroger les appareils et écrire les résultats en RAM (`/dev/shm/`).

### Création du service système (Daemon)
Créer le fichier de service :
```bash
sudo nano /etc/systemd/system/sha-worker.service

Contenu du fichier :
Ini, TOML

[Unit]
Description=S.H.A. 2026 - Tasmota Cache Worker
After=network.target

[Service]
ExecStart=/usr/bin/python3 /var/www/html/sha/scripts/sha_cache_builder.py
WorkingDirectory=/var/www/html/sha/
StandardOutput=null
StandardError=journal
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target

Activation du service
Bash

sudo systemctl daemon-reload
sudo systemctl enable sha-worker.service
sudo systemctl start sha-worker.service

2. 📁 Lien Symbolique RAM -> Data

Le script Python écrit les données en direct dans la mémoire vive (/dev/shm/) pour préserver la carte SD. PHP lit ces données via un lien symbolique dans le dossier data/.

Commande pour recréer le lien (à faire après un formatage) :
Bash

ln -s /dev/shm/sha_live.json /var/www/html/sha/data/sha_live.json

(Note : Ce lien symbolique est ignoré par Git via le .gitignore car il pointe vers l'extérieur du projet).
3. 🛡️ Fichiers Ignorés (.gitignore)

Les fichiers suivants sont intentionnellement exclus du dépôt GitHub pour des raisons de sécurité ou de volatilité. Ils doivent être recréés manuellement lors d'une nouvelle installation :

    config/app.conf : Contient les tokens API sensibles (Netatmo, etc.).

    data/sha_live.json : Lien symbolique vers la RAM (voir point 2).

    data/version.txt : Géré automatiquement par les hooks Git locaux.

4. 🪝 Hooks Git (Versionnage automatique)

Pour que la version s'incrémente automatiquement en allemand lors d'un commit local, le hook pre-commit doit être initialisé.
(Les hooks ne sont pas poussés sur GitHub, ils restent en local).

Créer le fichier :
Bash

nano /var/www/html/sha/.git/hooks/pre-commit
chmod +x /var/www/html/sha/.git/hooks/pre-commit


____



Documentation Technique : Module d'Audit & Worker Live (S.H.A.)

Cette documentation décrit les composants logiciels permettant de scanner, d'auditer la sécurité, de rafraîchir et de suivre en temps réel la consommation électrique du parc d'objets connectés (Tasmota et Shelly) sur le réseau S.H.A.
🛠️ 1. Architecture Réseau & Sécurité Cible

Pour éviter les effets domino (boucles d'allumage/extinction intempestives via protocole réseau direct DGR), l'ensemble du parc Tasmota a été isolé chirurgicalement :

    GroupTopic : Transféré du groupe générique tasmotas vers tasmota_solo.

    DevGroupShare : Configuré sur In: 0 / Out: 0 (0,0). Les appareils sont structurellement sourds et muets vis-à-vis des communications Wi-Fi directes.

    Protocole d'Échange : Unique et centralisé via le Broker MQTT (Mosquitto) sur le port standard non chiffré 1883 avec authentification par identifiants robustes. (Le chiffrement TLS/SSL a été explicitement écarté pour préserver le CPU des puces ESP8266).

🔍 2. Interface de Contrôle et d'Audit (index.php)

Fichier : /var/www/html/testtasmo/index.php

Ce script remplit deux rôles : il affiche un tableau de bord de l'état du parc à partir du cache local et gère deux actions asynchrones (AJAX) optimisées pour ne pas saturer le routeur Wi-Fi :

    Scan Complet : Interroge l'intégralité du sous-réseau (/24) par lots de 20 adresses IP avec un temps d'attente maximum (timeout) de 8 secondes pour ne rater aucune prise distante ou en veille économique.

    Actualisation Rapide : Lit le fichier JSON en cache et n'interroge que les adresses IP connues en moins de deux secondes.

Code Source de l'Interface
PHP

<?php
// --- SÉCURITÉ ET TEMPS D'EXÉCUTION ---
set_time_limit(240);          
ini_set('display_errors', 0); 

// --- CONFIGURATION ---
$subnet = '192.168.0.'; 
$json_file = 'tasmotas_audit.json';

// --- FONCTION CŒUR : INTERROGER UN LOT D'IPS SPÉCIFIQUES ---
function query_tasmotas($ips) {
    if (empty($ips)) return [];
    
    $tasmotas = [];
    $active_ips = [];
    
    $batch_size = 20; 
    $timeout = 8;    

    // Étape 1 : Récupération du Status 0 en parallèle
    $batches = array_chunk($ips, $batch_size);
    foreach ($batches as $batch) {
        $mh = curl_multi_init();
        $chs = [];
        foreach ($batch as $ip) {
            $chs[$ip] = curl_init("http://$ip/cm?cmnd=Status%200");
            curl_setopt($chs[$ip], CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chs[$ip], CURLOPT_TIMEOUT, $timeout); 
            curl_multi_add_handle($mh, $chs[$ip]);
        }
        $active = null;
        do { curl_multi_exec($mh, $active); } while ($active);

        foreach ($chs as $ip => $ch) {
            $res = curl_multi_getcontent($ch);
            if ($res) {
                $data = json_decode($res, true);
                if (is_array($data) && isset($data['Status'])) {
                    $active_ips[] = $ip;
                    $sns = $data['StatusSNS'] ?? [];
                    $power = 0;
                    if (isset($sns['GS303']['Power_cur'])) { $power = $sns['GS303']['Power_cur']; }
                    elseif (isset($sns['ENERGY']['Power'])) { $power = $sns['ENERGY']['Power']; }

                    $grouptopic = 'N/A';
                    if (isset($data['StatusPRM']['GroupTopic'])) { $grouptopic = $data['StatusPRM']['GroupTopic']; }
                    elseif (isset($data['StatusMQT']['GroupTopic'])) { $grouptopic = $data['StatusMQT']['GroupTopic']; }

                    $tasmotas[$ip] = [
                        'ip' => $ip,
                        'name' => $data['Status']['DeviceName'] ?? 'Inconnu',
                        'version' => $data['StatusFWR']['Version'] ?? 'Inconnue',
                        'mqtt_status' => (($data['StatusSTS']['MqttCount'] ?? 0) > 0) ? "Connecté (" . ($data['StatusMQT']['MqttHost'] ?? '?') . ")" : "Déconnecté",
                        'teleperiod' => $data['StatusLOG']['TelePeriod'] ?? 'N/A',
                        'state' => $data['StatusSTS']['POWER'] ?? 'N/A',
                        'power' => $power,
                        'devgroupshare' => 'Inconnu',
                        'dgr_in' => '1' 
                    ];
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }

    // Étape 2 : Récupération du DevGroupShare
    if (!empty($active_ips)) {
        $batches_active = array_chunk($active_ips, $batch_size);
        foreach ($batches_active as $batch) {
            $mh2 = curl_multi_init();
            $chs2 = [];
            foreach ($batch as $ip) {
                $chs2[$ip] = curl_init("http://$ip/cm?cmnd=DevGroupShare");
                curl_setopt($chs2[$ip], CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chs2[$ip], CURLOPT_TIMEOUT, $timeout);
                curl_multi_add_handle($mh2, $chs2[$ip]);
            }
            do { curl_multi_exec($mh2, $active); } while ($active);

            foreach ($chs2 as $ip => $ch) {
                $res = curl_multi_getcontent($ch);
                if ($res) {
                    $data = json_decode($res, true);
                    if (is_array($data) && isset($data['DevGroupShare'])) {
                        if (is_array($data['DevGroupShare'])) {
                            $tasmotas[$ip]['devgroupshare'] = "In: " . ($data['DevGroupShare']['In'] ?? '?') . " / Out: " . ($data['DevGroupShare']['Out'] ?? '?');
                            $tasmotas[$ip]['dgr_in'] = (string)($data['DevGroupShare']['In'] ?? '1');
                        } else {
                            $tasmotas[$ip]['devgroupshare'] = $data['DevGroupShare'];
                            $tasmotas[$ip]['dgr_in'] = (string)$data['DevGroupShare'];
                        }
                    }
                }
                curl_multi_remove_handle($mh2, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh2);
        }
    }
    return $tasmotas;
}

// --- LOGIQUE DES ACTIONS AJAX ---
if (isset($_GET['action'])) {
    $tasmotas = [];
    
    if ($_GET['action'] == 'scan') {
        $ips = [];
        for ($i = 1; $i <= 254; $i++) { $ips[] = $subnet . $i; }
        $tasmotas = query_tasmotas($ips);
    } 
    elseif ($_GET['action'] == 'refresh') {
        if (file_exists($json_file)) {
            $cached = json_decode(file_get_contents($json_file), true) ?? [];
            $ips = array_keys($cached);
            $tasmotas = query_tasmotas($ips);
        }
    }

    file_put_contents($json_file, json_encode($tasmotas, JSON_PRETTY_PRINT));
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'count' => count($tasmotas)]);
    exit;
}

// --- LECTURE DU FICHIER JSON POUR L'AFFICHAGE ---
$saved_devices = [];
$last_scan = "Jamais";
if (file_exists($json_file)) {
    $saved_devices = json_decode(file_get_contents($json_file), true) ?? [];
    $last_scan = date("d/m/Y H:i:s", filemtime($json_file));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Audit S.H.A. Tasmota</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #1a1a1a; color: #eee; margin: 0; padding: 20px; }
        h1 { text-align: center; color: #3498db; }
        .controls { text-align: center; margin-bottom: 30px; }
        button { color: white; border: none; padding: 12px 24px; font-size: 1.1rem; cursor: pointer; border-radius: 5px; font-weight: bold; margin: 5px; transition: background 0.2s; }
        .btn-scan { background-color: #e74c3c; }
        .btn-scan:hover { background-color: #c0392b; }
        .btn-refresh { background-color: #d35400; }
        .btn-refresh:hover { background-color: #b33921; }
        button:disabled { background-color: #555 !important; cursor: not-allowed; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 15px; }
        .card { background-color: #2c3e50; padding: 15px; border-radius: 8px; border-left: 5px solid #3498db; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        .card h3 { margin: 0 0 10px 0; color: #ecf0f1; border-bottom: 1px solid #444; padding-bottom: 5px; font-size: 1.1rem; }
        .data-row { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 0.9rem; }
        .label { color: #bdc3c7; }
        .val { font-weight: bold; color: #fff; }
        .val.danger { color: #e74c3c; }
        .val.safe { color: #2ecc71; }
        .val.info { color: #9b59b6; }
        .val.warning { color: #e67e22; }
        #loader { display: none; margin-top: 10px; color: #f39c12; font-weight: bold; }
    </style>
</head>
<body>

    <h1>🔍 Audit Réseau Tasmota</h1>
    
    <div class="controls">
        <button id="refreshBtn" class="btn-refresh" onclick="runAction('refresh')">⚡ Actualiser les Données</button>
        <button id="scanBtn" class="btn-scan" onclick="runAction('scan')">🔍 Lancer un Scan Complet (/24)</button>
        <div id="loader">⏳ Traitement en cours...</div>
        <p style="font-size: 0.8rem; color: #888;">Dernière mise à jour : <span id="lastScanTime"><?= $last_scan ?></span></p>
    </div>

    <div class="grid">
        <?php if (empty($saved_devices)): ?>
            <p style="text-align:center; width:100%; color:#aaa;">Aucun appareil en cache. Lancez un scan complet.</p>
        <?php else: ?>
            <?php foreach ($saved_devices as $ip => $dev): 
                $is_safe_group = ($dev['grouptopic'] !== 'tasmotas');
                $is_safe_dgr = (isset($dev['dgr_in']) && $dev['dgr_in'] === "0");
                $is_mqtt_ok = (strpos($dev['mqtt_status'], 'Connecté') !== false);
            ?>
                <div class="card">
                    <h3>🔌 <?= htmlspecialchars($dev['name']) ?></h3>
                    <div class="data-row"><span class="label">Adresse IP</span> <span class="val" style="color: #3498db;"><?= $ip ?></span></div>
                    <div class="data-row"><span class="label">Firmware</span> <span class="val info"><?= htmlspecialchars($dev['version']) ?></span></div>
                    
                    <div class="data-row" style="margin-top: 8px; border-top: 1px dashed #444; padding-top: 8px;">
                        <span class="label">État Relais</span> <span class="val"><?= $dev['state'] ?></span>
                    </div>
                    <div class="data-row"><span class="label">Puissance</span> <span class="val" style="color: #f1c40f;"><?= $dev['power'] ?> W</span></div>
                    
                    <div class="data-row" style="margin-top: 8px; border-top: 1px dashed #444; padding-top: 8px;">
                        <span class="label">Statut MQTT</span> 
                        <span class="val <?= $is_mqtt_ok ? 'safe' : 'danger' ?>"><?= htmlspecialchars($dev['mqtt_status']) ?></span>
                    </div>
                    <div class="data-row"><span class="label">TelePeriod</span> <span class="val warning"><?= htmlspecialchars($dev['teleperiod'] ?? 'N/A') ?><?= is_numeric($dev['teleperiod']) ? ' s' : '' ?></span></div>
                    <div class="data-row"><span class="label">GroupTopic</span> <span class="val <?= $is_safe_group ? 'safe' : 'danger' ?>"><?= htmlspecialchars($dev['grouptopic']) ?></span></div>
                    <div class="data-row"><span class="label">DevGroupShare</span> <span class="val <?= $is_safe_dgr ? 'safe' : 'danger' ?>"><?= $dev['devgroupshare'] ?></span></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        function runAction(actionType) {
            const btnScan = document.getElementById('scanBtn');
            const btnRefresh = document.getElementById('refreshBtn');
            const loader = document.getElementById('loader');
            
            btnScan.disabled = true;
            btnRefresh.disabled = true;
            
            if (actionType === 'scan') {
                loader.innerText = "⏳ Scan réseau par paquets douillets de 20... (~30 secondes max)";
            } else {
                loader.innerText = "⏳ Rafraîchissement des prises connues... (~1 à 2 secondes)";
            }
            loader.style.display = 'block';

            fetch('?action=' + actionType)
                .then(response => response.json())
                .then(data => {
                    if(data.status === 'ok') {
                        location.reload(); 
                    } else {
                        alert("Erreur lors du traitement.");
                        btnScan.disabled = false;
                        btnRefresh.disabled = false;
                        loader.style.display = 'none';
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("Erreur réseau.");
                    btnScan.disabled = false;
                    btnRefresh.disabled = false;
                    loader.style.display = 'none';
                });
        }
    </script>
</body>
</html>

🚀 3. Le Worker MQTT Live (sha_worker.py)

Fichier : Exécuté par un service système (ex: sha-worker.service).

Ce script en tâche de fond écoute en continu le trafic du broker MQTT. Il extrait la puissance actuelle (Watts) des modules Tasmota et Shelly (Gen2/Gen3) et compile les données directement dans la mémoire vive (RAM via Shared Memory /dev/shm) pour préserver la carte SD du serveur.
Sécurités implémentées :

    Contrôle structurel des payloads : Évite les plantages sur des payloads en texte brut en vérifiant la présence de l'accolade ouvrante {.

    Validation des adresses IP par Regex strict : Bloque toute tentative d'injection de caractères malveillants dans les variables réseau.

    Garbage Collector (RAM) : Nettoie automatiquement les données des appareils n'ayant pas publié depuis plus de 10 minutes (600s) pour prévenir les fuites de mémoire.

Code Source du Worker Python
Python

#!/usr/bin/env python3
import json
import os
import re
import time
import configparser
import paho.mqtt.client as mqtt

# --- CHEMINS LOGIQUES SHA ---
APP_CONF = "/var/www/html/sha/config/app.conf"
RAM_FILE = "/dev/shm/sha_live.json"
MAX_AGE_SECONDS = 600  # 10 minutes d'inactivité max avant suppression du cache

# Stockage global
cached_devices = {}
topic_to_ip_map = {}

# 1. CHARGEMENT DES CONFIGURATIONS SÉCURISÉES
config = configparser.ConfigParser()
if os.path.exists(APP_CONF):
    config.read(APP_CONF)
    MQTT_HOST = config.get('MQTT', 'host', fallback='127.0.0.1').strip('"')
    MQTT_PORT = int(config.get('MQTT', 'port', fallback='1883'))
    MQTT_USER = config.get('MQTT', 'user', fallback='').strip('"')
    MQTT_PASS = config.get('MQTT', 'password', fallback='').strip('"')
else:
    raise FileNotFoundError("Le fichier config/app.conf is introuvable.")

def clean_expired_devices():
    """Supprime les appareils du cache s'ils n'ont pas publié depuis trop longtemps"""
    global cached_devices
    now = int(time.time())
    expired = [ip for ip, dev in cached_devices.items() if now - dev["last_seen"] > MAX_AGE_SECONDS]
    for ip in expired:
        del cached_devices[ip]

def update_device_cache(ip, power):
    """Met à jour les watts et nettoie les vieux appareils morts"""
    global cached_devices
    cached_devices[ip] = {
        "power": power,
        "last_seen": int(time.time())
    }
    clean_expired_devices() # PROTECTION : Évite la fuite de mémoire RAM
    write_ram_cache()

def write_ram_cache():
    """Écrit le dictionnaire enrichi en RAM de manière atomique"""
    payload = {
        "devices": cached_devices,
        "source": "MQTT Live Stream",
        "updated_at": int(time.time())
    }
    temp_file = RAM_FILE + ".tmp"
    try:
        with open(temp_file, 'w') as f:
            json.dump(payload, f, indent=2)
        os.replace(temp_file, RAM_FILE)
    except Exception as e:
        print(f"⚠️ Erreur écriture RAM cache : {e}")

# 2. CALLBACKS MQTT
def on_connect(client, userdata, flags, rc):
    if rc == 0:
        print("✅ Connecté au Broker MQTT. Analyse des flux temporels sécurisés...")
        client.subscribe("stat/+/+")
        client.subscribe("tele/+/+")
    else:
        print(f"❌ Échec de connexion MQTT (Code {rc})")

def on_message(client, userdata, msg):
    global topic_to_ip_map
    topic = msg.topic
    
    try:
        payload_str = msg.payload.decode('utf-8', errors='ignore').strip()
    except Exception:
        return

    try:
        parts = topic.split('/')

        # --- CAS 1 : TASMOTA ---
        if topic.startswith("tele/") or topic.startswith("stat/"):
            device_id = parts[1] if len(parts) > 1 else parts[0]
            
            # Extraction propre de l'IP depuis le JSON STATUS5
            if "STATUS5" in topic and payload_str.startswith("{"):
                try:
                    data = json.loads(payload_str)
                    ip = data.get("StatusNET", {}).get("IPAddress")
                    if ip and re.match(r'^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$', ip):
                        topic_to_ip_map[device_id] = ip
                except json.JSONDecodeError:
                    pass

            # Fallback Regex sécurisé (si le payload est un JSON contenant une adresse IP)
            if device_id not in topic_to_ip_map and payload_str.startswith("{"):
                ip_match = re.search(r'192\.168\.\d+\.\d+', payload_str)
                if ip_match: 
                    topic_to_ip_map[device_id] = ip_match.group(0)

            # Extraction de la mesure de consommation électrique
            ip = topic_to_ip_map.get(device_id)
            if ip and ("STATUS" in topic or "SENSOR" in topic) and payload_str.startswith("{"):
                try:
                    data = json.loads(payload_str)
                    sns = data.get("StatusSNS", data)
                    
                    if 'GS303' in sns and 'Power_cur' in sns['GS303']:
                        update_device_cache(ip, sns['GS303'].get('Power_cur', 0))
                    elif 'ENERGY' in sns and 'Power' in sns['ENERGY']:
                        update_device_cache(ip, sns['ENERGY'].get('Power', 0))
                except json.JSONDecodeError:
                    pass

        # --- CAS 2 : SHELLY NOUVEAU FORMAT ---
        elif "shelly_192_168_" in parts[0]:
            raw_ip = parts[0].replace("shelly_", "").replace("_", ".")
            if re.match(r'^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$', raw_ip):
                ip = raw_ip
                if ("/status/pm1:" in topic or "/status/switch:" in topic) and payload_str.startswith("{"):
                    try:
                        data = json.loads(payload_str)
                        if 'apower' in data:
                            update_device_cache(ip, data.get('apower', 0))
                    except json.JSONDecodeError:
                        pass

    except Exception:
        pass

def run_mqtt_worker():
    client = mqtt.Client()
    if MQTT_USER and MQTT_PASS:
        client.username_pw_set(MQTT_USER, MQTT_PASS)
        
    client.on_connect = on_connect
    client.on_message = on_message

    client.connect(MQTT_HOST, MQTT_PORT, 60)
    client.loop_forever()

if __name__ == "__main__":
    run_mqtt_worker()

📝 4. Procédures de Maintenance Utiles (Console)

Si de nouveaux appareils rejoignent le réseau ou réinitialisent leurs options après une série de coupures de courant, utiliser ces commandes pour figer la configuration :
Sécuriser les options réseau d'une prise
Plaintext

Backlog GroupTopic tasmota_solo; DevGroupShare 0,0; SetOption36 0; SaveData 1

Verrouiller une prise critique sur ON (Frigo, Chauffage, etc.)
Plaintext

Backlog PowerOnState 1; SetOption0 1; ButtonTopic 0

Pour interdire l'extinction logicielle via une règle interne de secours :
Plaintext

Rule1 ON Power1#State=0 DO Power1 1 ENDON
Rule1 1



_____________


    ⚙️ 5. Configuration Spécifique : Modules Shelly (Gen2 / Gen3)

Contrairement aux modules Tasmota qui fonctionnent sur un modèle de scrutation périodique (TelePeriod), les modules Shelly de génération récente (ex: Shelly PM Mini Gen3, Plus 2PM) fonctionnent sur un modèle événementiel (Push on Change).

Si la consommation d'un appareil connecté à un Shelly est parfaitement stable (ex: un routeur ou une lampe LED), le module ne publiera aucun message MQTT pour économiser la bande passante.
Problème : Le système S.H.A. possède un Garbage Collector qui supprime du cache RAM tout appareil silencieux depuis plus de 10 minutes (MAX_AGE_SECONDS = 600). Un Shelly stable disparaîtra donc du tableau de bord.

Pour intégrer parfaitement un Shelly Gen2/Gen3 au système S.H.A., trois étapes obligatoires doivent être appliquées sur l'interface web de chaque module.
Étape 1 : Normalisation du Nommage (Préfixe)

Le script Python S.H.A. extrait l'adresse IP directement depuis le nom du topic racine. Il faut donc écraser le nom d'usine du Shelly.

    Aller dans Settings > MQTT.

    Dans le champ MQTT prefix, entrer le format strict S.H.A. : shelly_[IP_AVEC_UNDERSCORES] (ex: shelly_192_168_0_168).

Étape 2 : Activation des Flux de Données

Toujours dans Settings > MQTT, s'assurer que ces deux options sont cochées :

    ✅ RPC status notifications over MQTT (Active le canal maître en temps réel).

    ✅ Generic status update over MQTT (Active le canal classique /status/ en fallback).

    Note : Un redémarrage du module (Reboot) est requis après ces modifications.

Étape 3 : Le Script "TelePeriod" (Anti-Déconnexion)

Pour forcer le Shelly à parler toutes les 60 secondes (même sans changement de consommation) et ainsi réinitialiser le compteur du Garbage Collector S.H.A., il faut lui injecter un script interne.

    Aller dans le menu Scripts du Shelly.

    Créer un nouveau script nommé SHA_TelePeriod.

    Coller le code mJS suivant (optimisé pour ne pas saturer la RAM du Shelly) :

JavaScript

// --- S.H.A. KEEP-ALIVE SCRIPT POUR SHELLY GEN3 ---
// Force une publication MQTT de la consommation toutes les 60 secondes

// /!\ Remplacer l'IP ci-dessous par celle du module /!\
let TOPIC = "shelly_192_168_0_168/status/pm1:0"; 
let TELEPERIOD_MS = 60000; 

print("🚀 S.H.A. TelePeriod Script Démarré !");

Timer.set(TELEPERIOD_MS, true, function() {
  // PM1.GetStatus cible spécifiquement la puce d'énergie (évite le crash RAM)
  Shelly.call("PM1.GetStatus", { id: 0 }, function(res, err_code, err_msg) {
    if (err_code === 0) {
      let payload = JSON.stringify(res);
      MQTT.publish(TOPIC, payload, 0, false);
      print("✅ S.H.A. Ping envoyé : " + res.apower + " W");
    } else {
      print("❌ Erreur de lecture PM1 : " + err_msg);
    }
  });
});

    Cliquer sur Save.

    ⚠️ Action requise : Activer impérativement l'option Run on startup (Exécuter au démarrage) à côté du nom du script pour qu'il survive aux coupures de courant.

    Cliquer sur Start.

Le module Shelly est désormais 100 % compatible avec l'architecture S.H.A. et se comportera visuellement comme un module Tasmota sur le tableau de bord PHP.

____


2. Section de Documentation : Multi-Worker & Environnement

Voici la section à ajouter à ta documentation technique globale pour consigner toutes les modifications effectuées aujourd'hui en dehors du dossier /var/www/html/sha/.
📂 Annexe C : Dépendances Système et Architecture Multi-Worker

L'architecture S.H.A. s'appuie sur un gestionnaire d'environnement virtuel Python (PyEnv) et un service système centralisé (Systemd) pour faire cohabiter le collecteur de données et le moteur de règles événementiel.
1. Environnement Virtuel Obligatoire (PyEnv)

Les scripts S.H.A. ne doivent jamais utiliser le Python système (souvent obsolète ou bridé). Ils sont configurés pour s'exécuter exclusivement sous l'environnement suivant :

    Interpréteur cible : /root/.pyenv/versions/3.12.3/bin/python3

    Gestionnaire de paquets dédié : /root/.pyenv/versions/3.12.3/bin/pip

Dépendances critiques à maintenir (Gestion des versions) :

    pywebpush : Requis pour le chiffrement des notifications Push natives vers les navigateurs.

    paho-mqtt (⚠️ Verrouillage de version) : Le script utilise l'API de la branche 1.x. L'installation automatique de la version 2.x brise le protocole.

        Commande de restauration stricte : ```bash
        /root/.pyenv/versions/3.12.3/bin/pip install "paho-mqtt<2.0.0" --force-reinstall


        ___________________________________
        22.05.2026

        🗺️ 1. Architecture des FichiersL'infrastructure respecte une logique stricte de centralisation afin de séparer la présentation (HTML), le style (CSS), l'interactivité (JS) et la logique métier (PHP/Python) :Plaintext/var/www/html/sha/
├── assets/
│   └── css/
│       └── style.css            # Centralisation de toute la charte graphique (Sidebar, Cards, Grids)
├── core/
│   ├── functions.php            # Fonctions backend globales (Lecture cache RAM, Traitement AJAX actions)
│   └── functions.js             # Logique frontend unifiée (Délégation de clics, Volets, Auto-Refresh)
├── data/
│   └── sha_live.json            # 🔗 Lien symbolique vers /dev/shm/sha_live.json (Cache RAM ultrarapide)
├── config/
│   ├── app.conf                 # Identifiants sécurisés (MQTT, Base de données)
│   └── home_structure.conf      # Arborescence des pièces et assignation des devices
├── scripts/
│   └── sha_cache_builder.py     # Daemon de capture MQTT et orchestrateur de requêtes de masse (Polling)
└── steckdose.php                # Vue Cockpit épurée pour le contrôle des prises et lumières
🧠 2. Logique d'Affichage & d'Actionneur (steckdose.php)La page a été vidée de tout code superflu. Elle agit comme une simple boucle de rendu HTML.Prise en charge des types : Scanne et affiche dynamiquement les types socket, light, et light_p.Affichage adaptatif (Responsive Grids) : Les pièces s'organisent en grille fluide. L'Arbeitszimmer prend deux colonnes sur grand écran grâce à la classe .grid-2-columns et repasse en une seule colonne sur mobile.Statut unifié : La mention textuelle (🟢 ON, ⚫ OFF, ⚠️ Offline) est placée juste à côté du libellé de l'appareil. Le bouton à droite affiche uniquement la prochaine action disponible (Bouton vert "ON" pour allumer, Bouton rouge "OFF" pour éteindre).⚡ 3. Le Noyau Frontend Centralisé (core/functions.js)Le script JavaScript gère deux mécanismes critiques pour garantir la réactivité sans coupure :Auto-Refresh Invisible (DOM Parsing) : Toutes les 10 secondes, le script aspire la page actuelle en tâche de fond pour mettre à jour les états, les pastilles et le compte des périphériques actifs sans recharger l'interface utilisateur.Délégation d'Événements (Event Delegation) : Pour éviter que l'Auto-Refresh ne détruise les écouteurs de clics lors de la réécriture du code HTML, le script écoute le document global. L'interactivité des boutons ON/OFF est ainsi éternelle.Sécurité Allemande (Deutsche Sicherheit) : Pour éliminer les erreurs de manipulation sur écran tactile, toute tentative d'extinction (nextAction === 'OFF') déclenche une confirmation explicite :⚠️ S.H.A. Sicherheit: Sind Sie sicher, dass Sie das Gerät "[Nom]" AUSSCHALTEN möchten?📡 4. Pipeline de Données & Cache Multi-Relais (MQTT $\rightarrow$ Python $\rightarrow$ RAM)Le flux d'informations a été inversé pour éliminer la charge CPU sur les modules Tasmota :[Prises Tasmota] 
       │ (Réponses Status 5 & Télémétrie STATE)
       ▼
 [Broker MQTT]
       │
       ▼
[sha_cache_builder.py] ──(Traduction POWER1..4 en 1.0/0.0)──► [/dev/shm/sha_live.json]
                                                                      │
                                                        (Lecture RAM) │
                                                                      ▼
                                                              [steckdose.php]
Le Polling Centralisé (Requête de masse)Toutes les 5 minutes (300s), le script Python balance un ordre groupé sur le topic global : cmnd/tasmota_solo/STATUS 5. Toutes les prises connectées répondent instantanément en publiant leur configuration réseau. Le script extrait l'adresse IP et l'associe à l'identifiant MQTT de l'appareil dans la table topic_to_ip_map.Parsing Multi-Relais (Blocs à 4 prises)Lorsqu'un message de statut STATE arrive, le script Python scanne la racine du JSON à la recherche de clés commençant par POWER (POWER1, POWER2, etc.).Il convertit l'état textuel (ON $\rightarrow$ 1.0 / OFF $\rightarrow$ 0.0).Il injecte ces valeurs dans l'objet channels du cache RAM.Le fichier PHP valide instantanément la ligne grâce au test :PHP$current_state = ($dev_data['channels'][$relay] > 0) ? "ON" : "OFF";
🛠️ 5. Maintenance & Commandes UtilesPour toute modification dans la structure de capture ou l'ajout de règles de parsing, l'ensemble des scripts s'administre via le service système global de la machine.Redémarrer l'orchestrateur de cache :Bashsystemctl restart sha-worker.service
Vérifier le bon fonctionnement des scripts en temps réel :Bashjournalctl -u sha-worker.service -f
Surveiller l'intégrité du fichier de cache en RAM :Bashcat /dev/shm/sha_live.json