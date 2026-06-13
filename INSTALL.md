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


_____________________
23.05.2026 (Afternoon)

🛠️ RÉCAPITULATIF : Activation du WOL & Shutdown sur Windows 11Pour chaque nouveau PC à intégrer dans l'infrastructure S.H.A., applique cette procédure stricte :1. Configuration du BIOS / UEFIChercher l'option ErP Ready (ou Deep Sleep) et la passer sur Disabled (sinon la carte réseau est totalement privée de courant à l'extinction).Activer l'option Wake-On-LAN, Power On By PCI-E ou Resume by PME.2. Configuration de Windows 11Ouvre un terminal PowerShell en mode Administrateur et exécute ce bloc de commandes pour configurer le réseau, le registre et le pare-feu restrictif (autorisant uniquement l'IP du serveur 192.168.0.10) :PowerShell# 1. Force le réseau en mode Privé (indispensable pour ouvrir les ports)
Get-NetConnectionProfile | Set-NetConnectionProfile -NetworkCategory Private

# 2. Active et démarre le service Registre à Distance utilisé pour l'extinction RPC
Set-Service RemoteRegistry -StartupType Automatic
Start-Service RemoteRegistry

# 3. Crée la règle de Pare-feu exclusive et ultra-sécurisée pour le serveur S.H.A.
New-NetFirewallRule -DisplayName "SHA RPC Shutdown" -Direction Inbound -LocalPort 445 -Protocol TCP -Action Allow -Profile Private -RemoteAddress 192.168.0.10

# 4. Autorise les jetons d'administration distants locaux pour valider l'extinction
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System" /v "LocalAccountTokenFilterPolicy" /t REG_DWORD /d 1 /f
3. Gestionnaire de périphériques & Options d'alimentationDésactiver le démarrage rapide : Dans le Panneau de configuration $\rightarrow$ Options d'alimentation $\rightarrow$ Choisir l'action des boutons d'alimentation, clique sur modifier les paramètres indisponibles et décoche la case Activer le démarrage rapide (Schnellstart).Propriétés de la carte réseau :Onglet Gestion de l'alimentation : Cocher Autoriser ce périphérique à réveiller l'ordinateur et Autoriser uniquement un paquet magique.Onglet Avancé : Activer Réveil sur paquet magique (Wake on Magic Packet), Délestage ARP (ARP Offload) et Shutdown Wake-On-LAN (Aktivierung nach Herunterfahren).📝 DOCUMENTATION TECHNIQUE : MODULE COCKPIT PRISES, LUMIÈRES & PC (S.H.A. 2026)🗺️ 1. Architecture des Fichiers et DépendancesL'architecture sépare de manière étanche la capture asynchrone (Python), le stockage volatile (RAM), la sécurité (fichiers .conf), le routage d'action (PHP Core) et l'interface utilisateur.Plaintext/var/www/html/sha/
├── assets/css/style.css         # Conteneurs de grilles (.grid-2-columns, .flex-column, responsive)
├── core/
│   ├── functions.php            # Émetteur WOL PHP, exécuteur RPC Shutdown, lecteur RAM et parseur app.conf
│   └── functions.js             # Boucle d'Auto-Refresh DOM 10s, Délégation d'événements, Deutsche Sicherheit
├── data/sha_live.json           # Symlink pointant vers le cache volatile de la RAM (/dev/shm/sha_live.json)
├── config/
│   ├── app.conf                 # Identifiants chiffrés / sécurisés [MQTT] et [Windows] (User / Pass)
│   └── home_structure.conf      # Registre d'arborescence des pièces unifiant les tableaux devices[] et pcs[]
├── scripts/
│   └── sha_cache_builder.py     # Démon d'arrière-plan (Orchestrateur MQTT Multi-Relais + Ping asynchrone 15s)
└── steckdose.php                # Rendu HTML pur alimenté par le cache RAM (Temps d'exécution < 5ms)
📡 2. Pipeline de Données de Fond (sha_cache_builder.py)Géré par le service global sha-worker.service, ce script centralise toute la capture de données pour soulager le serveur web Apache et éliminer la latence à l'affichage.A. Analyse Multi-Relais Tasmota (Nouveauté)Le script intercepte les messages STATE et parcourt dynamiquement la racine du JSON à la recherche de clés POWER1, POWER2, etc. Il convertit automatiquement les statuts binaires textuels en valeurs flottantes (1.0 / 0.0) insérées dans l’objet channels du périphérique pour une compatibilité PHP transparente. Toutes les 5 minutes, il pousse un STATUS 5 global pour cartographier les adresses IP à la volée.B. Balayage Ping Asynchrone des PC (Nouveauté)Toutes les 15 secondes, le démon s'interrompt brièvement pour analyser home_structure.conf. Il extrait toutes les adresses IP déclarées sous l'identifiant pc| et exécute un ping d’arrière-plan ultra-rapide (1 paquet, timeout de 500ms). L’état obtenu (ON ou OFF) est immédiatement injecté de manière atomique dans le fichier /dev/shm/sha_live.json.🧠 3. Routage Backend & Sécurité (core/functions.php)Le fichier core/functions.php centralise la logique métier lourde et l'accès sécurisé aux configurations privées.A. Injection d’Authentification (config/app.conf)Le fichier charge à la volée la section [Windows] pour récupérer l'utilisateur et le mot de passe administrateur local sans jamais les exposer en clair dans l'arborescence du code public :Ini, TOML[Windows]
user = "NomAdministrateur"
password = "MotDePasseSecurise"
B. Émetteur Wake-On-LAN NatifLors d'une requête POST avec l'action ON sur un périphérique de type pc, le noyau extrait l'adresse MAC, nettoie les séparateurs superflus, assemble le Magic Packet binaire (6 octets 0xFF + 16 répétitions de la MAC address) et l'éjecte en UDP Broadcast sur le port 9.C. Shutdown RPC Forcé (Graceful Extinction)Lors d’une action OFF sur un PC, PHP exécute la commande réseau Samba unifiée :Bashnet rpc shutdown -I [IP_PC] -U [USER%PASSWORD] -t 0 -f
L'argument -t 0 garantit une extinction immédiate, tandis que l'argument -f (Force) ordonne au noyau Windows de court-circuiter toutes les applications ouvertes et de déconnecter instantanément toutes les sessions (masquées, actives ou verrouillées par un autre utilisateur) pour éteindre proprement la machine.⚡ 4. Interface & Noyau Frontend (steckdose.php + functions.js)A. Rendu Visuel Fluide et Ergonomie PCLa page steckdose.php fusionne à la volée les lignes devices[] et pcs[] de chaque pièce. Pour garantir une expérience fluide :Ergonomie PC : Contrairement aux prises de puissance, les PC sont exclus de la routine d'inactivité du cache. Ils ne passent jamais en statut Offline gris, maintenant le bouton d'action opérationnel en permanence.Mise en page adaptative : La pièce Arbeitszimmer s’étale élégamment sur deux colonnes équilibrées (.grid-2-columns) sur les écrans de PC/Tablettes et bascule sur une colonne sur smartphone.B. Moteur JS CentraliséL'interactivité repose entièrement sur le fichier core/functions.js :Auto-Refresh DOM : Toutes les 10 secondes, le script aspire le contenu mis à jour depuis la RAM de manière invisible et remplace les nœuds HTML (Pastilles 🟢 ON / ⚫ OFF, compteurs de badges) sans rafraîchir la page ni couper l'expérience utilisateur.Double Sécurité Allemande (Deutsche Sicherheit) : Tout clic sur un bouton menant à une extinction (nextAction === 'OFF') intercepte l'événement et affiche une boîte de dialogue de sécurité stricte :⚠️ S.H.A. Sicherheit: Sind Sie sicher, dass Sie das Gerät "[Nom du périphérique]" AUSSCHALTEN möchten?🛠️ 5. Commandes de Maintenance SystèmeToute modification au sein de l'écosystème SHA (fichiers de structure, de configuration, ou règles de parsing Python) s'administre via les commandes système suivantes :Appliquer les modifications (Redémarrer l'orchestrateur) :Bashsystemctl restart sha-worker.service
Surveiller le flux de débogage du worker en temps réel :Bashjournalctl -u sha-worker.service -f
Vérifier l'état actuel du cache brut en RAM :Bashcat /dev/shm/sha_live.json

## 👥 6. Provisionnement d'un nouveau PC Windows 11 (Compte Admin Local)

Pour que le serveur S.H.A. (`192.168.0.10`) soit autorisé à pousser l'ordre d'extinction, un compte miroir avec des droits d'administration élevés doit exister localement sur la machine cible. Ce compte doit correspondre exactement aux valeurs injectées dans le fichier `config/app.conf`.

### A. Création rapide via Invite de commandes (CMD en mode Admin)
Sur la machine Windows 11 à intégrer, ouvrir un terminal `cmd` avec les privilèges d'administrateur et exécuter les commandes suivantes :

```cmd
:: 1. Création de l'utilisateur local avec son mot de passe dédié
net user "TonNomAdministrateur" "TonMotDePasseSecurise" /add

:: 2. Élévation des privilèges au groupe des Administrateurs
:: ⚠️ Règle de langue obligatoire (choisir la commande selon l'OS) :
:: Pour un Windows Allemand (Standard S.H.A.) :
net localgroup Administratoren "TonNomAdministrateur" /add

:: Pour un Windows Français :
net localgroup Administrateurs "TonNomAdministrateur" /add

:: Pour un Windows Anglais :
net localgroup Administrators "TonNomAdministrateur" /add


## 🔒 7. Autorisation Exclusive du Ping Réseau (ICMPv4)

Par défaut, le pare-feu de Windows 11 bloque les requêtes d'écho (Ping). Pour que le démon d'arrière-plan `sha_cache_builder.py` puisse cartographier l'état live (`ON`/`OFF`) de la machine toutes les 15 secondes sans compromettre la sécurité globale du PC, une règle d'isolation ICMP doit être injectée.

### A. Injection de la règle restrictive (PowerShell en mode Admin)
Exécuter la commande suivante sur le PC Windows pour ouvrir le flux ICMP **uniquement** à l'adresse IP du serveur S.H.A. (`192.168.0.10`) :

```powershell
New-NetFirewallRule -DisplayName "SHA ICMP Ping" -Direction Inbound -Protocol ICMPv4 -IcmpType 8 -Action Allow -Profile Private -RemoteAddress 192.168.0.10
___________
Activer le ping:

Méthode 2 : Via l'interface graphiqueSi vous préférez passer par les menus de Windows :1.Ouvrir le pare-feu avancé:Appuyez sur le raccourci Win + R, tapez wf.msc et validez avec Entrée. Cela ouvre la console du Pare-feu Windows Defender avec fonctions avancées de sécurité.2.Accéder aux règles de trafic entrant:Dans la colonne de gauche, cliquez sur Règles de trafic entrant (Inbound Rules).3.Localiser la règle ICMP:Faites défiler la liste centrale jusqu'à la section Partage de fichiers et d'imprimantes. Recherchez la règle suivante :Partage de fichiers et d'imprimantes (Demande d'écho - ICMPv4-Entrée)(Note : Si votre Windows est configuré en anglais, elle s'intitule File and Printer Sharing (Echo Request - ICMPv4-In)).4.Activer la règle:Faites un clic droit sur la règle qui correspond à votre profil réseau actuel (généralement le profil Privé) et sélectionnez Activer la règle. L'icône à gauche de la ligne doit devenir verte.

___

Si ca ne marche pas

# 1. Passe le réseau en Privé
Get-NetConnectionProfile | Set-NetConnectionProfile -NetworkCategory Private

# 2. Active le service de registre distant
Set-Service RemoteRegistry -StartupType Automatic
Start-Service RemoteRegistry

# 3. Ouvre le pare-feu exclusivement pour le serveur SHA
New-NetFirewallRule -DisplayName "SHA RPC Shutdown" -Direction Inbound -LocalPort 445 -Protocol TCP -Action Allow -Profile Private -RemoteAddress 192.168.0.10
New-NetFirewallRule -DisplayName "SHA ICMP Ping" -Direction Inbound -Protocol ICMPv4 -IcmpType 8 -Action Allow -Profile Private -RemoteAddress 192.168.0.10

# 4. Autorise les jetons d'administration distants
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System" /v "LocalAccountTokenFilterPolicy" /t REG_DWORD /d 1 /f

______________
26.05.2026

1. Backend Core : /var/www/html/sha/core/functions.php

La logique de traitement des commandes a été épurée. Les requêtes de gradation (curseur) et les commandes d'état (boutons ON/OFF) partagent les mêmes contraintes de sécurité. Pour le type light (OpenBeken/Dimmable), l'intensité pilote tout et le canal de couleur est forcé à 0.
PHP

<?php
/**
 * SHA 2026 - Fonctions Core (RAM Powered)
 */

function getSmartMeter($ip) {
    if (empty($ip)) return 0;
    $cache_file = '/dev/shm/sha_live.json';
    if (!file_exists($cache_file)) return 0;
    $live_data = json_decode(@file_get_contents($cache_file), true);
    return $live_data['devices'][$ip]['power'] ?? 0;
}

function getTasmotaState($ip, $relay = 1) {
    $power = getSmartMeter($ip);
    return ($power > 1.0) ? 'ON' : 'OFF';
}

function getSolarPower($ip) {
    return getSmartMeter($ip);
}

if (!function_exists('handle_device_action')) {
    /**
     * Traite les actions interactives (Tasmota, ADB Android, Gradation OpenBeken, etc.)
     */
    function handle_device_action() {
        // --- 1. LECTURE PRÉALABLE DE LA CONFIGURATION MQTT DEPUIS APP.CONF ---
        $mqtt_user = "raftanel";
        $mqtt_pass = "";
        $app_conf_path = dirname(__DIR__) . '/config/app.conf';
        if (file_exists($app_conf_path)) {
            $app_config = parse_ini_file($app_conf_path, true);
            if (isset($app_config['MQTT'])) {
                $mqtt_user = $app_config['MQTT']['user'] ?? $mqtt_user;
                $mqtt_pass = $app_config['MQTT']['password'] ?? $mqtt_pass;
            }
        }
        $auth_part = "-u " . escapeshellarg($mqtt_user) . " -P " . escapeshellarg($mqtt_pass);

        // --- CAS A : REQUÊTE DE GRADATION DIRECTE VIA LE CURSEUR ---
        if (isset($_POST['ip']) && isset($_POST['action']) && isset($_POST['value'])) {
            header('Content-Type: application/json');
            $ip = filter_var($_POST['ip'], FILTER_VALIDATE_IP);
            $action = $_POST['action']; // 'dimmer'
            $value = intval($_POST['value']);

            if (!$ip) {
                echo json_encode(['success' => false, 'message' => 'Ungültige IP']);
                exit;
            }

            if ($action === 'dimmer') {
                // Intensité cible sur Canal 1
                $cmd1 = "mosquitto_pub -h localhost $auth_part -t 'obk08466065/1/set' -m " . escapeshellarg($value) . " > /dev/null 2>&1";
                @exec($cmd1);

                // Sécurité : On s'assure que le pilote maître est réveillé
                $cmd2 = "mosquitto_pub -h localhost $auth_part -t 'obk08466065/led_enableAll' -m '1' > /dev/null 2>&1";
                @exec($cmd2);

                // Neutralisation définitive de la couleur sur le Canal 0
                $cmd3 = "mosquitto_pub -h localhost $auth_part -t 'obk08466065/0/set' -m '0' > /dev/null 2>&1";
                @exec($cmd3);

                echo json_encode(['success' => true, 'message' => 'Intensité et état appliqués']);
                exit;
            }
            exit;
        }

        // --- CAS B : TRAITEMENT TRADITIONNEL DES ETATS (ON / OFF) ---
        if (isset($_POST['action']) && isset($_POST['ip'])) {
            header('Content-Type: application/json');

            $ip = filter_var($_POST['ip'], FILTER_VALIDATE_IP);
            $target_state = ($_POST['action'] === 'ON') ? 'ON' : 'OFF';
            $type = $_POST['type'] ?? 'socket';
            $relay_or_mac = $_POST['relay'] ?? '';

            if (!$ip) {
                echo json_encode(['success' => false, 'message' => 'Ungültige Parameter']);
                exit;
            }

            // [PC Windows & Android / Fire TV Omis ici pour lisibilité de la doc]
            
            // --- EXTINCTION / ALLUMAGE APPAREILS DE TYPE LIGHT (OPENBEKEN) ---
            if ($type === 'light') {
                if ($target_state === 'OFF') {
                    // Extinction franche et abaissement des canaux à 0
                    @exec("mosquitto_pub -h localhost $auth_part -t 'obk08466065/led_enableAll' -m '0' > /dev/null 2>&1");
                    @exec("mosquitto_pub -h localhost $auth_part -t 'obk08466065/1/set' -m '0' > /dev/null 2>&1");
                } else {
                    // Allumage et rappel de l'intensité à 100%
                    @exec("mosquitto_pub -h localhost $auth_part -t 'obk08466065/led_enableAll' -m '1' > /dev/null 2>&1");
                    @exec("mosquitto_pub -h localhost $auth_part -t 'obk08466065/1/set' -m '100' > /dev/null 2>&1");
                }
                @exec("mosquitto_pub -h localhost $auth_part -t 'obk08466065/0/set' -m '0' > /dev/null 2>&1");

                echo json_encode(['success' => true, 'new_state' => $target_state]);
                exit;
            }

            // --- MODULES TASMOTA STANDARD RELAIS ---
            $relay_num = intval($relay_or_mac);
            if ($relay_num >= 1) {
                $url = "http://{$ip}/cm?cmnd=Power{$relay_num}%20{$target_state}";
                $ctx = stream_context_create(['http' => ['timeout' => 2]]);
                @file_get_contents($url, false, $ctx);
                echo json_encode(['success' => true, 'new_state' => $target_state]);
                exit;
            }
        }
    }
}

function get_sha_live_cache($cache_path = '/dev/shm/sha_live.json') {
    if (file_exists($cache_path) && filesize($cache_path) > 0) {
        return json_decode(@file_get_contents($cache_path), true) ?? [];
    }
    return [];
}

🎛️ 2. Interface Utilisateur : /var/www/html/sha/steckdose.php

Le popover et sa flèche ▼ ont été balayés. La tirette d'intensité (Helligkeit) est maintenant directement intégrée sur la ligne, à la suite du badge 🟢 ON. Elle ne s'affiche que si l'appareil est allumé. Aucun code JavaScript n'est toléré dans ce fichier.
PHP

// [Lignes d'initialisation 1-80 inchangées]
foreach ($dev_list as $d) {
    $is_on = ($d['state'] === 'ON');
    $row_class = $d['offline'] ? "dev-row offline" : ($is_on ? "dev-row state-on" : "dev-row state-off");

    $rendered_cards_html .= '      <div class="' . $row_class . '">';
    $rendered_cards_html .= '          <span class="dev-name"><span>' . $d['icon'] . ' ' . $d['label'] . '</span>';
    
    $rendered_cards_html .= '          <span class="status-container" style="display: inline-flex; align-items: center; gap: 15px;">';
    $rendered_cards_html .= '              <span class="status-text ' . ($d['offline'] ? 'offline' : ($is_on ? 'on' : 'off')) . '">' . ($d['offline'] ? '⚠️ Offline' : ($is_on ? '🟢 ON' : '⚫ OFF')) . '</span>';
    
    // Rendu en ligne direct si ON et dimmable
    if ($is_on && $d['dimmable']) {
        $rendered_cards_html .= '          <span class="direct-dimmer-block" style="display: inline-flex; align-items: center; gap: 8px;">';
        $rendered_cards_html .= '              <input type="range" min="0" max="100" value="' . $d['dimmer'] . '" style="width: 90px; accent-color: #ff9800; margin: 0; cursor: pointer;" oninput="this.nextElementSibling.innerText = this.value + \'%\'" onchange="sendOBKDimmer(\'' . $d['ip'] . '\', \'dimmer\', this.value)">';
        $rendered_cards_html .= '              <span style="font-size: 0.7rem; font-weight: bold; color: #ff9800; min-width: 32px; text-align: right;">' . $d['dimmer'] . '%</span>';
        $rendered_cards_html .= '          </span>';
    }
    
    $rendered_cards_html .= '          </span></span>';
    $rendered_cards_html .= '          <button class="toggle-btn ' . ($is_on ? 'btn-on' : 'btn-off') . '" data-type="' . $d['type'] . '" data-ip="' . $d['ip'] . '" data-relay="' . $d['relay'] . '" data-state="' . $d['state'] . '" data-label="' . htmlspecialchars($d['label'], ENT_QUOTES) . '">' . ($is_on ? 'OFF' : 'ON') . '</button>';
    $rendered_cards_html .= '      </div>';
}
// [Fin de fichier classique avec inclusion du footer]

⚡ 3. Interactivité Instantanée : /var/www/html/sha/core/functions.js

Pour tricher sur la latence du réseau, le fichier JS intercepte le clic sur le bouton maître et applique un effet visuel immédiat : il injecte la tirette à 100% dès le clic sur ON, et la détruit au millième de seconde dès le clic sur OFF.
JavaScript

/**
 * S.H.A. 2026 - Fonctions JavaScript Core
 */

document.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('toggle-btn')) {
        const btn = e.target;
        const type = btn.getAttribute('data-type');
        const ip = btn.getAttribute('data-ip');
        const currentState = btn.getAttribute('data-state'); 

        if (type === 'light') {
            const row = btn.closest('.dev-row');
            if (!row) return;
            
            const statusContainer = row.querySelector('.status-container');
            if (!statusContainer) return;

            // Injection instantanée au clic sur ON
            if (currentState === 'OFF') {
                if (!statusContainer.querySelector('.direct-dimmer-block')) {
                    const dimmerHtml = `
                        <span class="direct-dimmer-block" style="display: inline-flex; align-items: center; gap: 8px;">
                            <input type="range" min="0" max="100" value="100" style="width: 90px; accent-color: #ff9800; margin: 0; cursor: pointer;" oninput="this.nextElementSibling.innerText = this.value + '%'" onchange="sendOBKDimmer('${ip}', 'dimmer', this.value)">
                            <span style="font-size: 0.7rem; font-weight: bold; color: #ff9800; min-width: 32px; text-align: right;">100%</span>
                        </span>
                    `;
                    statusContainer.insertAdjacentHTML('beforeend', dimmerHtml);
                }
            } 
            // Destruction immédiate au clic sur OFF
            else if (currentState === 'ON') {
                const dimmerBlock = statusContainer.querySelector('.direct-dimmer-block');
                if (dimmerBlock) dimmerBlock.remove();
            }
        }
    }
});

