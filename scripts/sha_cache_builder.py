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

def update_device_cache(ip, power=None, state=None, channel=None):
    """Met à jour le cache centralisé de la RAM de manière générique"""
    global cached_devices
    now = int(time.time())

    if ip not in cached_devices:
        cached_devices[ip] = {
            "power": 0.0,
            "state": "OFF",
            "channels": {},
            "last_seen": now
        }

    # Mise à jour de la puissance active
    if power is not None:
        p_val = float(power)
        if channel is not None:
            cached_devices[ip]["channels"][str(channel)] = p_val
            cached_devices[ip]["power"] = round(sum(cached_devices[ip]["channels"].values()), 2)
        else:
            cached_devices[ip]["power"] = round(p_val, 2)
            
        if state is None:
            cached_devices[ip]["state"] = "ON" if cached_devices[ip]["power"] > 2.0 else "OFF"

    # Mise à jour de l'état binaire (ON/OFF)
    if state is not None:
        if isinstance(state, bool):
            cached_devices[ip]["state"] = "ON" if state else "OFF"
        elif str(state).upper() in ["ON", "TRUE", "1"]:
            cached_devices[ip]["state"] = "ON"
        else:
            cached_devices[ip]["state"] = "OFF"

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

# 2. CALLBACKS MQTT
def on_connect(client, userdata, flags, rc, *extra_args):
    if rc == 0:
        print("✅ Connecté au Broker MQTT. Analyse du flux S.H.A. en cours...")
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
        device_id = parts[0]
        
        # --- CAS 1 : TASMOTA (Ancien flux standard) ---
        if topic.startswith("tele/") or topic.startswith("stat/"):
            lookup_id = parts[1] if len(parts) > 1 else device_id

            if "STATUS5" in topic and payload_str.startswith("{"):
                try:
                    data = json.loads(payload_str)
                    ip = data.get("StatusNET", {}).get("IPAddress")
                    if ip and re.match(r'^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$', ip):
                        topic_to_ip_map[lookup_id] = ip
                except Exception: pass

            if lookup_id not in topic_to_ip_map and payload_str.startswith("{"):
                ip_match = re.search(r'192\.168\.\d+\.\d+', payload_str)
                if ip_match:
                    topic_to_ip_map[lookup_id] = ip_match.group(0)

            ip = topic_to_ip_map.get(lookup_id)
            if ip and payload_str.startswith("{"):
                try:
                    data = json.loads(payload_str)
                    sns = data.get("StatusSNS", data)
                    if 'ENERGY' in sns and isinstance(sns['ENERGY'], dict):
                        update_device_cache(ip, power=sns['ENERGY'].get('Power'), state=sns['ENERGY'].get('Process'))
                    elif 'GS303' in sns and isinstance(sns['GS303'], dict):
                        update_device_cache(ip, power=sns['GS303'].get('Power_cur'))
                except Exception: pass

        # --- CAS 2 : TOUT APPAREIL AVEC IP DANS LE NOM (Shelly Gen1/Gen2/Gen3) ---
        else:
            # Recherche d'une IP au format 192.168.X.X (avec séparateurs _ ou .) dans le nom du topic
            # Exemple toléré : shelly_Heizung_192.168.0.98, shelly_KS_192_168_0_153, etc.
            normalized_id = device_id.replace("_", ".")
            ip_match = re.search(r'192\.168\.\d+\.\d+', normalized_id)
            
            if not ip_match:
                return  # Si pas d'IP dans le topic, on ignore
                
            ip = ip_match.group(0)

            if payload_str.startswith("{"):
                try:
                    data = json.loads(payload_str)
                    
                    # Détection dans le sous-topic ciblé (ex: status/switch:0 ou status/pm1:0)
                    if "status" in topic:
                        sub_key = parts[-1]
                        channel = sub_key.split(':')[-1] if ':' in sub_key else "0"
                        
                        # Lecture directe racine du sous-topic
                        if 'apower' in data or 'output' in data:
                            update_device_cache(ip, power=data.get('apower'), state=data.get('output'), channel=channel)
                        
                        # Lecture dans le dictionnaire imbriqué (si présent)
                        for key in list(data.keys()):
                            if (key.startswith("pm1:") or key.startswith("switch:")) and isinstance(data[key], dict):
                                ch = key.split(':')[-1]
                                update_device_cache(ip, power=data[key].get('apower'), state=data[key].get('output'), channel=ch)
                                
                    # Détection dans les évènements globaux RPC (events/rpc)
                    elif "events" in topic or "rpc" in topic:
                        root_data = data.get("params", data)
                        for key in list(root_data.keys()):
                            if (key.startswith("pm1:") or key.startswith("switch:")) and isinstance(root_data[key], dict):
                                ch = key.split(':')[-1]
                                update_device_cache(ip, power=root_data[key].get('apower'), state=root_data[key].get('output'), channel=ch)
                except Exception: pass
            else:
                # Fallback texte brut pour Shelly Gen1
                if "/relay/" in topic and topic.endswith("/power"):
                    try: update_device_cache(ip, power=float(payload_str), channel=parts[2])
                    except ValueError: pass
                elif "/relay/" in topic and re.match(r'^\d+$', parts[-1]):
                    update_device_cache(ip, state=payload_str, channel=parts[-1])

    except Exception as e:
        print(f"⚠️ Erreur traitement message: {e}")

def run_mqtt_worker():
    try: client = mqtt.Client(callback_api_version=mqtt.CallbackAPIVersion.VERSION1)
    except AttributeError: client = mqtt.Client()

    if MQTT_USER and MQTT_PASS:
        client.username_pw_set(MQTT_USER, MQTT_PASS)

    client.on_connect = on_connect
    client.on_message = on_message
    client.connect(MQTT_HOST, MQTT_PORT, 60)
    client.loop_forever()

if __name__ == "__main__":
    run_mqtt_worker()