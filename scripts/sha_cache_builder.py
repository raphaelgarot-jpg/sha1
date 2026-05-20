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
    raise FileNotFoundError("Le fichier config/app.conf est introuvable.")

def update_device_cache(ip, power):
    """Met à jour les watts et enregistre le timestamp exact du message"""
    global cached_devices
    cached_devices[ip] = {
        "power": power,
        "last_seen": int(time.time()) # Heure actuelle en secondes
    }
    write_ram_cache()

def write_ram_cache():
    """Écrit le dictionnaire enrichi en RAM"""
    payload = {
        "devices": cached_devices,
        "source": "MQTT Live Stream"
    }
    temp_file = RAM_FILE + ".tmp"
    with open(temp_file, 'w') as f:
        json.dump(payload, f)
    os.replace(temp_file, RAM_FILE)

# 2. CALLBACKS MQTT
def on_connect(client, userdata, flags, rc):
    if rc == 0:
        print("✅ Connecté au Broker MQTT. Analyse des flux temporels...")
        client.subscribe("stat/+/+")
        client.subscribe("tele/+/+")
        client.subscribe("+/status/#")
    else:
        print(f"❌ Échec de connexion MQTT (Code {rc})")

def on_message(client, userdata, msg):
    global topic_to_ip_map
    topic = msg.topic
    payload_str = msg.payload.decode('utf-8', errors='ignore')

    try:
        parts = topic.split('/')

        # --- CAS 1 : TASMOTA (On garde ta logique stable par ID) ---
        if topic.startswith("tele/") or topic.startswith("stat/"):
            device_id = parts[1] if len(parts) > 1 else parts[0]
            
            if "/STATUS5" in topic:
                data = json.loads(payload_str)
                ip = data.get("StatusNET", {}).get("IPAddress")
                if ip: topic_to_ip_map[device_id] = ip

            if device_id not in topic_to_ip_map:
                ip_match = re.search(r'192\.168\.[0-9]+\.[0-9]+', payload_str)
                if ip_match: topic_to_ip_map[device_id] = ip_match.group(0)

            ip = topic_to_ip_map.get(device_id)
            
            if ip and ("STATUS" in topic or "SENSOR" in topic):
                data = json.loads(payload_str)
                sns = data.get("StatusSNS", data)
                if 'GS303' in sns:
                    update_device_cache(ip, sns['GS303'].get('Power_cur', 0))
                elif 'ENERGY' in sns:
                    update_device_cache(ip, sns['ENERGY'].get('Power', 0))

        # --- CAS 2 : SHELLY NOUVEAU FORMAT (L'IP est dans le topic !) ---
        # Ex: shelly_192_168_0_168/status/pm1:0
        elif "shelly_192_168_" in parts[0]:
            # On extrait l'IP en remplaçant les underscores par des points
            ip = parts[0].replace("shelly_", "").replace("_", ".")
            
            if "/status/pm1:" in topic or "/status/switch:" in topic:
                data = json.loads(payload_str)
                if 'apower' in data:
                    update_device_cache(ip, data.get('apower', 0))

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