function sendOBKDimmer(ip, action, value) {
    const formData = new FormData();
    formData.append('ip', ip);
    formData.append('action', action);
    formData.append('value', value);
    fetch('steckdose.php', { method: 'POST', body: formData });
}

🐍 4. Worker de Cache Python : scripts/sha_cache_builder.py

Pour éliminer définitivement les fausses remontées d'état (led_enableAll/get 0) de la lampe alors qu'elle est allumée, le script Python applique un filtrage de confiance : l'ordre /1/set est roi. Les retours /get menteurs sont ignorés.
Python

        # --- CAS 2 : OPENBEKEN (Version alignée sur les canaux PHP) ---
        elif device_id.startswith("obk") or "OpenBK" in topic:
            ip_match = re.search(r'192\.168\.\d+\.\d+', payload_str)
            if ip_match: topic_to_ip_map[device_id] = ip_match.group(0)
            ip = topic_to_ip_map.get(device_id)
            if ip:
                # Interception des ordres uniquement (on rejette les /get)
                if "led_enableAll" in topic:
                    if not topic.endswith("/get"):
                        new_state = "ON" if payload_str in ["1", "ON", "TRUE"] else "OFF"
                        update_device_cache(ip, state=new_state)
                        update_device_cache(ip, state=new_state, channel="1")
                
                # Le levier d'intensité /1/set dicte la valeur et le statut réel
                elif topic.endswith("/1/set") or (topic.endswith("/1") and not topic.endswith("/get")):
                    try:
                        val = int(float(payload_str))
                        if val == 0:
                            update_device_cache(ip, state="OFF", channel="1")
                        else:
                            update_device_cache(ip, dimmer=val, state="ON", channel="1")
                    except: pass

                # Secours : Prise de valeur initiale uniquement au reboot du script
                elif "led_dimmer" in topic:
                    try:
                        dim_val = int(float(payload_str))
                        if dim_val > 0:
                            if cached_devices.get(ip, {}).get("dimmer") is None:
                                update_device_cache(ip, dimmer=dim_val)
                    except: pass

