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
MAX_AGE_SECONDS = 600  # 10 minutes d'inactivité max

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

def clean_expired_devices():
    """Supprime les appareils du cache s'ils n'ont pas publié depuis trop longtemps"""
    global cached_devices
    now = int(time.time())
    expired = [ip for ip, dev in cached_devices.items() if now - dev["last_seen"] > MAX_AGE_SECONDS]
    for ip in expired:
        del cached_devices[ip]

def update_device_cache(ip, power, channel=None):
    """Met à jour la puissance par canal et calcule la somme totale par IP"""
    global cached_devices
    now = int(time.time())
    
    if ip not in cached_devices:
        cached_devices[ip] = {
            "power": 0.0,
            "channels": {},
            "last_seen": now
        }
    
    if channel is not None:
        cached_devices[ip]["channels"][str(channel)] = float(power)
        total_power = sum(cached_devices[ip]["channels"].values())
        cached_devices[ip]["power"] = round(total_power, 2)
    else:
        cached_devices[ip]["power"] = round(float(power), 2)
        
    cached_devices[ip]["last_seen"] = now
    clean_expired_devices()
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
    except Exception:
        pass

# 2. CALLBACKS MQTT (Version compatible API v1 et v2)
def on_connect(client, userdata, flags, rc, *extra_args):
    if rc == 0:
        print("✅ Connecté au Broker MQTT. Analyse du flux multi-génération...")
        # On s'abonne à tout pour ne rien rater des Shelly sans racine fixe
        client.subscribe("#")
        client.publish("cmnd/tasmota_solo/STATUS", "5")
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

        # Mouchard temporaire pour voir passer les Shelly dans la console
        if "shelly" in parts[0]:
            print(f"📥 [DEBUG SHELLY] Topic: {topic} | Payload: {payload_str[:80]}")

        # --- CAS 1 : TASMOTA ---
        if topic.startswith("tele/") or topic.startswith("stat/"):
            device_id = parts[1] if len(parts) > 1 else parts[0]

            if "STATUS5" in topic and payload_str.startswith("{"):
                try:
                    data = json.loads(payload_str)
                    ip = data.get("StatusNET", {}).get("IPAddress")
                    if ip and re.match(r'^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$', ip):
                        topic_to_ip_map[device_id] = ip
                except json.JSONDecodeError:
                    pass

            if device_id not in topic_to_ip_map and payload_str.startswith("{"):
                ip_match = re.search(r'192\.168\.\d+\.\d+', payload_str)
                if ip_match:
                    topic_to_ip_map[device_id] = ip_match.group(0)

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

# --- CAS 2 : SHELLY (COMPATIBILITÉ FINALE GEN 2 & 3) ---
        elif "shelly" in parts[0]:
            device_id = parts[0]

            # 1. Résolution de l'IP pour le format "shelly_192_168_X_X"
            if "shelly_192_168_" in device_id:
                raw_ip = device_id.replace("shelly_", "").replace("_", ".")
                if re.match(r'^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$', raw_ip):
                    topic_to_ip_map[device_id] = raw_ip

            # 2. Résolution de l'IP pour les IDs en texte (Mappage de secours ou dynamique)
            if payload_str.startswith("{"):
                try:
                    data = json.loads(payload_str)
                    sta_ip = None
                    if "wifi" in data and "sta_ip" in data["wifi"]:
                        sta_ip = data["wifi"]["sta_ip"]
                    elif "params" in data and "wifi" in data["params"] and "sta_ip" in data["params"]["wifi"]:
                        sta_ip = data["params"]["wifi"]["sta_ip"]
                        
                    if sta_ip and re.match(r'^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$', sta_ip):
                        topic_to_ip_map[device_id] = sta_ip
                except Exception:
                    pass

            # 3. EXTRACTION DIRECTE DE LA PUISSANCE (On cible les sous-topics de statut)
            if "/status/" in topic and payload_str.startswith("{"):
                try:
                    data = json.loads(payload_str)
                    if 'apower' in data:
                        # Si l'IP n'est pas encore découverte dynamiquement pour cet ID, 
                        # on utilise une correspondance temporaire basée sur tes configurations
                        ip = topic_to_ip_map.get(device_id)
                        if not ip:
                            if "e4b32317de0c" in device_id:
                                ip = "192.168.0.141"  # Ton Shelly 1PM Mini Gen3
                            elif "dcda0ce8d81c" in device_id:
                                ip = "192.168.0.153"  # Ton Shelly PM Mini Gen3
                            else:
                                return # IP inconnue, on passe

                        # Récupération du canal depuis le topic (ex: "pm1:0" ou "switch:0")
                        sub_key = parts[-1]
                        channel = sub_key.split(':')[-1] if ':' in sub_key else "0"
                        
                        # Injection immédiate de la puissance active
                        update_device_cache(ip, data.get('apower', 0.0), channel=channel)
                except Exception:
                    pass

            # 4. Lecture de secours via les évènements globaux RPC
            elif "/events/rpc" in topic and payload_str.startswith("{"):
                try:
                    data = json.loads(payload_str)
                    ip = topic_to_ip_map.get(device_id)
                    if not ip and "e4b32317de0c" in device_id: ip = "192.168.0.141"
                    if not ip and "dcda0ce8d81c" in device_id: ip = "192.168.0.153"
                    
                    if ip:
                        params = data.get("params", {})
                        for key, value in params.items():
                            if (key.startswith("pm1:") or key.startswith("switch:")) and isinstance(value, dict):
                                if 'apower' in value:
                                    channel = key.split(':')[-1]
                                    update_device_cache(ip, value.get('apower', 0.0), channel=channel)
                except Exception:
                    pass

    except Exception as e:
        print(f"⚠️ Erreur lors du traitement d'un message: {e}")

def run_mqtt_worker():
    # Déclaration compatible avec les anciennes et nouvelles versions de Paho-MQTT
    try:
        client = mqtt.Client(callback_api_version=mqtt.CallbackAPIVersion.VERSION1)
    except AttributeError:
        client = mqtt.Client()

    if MQTT_USER and MQTT_PASS:
        client.username_pw_set(MQTT_USER, MQTT_PASS)

    client.on_connect = on_connect
    client.on_message = on_message

    client.connect(MQTT_HOST, MQTT_PORT, 60)
    client.loop_forever()

if __name__ == "__main__":
    run_mqtt_worker()