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
    
    # Si l'IP n'existe pas, on initialise la structure multi-canal
    if ip not in cached_devices:
        cached_devices[ip] = {
            "power": 0.0,
            "channels": {},
            "last_seen": now
        }
    
    # Si un canal est spécifié (cas des modules Shelly multi-voies)
    if channel is not None:
        cached_devices[ip]["channels"][str(channel)] = float(power)
        # La puissance totale est la somme de tous les canaux actifs de cet appareil
        total_power = sum(cached_devices[ip]["channels"].values())
        cached_devices[ip]["power"] = round(total_power, 2)
    else:
        # Cas standard mono-canal (Tasmota standard)
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
    except Exception as e:
        pass

# 2. CALLBACKS MQTT
def on_connect(client, userdata, flags, rc):
    if rc == 0:
        print("✅ Connecté au Broker MQTT. Analyse du flux multi-génération Shelly & Tasmota...")
        client.subscribe("stat/+/+")
        client.subscribe("tele/+/+")
        client.subscribe("+/relay/#") 
        client.subscribe("+/status/#")
        client.subscribe("+/events/rpc") # Canal maître pour Shelly Gen3
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
                        raw_power = sns['GS303'].get('Power_cur', 0)
                        factor = sns['GS303'].get('Factor', 1.0)
                        final_power = (raw_power / factor) if factor > 0 else raw_power
                        update_device_cache(ip, final_power)
                        
                    elif 'ENERGY' in sns:
                        # On privilégie la Puissance Apparente native de Tasmota (VA)
                        if 'ApparentPower' in sns['ENERGY']:
                            final_power = sns['ENERGY']['ApparentPower']
                        elif 'Power' in sns['ENERGY']:
                            # Fallback : calcul manuel avec le Power Factor
                            raw_power = sns['ENERGY'].get('Power', 0)
                            factor = sns['ENERGY'].get('Factor', 1.0)
                            final_power = (raw_power / factor) if factor > 0 else raw_power
                        else:
                            final_power = 0
                            
                        update_device_cache(ip, final_power)
                        
                except json.JSONDecodeError:
                    pass

        # --- CAS 2 : SHELLY (COMPATIBILITÉ TOTALE GEN 1 ET GEN 2/3) ---
        elif "shelly_192_168_" in parts[0]:
            raw_ip = parts[0].replace("shelly_", "").replace("_", ".")
            if re.match(r'^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$', raw_ip):
                ip = raw_ip

                # SOUS-CAS A : NOUVEAU FLUX RPC (Shelly Gen3)
                if "/events/rpc" in topic and payload_str.startswith("{"):
                    try:
                        data = json.loads(payload_str)
                        params = data.get("params", {})
                        
                        for key, value in params.items():
                            if (key.startswith("pm1:") or key.startswith("switch:")) and isinstance(value, dict):
                                if 'apower' in value:
                                    channel = key.split(':')[-1]
                                    raw_power = value.get('apower', 0)
                                    factor = value.get('pf', 1.0)
                                    final_power = (raw_power / factor) if factor > 0 else raw_power
                                    update_device_cache(ip, final_power, channel=channel)
                    except json.JSONDecodeError:
                        pass

                # SOUS-CAS B : Shelly Récent / Gen 2+ (Format JSON standard)
                elif ("/status/pm1:" in topic or "/status/switch:" in topic) and payload_str.startswith("{"):
                    try:
                        data = json.loads(payload_str)
                        if 'apower' in data:
                            channel = parts[2].split(':')[-1] if ':' in parts[2] else "0"
                            raw_power = data.get('apower', 0)
                            factor = data.get('pf', 1.0)
                            final_power = (raw_power / factor) if factor > 0 else raw_power
                            update_device_cache(ip, final_power, channel=channel)
                    except json.JSONDecodeError:
                        pass

                # SOUS-CAS C : Shelly Ancien / Gen 1 (Format Texte Brut / Raw)
                elif "/relay/" in topic and topic.endswith("/power"):
                    try:
                        raw_power = float(payload_str)
                        channel = parts[2]
                        update_device_cache(ip, raw_power, channel=channel)
                    except ValueError:
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