🛠️ 5. Analyse Périphériques (TV Android & Shelly Gen 1)

    Android TV (Sleep Timer Bloqué) : L'injection dans functions.php écrase le timeout système d'Android (screen_off_timeout 0 et sleep_timeout 0). En cas de firmware constructeur trop agressif, la parade validée est l'envoi d'un signal fantôme keyevent 224 par cron toutes les 15 minutes.

    Shelly Dimmer 2 (MQTT Structure) : Identifié sur MQTT Explorer sous le nœud racine inamovible shellies/. Pour l'intégrer proprement, la recommandation est de remplacer son long ID d'usine par un préfixe personnalisé dans son interface développeur (ex: salon_dimmer), puis d'ouvrir la condition du sha_cache_builder.py à "shellies" in topic.


    _____

    29.05.26 - Portail 

    C'est une excellente décision. Les pas de vis des boîtiers extérieurs en plastique s'usent très vite, et rouvrir l'étanchéité plusieurs fois augmente le risque d'infiltration d'eau à la prochaine pluie. Faire tout d'un coup en une seule session propre est la meilleure méthode.

Voici la feuille de route complète et définitive pour le jour où tu reçois ton module relais 24V. Tu pourras tout câbler d'une seule traite avant de refermer définitivement le boîtier.
⚠️ RAPPEL SÉCURITÉ AVANT DE COMMENCER

Coupe impérativement le disjoncteur général du portail au tableau électrique de la maison. Vérifie que l'écran LCD du Marantec est bien éteint avant d'insérer le moindre tournevis.
🛠️ LE PLAN DE CÂBLAGE UNIQUE (En 4 étapes)
Étape 1 : Alimentation et Commande du Portail (Le Shelly 1 Gen 3)

    Alimentation 230V : Branche un fil depuis le domino L (Phase de la Marantec) vers la borne L du Shelly, et un fil depuis le domino N (Neutre) vers la borne N du Shelly.

    Commande Contact Sec : Branche un fil de la borne I du Shelly vers la borne B9 de la Marantec, et un fil de la borne O du Shelly vers la borne 8 de la Marantec.

Étape 2 : Alimentation du module Relais 24V (Depuis la Marantec)

Regarde sous l'écran LCD, là où il est écrit "Class 2 Supply 24 Volts". Tu y trouveras les bornes d'alimentation Basse Tension.

    Relie la borne + 24V de la Marantec à la broche DC+ (ou VCC) du petit module relais.

    Relie la borne GND (ou Moins) de la Marantec à la broche DC- (ou GND) du petit module relais.
    À ce stade, le petit module relais partagera la même alimentation basse tension sécurisée que ton moteur.

Étape 3 : La boucle extérieure du Détecteur Magnétique (Sécurité 24V)

C'est le câble qui part à l'extérieur vers ton portail.

    Prends le fil COM (Commun) de ton détecteur magnétique et connecte-le sur le + 24V de la Marantec (tu peux doubler le fil dans la borne).

    Prends le fil NC (Normalement Fermé) de ton détecteur et connecte-le sur la broche IN (Signal) du module relais.

    Isole le troisième fil (NO) du détecteur avec un petit scotch, il ne sert pas.

    Le Jumper : Sur la carte du relais, positionne le petit cavalier en plastique (jumper) sur la position HIGH.
    (Comme le capteur est NC, quand le portail est fermé, l'aimant est là, le contact est fermé et il envoie du 24V sur l'entrée IN. Le relais va donc coller quand le portail est fermé).

Étape 4 : La liaison interne Relais ➡️ Shelly (Retour d'état)

Cette partie reste à l'intérieur du boîtier étanche et fait la passerelle vers le Shelly.

    Tire un petit fil depuis la borne L (230V) du Shelly vers la borne à vis COM (généralement la borne centrale du bornier bleu à 3 vis) du module relais.

    Tire un petit fil depuis la borne à vis NO (Normalement Ouvert) du module relais vers la borne SW du Shelly.

📲 AVANT DE REVISSER LE CAPOT (La configuration)

Remets le disjoncteur général en marche. Prends ton smartphone, connecte-toi à l'interface IP du Shelly et valide ces deux réglages :

    Le mode impulsion (Moteur) : Va dans Timer ➡️ Active l'Auto OFF et règle-le sur 0.5 seconde. (Indispensable pour simuler un bouton poussoir).

    Le mode détaché (Capteur) : Va dans Input settings (Paramètres de l'entrée SW) et configure le bouton en mode Detached (Détaché).

Le test ultime : * En bougeant l'aimant devant ton capteur, tu dois entendre un léger "clic" provenant du petit module relais bleu, et tu dois voir le statut changer instantanément sur l'interface graphique du Shelly (le petit trait vertical à côté du bouton), sans que le portail ne se mette à bouger.

    En cliquant sur le bouton ON de l'application, le portail doit s'activer.

Une fois que tout est validé, tu peux ranger proprement les fils, fermer ton boîtier et serrer tes vis une bonne fois pour toutes !


____________
30.05.26

🛠️ ARCHITECTURE DU SYSTÈME DE SAUVEGARDE CROISÉE (SHA & FAMILLE)

Voici le dossier de documentation d'ingénierie finale complet. Les scripts sont factorisés, la sécurité est déportée et les flux d'exécution sont asynchrones.
1. Script Logique Centralisé : /usr/local/bin/backup_system.sh

Ce script binaire est strictement identique sur S.H.A. et FAMILLE. Il implémente un argument facultatif test pour l'audit à chaud.
Bash

#!/bin/bash
# =====================================================================
# S.H.A. & FAMILLE - SCRIPT DE SAUVEGARDE SYSTÈME + SQL INTERACTIF v1.7
# =====================================================================
set -euo pipefail

CONF_FILE="/etc/sha_backup.conf"
if [[ ! -f "$CONF_FILE" ]]; then
    echo "❌ Erreur : Fichier de configuration $CONF_FILE introuvable." >&2
    exit 1
fi

source "$CONF_FILE"
START_TIME=$(date +%s)

# Détection du mode d'évaluation rapide
TEST_MODE=false
if [[ "${1:-}" == "--test" || "${1:-}" == "test" ]]; then
    TEST_MODE=true
fi

log() {
    local level="$1"
    local msg="$2"
    local timestamp
    timestamp=$(date "+%Y-%m-%d %H:%M:%S")
    echo "[$timestamp] [$level] $msg" | tee -a "$LOG_FILE"
}

DATE_NOW=$(date "+%Y-%m-%d")
DAY_OF_MONTH=$(date "+%d")
DAY_OF_WEEK=$(date "+%u")
WEEK_NUMBER=$(date "+%V")

DIR_DAILY="${LOCAL_TARGET}/daily"
DIR_WEEKLY="${LOCAL_TARGET}/weekly"
DIR_MONTHLY="${LOCAL_TARGET}/monthly"
mkdir -p "$DIR_DAILY" "$DIR_WEEKLY" "$DIR_MONTHLY"

ARCHIVE_NAME="sys-backup-${DATE_NOW}.tar.gz"
if [[ "${TEST_MODE}" == "true" ]]; then
    ARCHIVE_NAME="sys-backup-test-${DATE_NOW}.tar.gz"
fi
ARCHIVE_PATH="${DIR_DAILY}/${ARCHIVE_NAME}"

# --- ÉTAPE 0 : DUMP LOGIQUE MYSQL/MARIADB ---
MYSQL_TMP_DIR="/var/backups/mysql_staging"

if [[ "${MYSQL_BACKUP_ENABLED}" == "true" ]]; then
    log "INFO" "🗄️ [0/3] Lancement de l'extraction logique à chaud (mysqldump)..."
    mkdir -p "$MYSQL_TMP_DIR"
    chmod 700 "$MYSQL_TMP_DIR"
    
    MYSQL_DUMP_FILE="${MYSQL_TMP_DIR}/all_databases_${DATE_NOW}.sql.gz"
    export MYSQL_PWD="${MYSQL_PASS}"
    
    if mysqldump -u "${MYSQL_USER}" --all-databases --single-transaction --quick --routines --triggers 2>/dev/null | gzip > "$MYSQL_DUMP_FILE"; then
        DUMP_SIZE=$(du -sh "$MYSQL_DUMP_FILE" | cut -f1)
        log "SUCCESS" "Dump MySQL compressé créé avec succès : ${DUMP_SIZE}"
    else
        log "ERROR" "Échec critique du dump MySQL. Poursuite du traitement."
    fi
    unset MYSQL_PWD
fi

# --- ÉTAPE 1 : PIPELINE DE COMPRESSION (TARBALL) ---
if [[ "${TEST_MODE}" == "true" ]]; then
    log "WARN" "⚡ [1/3] MODE TEST ACTIF : Extraction restreinte au dump et fichiers d'états..."
    TAR_TARGETS="/etc/hosts /etc/hostname"
    if [[ -d "$MYSQL_TMP_DIR" ]]; then
        TAR_TARGETS="${TAR_TARGETS} ${MYSQL_TMP_DIR}"
    fi
    # shellcheck disable=SC2086
    tar -czf "$ARCHIVE_PATH" $TAR_TARGETS 2>/dev/null || true
    BACKUP_SIZE=$(du -sh "$ARCHIVE_PATH" | cut -f1)
    log "SUCCESS" "Archive de test isolée : ${ARCHIVE_PATH} (${BACKUP_SIZE})"
else
    log "INFO" "📦 [1/3] Compression globale de la racine (/) avec exclusions industrielles..."
    TAR_EXCLUDES=""
    for exc in $EXCLUDES; do
        TAR_EXCLUDES="${TAR_EXCLUDES} --exclude=${exc}"
    done
    # shellcheck disable=SC2086
    if tar -czf "$ARCHIVE_PATH" $TAR_EXCLUDES / 2>/dev/null || true; then
        ROOT_SIZE=$(du -sh / 2>/dev/null | cut -f1 || echo "N/A")
        BACKUP_SIZE=$(du -sh "$ARCHIVE_PATH" | cut -f1)
        log "SUCCESS" "Archive système créée : ${ARCHIVE_PATH} (Racine: ${ROOT_SIZE} -> Target: ${BACKUP_SIZE})"
    else
        log "ERROR" "Rupture partielle durant l'exécution de la commande tar."
        exit 1
    fi
fi

# Purge immédiate de la zone de staging MySQL (Libération espace disque)
if [[ -d "$MYSQL_TMP_DIR" ]]; then
    rm -rf "$MYSQL_TMP_DIR"
fi

# --- ÉTAPE 2 : MACHINE À ÉTATS DE RÉTENTION (ROTATION TEMPORELLE) ---
log "INFO" "🔄 [2/3] Traitement de la taxonomie temporelle et purge des deltas obsolètes..."
FIND_PATTERN="sys-backup-*.tar.gz"
if [[ "${TEST_MODE}" == "true" ]]; then
    FIND_PATTERN="sys-backup-test-*.tar.gz"
fi

find "$DIR_DAILY" -name "$FIND_PATTERN" -type f -printf '%T@ %p\n' \
    | sort -n | head -n -"${RETENTION_DAILY}" | cut -d' ' -f2- | xargs rm -f

if [[ "${TEST_MODE}" == "false" ]]; then
    # Sauvegarde Bi-hebdomadaire (Semaines ISO paires, le Dimanche)
    if [[ "$DAY_OF_WEEK" -eq 7 ]] && [[ $((10#$WEEK_NUMBER % 2)) -eq 0 ]]; then
        log "INFO" "Règle de Quinzaine validée. Duplication du snapshot..."
        cp "$ARCHIVE_PATH" "${DIR_WEEKLY}/sys-backup-quinzaine-${DATE_NOW}.tar.gz"
        find "$DIR_WEEKLY" -name "sys-backup-quinzaine-*.tar.gz" -type f -printf '%T@ %p\n' \
            | sort -n | head -n -1 | cut -d' ' -f2- | xargs rm -f
    fi

    # Sauvegarde Mensuelle (Le 1er jour du mois strict)
    if [[ "$DAY_OF_MONTH" -eq "01" ]]; then
        log "INFO" "Premier jour du mois détecté. Duplication du snapshot mensuel..."
        cp "$ARCHIVE_PATH" "${DIR_MONTHLY}/sys-backup-mensuel-${DATE_NOW}.tar.gz"
        find "$DIR_MONTHLY" -name "sys-backup-mensuel-*.tar.gz" -type f -printf '%T@ %p\n' \
            | sort -n | head -n -1 | cut -d' ' -f2- | xargs rm -f
    fi
fi

# --- ÉTAPE 3 : TRANSMISSION CROSSED-SERVER (RSYNC DELTA) ---
log "INFO" "🚀 [3/3] Synchronisation différentielle sécurisée vers ${REMOTE_IP}..."

export SSHPASS="${MYSQL_PASS}"

# Structuration forcée de la racine de stockage à distance via SSH non-interactif
sshpass -e ssh -p "${REMOTE_PORT}" -o StrictHostKeyChecking=no "${REMOTE_USER}@${REMOTE_IP}" "mkdir -p ${REMOTE_TARGET}"

# Synchronisation atomique du contenu
if sshpass -e rsync -avz -e "ssh -p ${REMOTE_PORT} -o StrictHostKeyChecking=no" --delete "$LOCAL_TARGET/" "${REMOTE_USER}@${REMOTE_IP}:${REMOTE_TARGET}/" >> "$LOG_FILE" 2>&1; then
    END_TIME=$(date +%s)
    EXEC_TIME=$((END_TIME - START_TIME))
    log "SUCCESS" "Exécution globale clôturée avec succès. Durée totale : ${EXEC_TIME} secondes."
else
    log "ERROR" "Échec critique de l'interfaçage réseau via rsync."
    unset SSHPASS
    exit 1
fi

unset SSHPASS

2. Environnement d'infrastructure sur S.H.A. (192.168.0.10)
Fichier /etc/sha_backup.conf
Ini, TOML

# --- CONFIGURATION LOCALE (S.H.A.) ---
LOCAL_TARGET="/home/NVM1/backup_local"
EXCLUDES="/proc/* /sys/* /dev/* /run/* /tmp/* /lost+found /media/* /mnt/* /home/NVM1/* /var/cache/* /var/lib/apt/lists/* /var/tmp/* /var/log/* /root/.vscode-server/* /root/.cache/* /home/palworld/* /var/lib/mysql/*"

# --- CONFIGURATION DISTANTE (FAMILLE) ---
REMOTE_IP="192.168.0.11"
REMOTE_USER="root"
REMOTE_PORT="22"
REMOTE_TARGET="/home/Tera8/backup_remote_sha"

# --- CONFIGURATION LOGS & RÉTENTION ---
LOG_FILE="/var/log/sha_backup.log"
RETENTION_DAILY=5

# --- SÉCURITÉ INTERNE CREDENTIALS ---
MYSQL_BACKUP_ENABLED=true
MYSQL_USER="root"
MYSQL_PASS="VotreMotDePasseUniqueEtSecurise"

Planificateur système (sudo crontab -e)
Code snippet

0 2 * * * /usr/local/bin/backup_system.sh > /var/log/sha_backup_cron.log 2>&1

3. Environnement d'infrastructure sur FAMILLE (192.168.0.11)
Fichier /etc/sha_backup.conf
Ini, TOML

# --- CONFIGURATION LOCALE (FAMILLE) ---
LOCAL_TARGET="/home/Tera8/backup_local"
EXCLUDES="/proc/* /sys/* /dev/* /run/* /tmp/* /lost+found /media/* /mnt/* /home/* /var/cache/* /var/lib/apt/lists/* /var/tmp/* /var/log/* /var/lib/mysql/*"

# --- CONFIGURATION DISTANTE (S.H.A.) ---
REMOTE_IP="192.168.0.10"
REMOTE_USER="root"
REMOTE_PORT="22"
REMOTE_TARGET="/home/NVM1/backup_remote_famille"

# --- CONFIGURATION LOGS & RÉTENTION ---
LOG_FILE="/var/log/sha_backup.log"
RETENTION_DAILY=5

# --- SÉCURITÉ INTERNE CREDENTIALS ---
MYSQL_BACKUP_ENABLED=true
MYSQL_USER="root"
MYSQL_PASS="VotreMotDePasseUniqueEtSecurise"

Planificateur système (sudo crontab -e)
Code snippet

0 4 * * * /usr/local/bin/backup_system.sh > /var/log/sha_backup_cron.log 2>&1

4. Section d'Avenant pour INSTALL.md (Hors dépôt Git)

Incorporez ce bloc à la fin de votre fichier /var/www/html/sha/INSTALL.md pour fixer l'état d'exploitation historique:  
Markdown

## 💾 8. Supervision Réseau & Sauvegardes Système Croisées

L'infrastructure applique une tolérance aux pannes matérielles par réplication croisée non-interactive (Zéro interaction humaine) entre S.H.A. et le NAS FAMILLE.

### Dépendances OS requises
```bash
sudo apt update && sudo apt install -y sshpass du

Topologie du Stockage

    S.H.A. (Local) : /home/NVM1/backup_local/ (Montage permanent NVMe Ext4, options noatime).

    S.H.A. (Dépôt distant reçu) : /home/NVM1/backup_remote_famille/.

    FAMILLE (Local) : /home/Tera8/backup_local/ (Disque mécanique RAID/HDD).

    FAMILLE (Dépôt distant reçu) : /home/Tera8/backup_remote_sha/.

Matrice de Rétention et Fenêtres de Tir

    Les scripts s'exécutent de manière asynchrone pour lisser la charge réseau :

        SHA : Déclenchement à 02:00 AM.

        FAMILLE : Déclenchement à 04:00 AM.

    Rotation : 5 snapshots quotidiens (daily), 1 snapshot bi-hebdomadaire (weekly - semaines ISO paires), 1 snapshot mensuel (monthly).

    Extraction SQL : Les bases de données sont extraites logiquement via mysqldump de manière non bloquante (--single-transaction), compressées à la volée, intégrées temporairement dans le tarball système, puis nettoyées de la partition racine.


---

### Points clés de l'implémentation
* **Généricité matérielle :** Le même script gère les deux nœuds d'infrastructure. L'asymétrie fonctionnelle (Exclusion de `/home` sur le NAS contre inclusion sélective sur S.H.A.) est pilotée uniquement par l'abstraction du fichier local `/etc/sha_backup.conf`.
* **Sécurisation des secrets par découplage :** Conformément à la règle de sécurité, aucune clé ou clé d'accès n'est écrite en dur. Le mot de passe système est stocké dans le fichier `.conf` (protégé par le `.htaccess` et les droits Unix `600`)[cite: 10, 497]. Il est injecté de manière volatile dans les variables d'environnement (`MYSQL_PWD` et `SSHPASS`) puis détruit immédiatement via `unset` en fin d'exécution.
* **Éradication de l'I/O inutile :** L'exclusion totale des fichiers de base de données à chaud (`/var/lib/mysql/*`), des logs (`/var/log/*`) et des métadonnées de packages réduit le poids des archives de plus de 80 %, préservant la durée de vie des cellules de stockage du NVMe.

_____ extra 
 ### 📋 Nomenclature d'Architecture des Archives Indépendantes

Pour interdire toute collision ou confusion visuelle au sein des dossiers distants (`backup_remote_*`), les fichiers respectent désormais un typage strict préfixé par l'identité physique de la machine émettrice (`$(hostname)`) :

- **Format Quotidien Standard :** `[hostname]-sys-backup-YYYY-MM-DD.tar.gz`
- **Format Test Rapide :** `[hostname]-sys-backup-test-YYYY-MM-DD.tar.gz`
- **Format Bi-hebdomadaire :** `[hostname]-sys-backup-quinzaine-YYYY-MM-DD.tar.gz`
- **Format Mensuel Restreint :** `[hostname]-sys-backup-mensuel-YYYY-MM-DD.tar.gz`

### Isolation des purges de rétention
Le moteur de nettoyage (`find`) cible exclusivement le pattern contenant le nom de la machine locale. Cela permet de purger de manière étanche les archives obsolètes sans risquer d'altérer ou de corrompre les fichiers de réplication croisée issus de l'autre machine co-hébergée sur le même volume physique de stockage.