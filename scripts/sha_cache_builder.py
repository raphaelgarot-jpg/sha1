#!/usr/bin/env python3
import json
import os
import re
import time
import configparser
import paho.mqtt.client as mqtt

# --- CHEMINS LOGIQUES SHA ---
APP_CONF = "/var/www/html/sha/config/app.conf"
HOME_CONF = "/var/www/html/sha/config/home_structure.conf"
RAM_FILE = "/dev/shm/sha_live.json"
MAX_AGE_SECONDS = 600  # 10 minutes max

cached_devices = {}
topic_to_ip_map = {}

# CHARGEMENT DES CONFIGURATIONS
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
    global cached_devices
    now = int(time.time())
    expired = [ip for ip, dev in cached_devices.items() if now - dev["last_seen"] > MAX_AGE_SECONDS]
    for ip in expired:
        del cached_devices[ip]

def update_device_cache(ip, power=None, state=None, channel=None):
    """Modèle de cache unifié SHA 2026 : Séparation étanche Power vs State per Channel"""
    global cached_devices
    now = int(time.time())

    if ip not in cached_devices:
        cached_devices[ip] = {
            "power": 0.0,
            "state": "OFF",
            "channels": {},        
            "channel_states": {},  
            "last_seen": now
        }

    if "channel_states" not in cached_devices[ip]:
        cached_devices[ip]["channel_states"] = {}
    if "channels" not in cached_devices[ip]:
        cached_devices[ip]["channels"] = {}

    # 1. Traitement par canal spécifié
    if channel is not None:
        ch_key = str(channel)
        if power is not None:
            p_val = round(float(power), 2)
            cached_devices[ip]["channels"][ch_key] = p_val
            if ch_key not in cached_devices[ip]["channel_states"]:
                cached_devices[ip]["channel_states"][ch_key] = "ON" if p_val > 1.5 else "OFF"

        if state is not None:
            if isinstance(state, bool):
                st_str = "ON" if state else "OFF"
            elif str(state).upper() in ["ON", "TRUE", "1", "1.0"]:
                st_str = "ON"
            else:
                st_str = "OFF"
            cached_devices[ip]["channel_states"][ch_key] = st_str
    
    # 2. Traitement global
    if channel is None:
        if power is not None:
            cached_devices[ip]["power"] = round(float(power), 2)
        if state is not None:
            if isinstance(state, bool):
                cached_devices[ip]["state"] = "ON" if state else "OFF"
            elif str(state).upper() in ["ON", "TRUE", "1"]:
                cached_devices[ip]["state"] = "ON"
            else:
                cached_devices[ip]["state"] = "OFF"
    else:
        # Consolidation automatique des puissances globales
        if cached_devices[ip]["channels"]:
            cached_devices[ip]["power"] = round(sum(cached_devices[ip]["channels"].values()), 2)
        if cached_devices[ip]["channel_states"]:
            if any(v == "ON" for v in cached_devices[ip]["channel_states"].values()):
                cached_devices[ip]["state"] = "ON"
            else:
                cached_devices[ip]["state"] = "OFF"

    cached_devices[ip]["last_seen"] = now
    clean_expired_devices()
    write_ram_cache()

def discover_and_ping_pcs():
    global cached_devices
    if not os.path.exists(HOME_CONF): return
    try:
        with open(HOME_CONF, 'r', encoding='utf-8') as f: lines = f.readlines()
    except Exception: return

    updated = False
    for line in lines:
        if "pc|" in line:
            match = re.search(r'pc\|(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})', line)
            if match:
                ip = match.group(1)
                is_online = os.system(f"ping -c 1 -W 0.5 {ip} > /dev/null 2>&1") == 0
                now = int(time.time())
                cached_devices[ip] = {
                    "power": 0.0, "state": "ON" if is_online else "OFF",
                    "channels": {}, "channel_states": {}, "last_seen": now
                }
                updated = True
    if updated: write_ram_cache()

def write_ram_cache():
    payload = {"devices": cached_devices, "source": "MQTT Live Stream", "updated_at": int(time.time())}
    temp_file = RAM_FILE + ".tmp"
    try:
        with open(temp_file, 'w') as f: json.dump(payload, f, indent=2)
        os.replace(temp_file, RAM_FILE)
    except Exception: pass

def on_connect(client, userdata, flags, rc, *extra_args):
    if rc == 0:
        client.subscribe("#")
        client.publish("cmnd/tasmota_solo/STATUS", "5")
    else: print(f"❌ MQTT Error {rc}")

def on_message(client, userdata, msg):
    global topic_to_ip_map
    topic = msg.topic
    try: payload_str = msg.payload.decode('utf-8', errors='ignore').strip()
    except Exception: return

    try:
        parts = topic.split('/')
        device_id = parts[0]

        if topic.startswith("tele/") or topic.startswith("stat/"):
            lookup_id = parts[1] if len(parts) > 1 else device_id

            if "STATUS5" in topic and payload_str.startswith("{"):
                try:
                    data = json.loads(payload_str)
                    ip = data.get("StatusNET", {}).get("IPAddress")
                    if ip and re.match(r'^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$', ip):
                        topic_to_ip_map[lookup_id] = ip
                        update_device_cache(ip)
                except Exception: pass

            if lookup_id not in topic_to_ip_map and payload_str.startswith("{"):
                ip_match = re.search(r'192\.168\.\d+\.\d+', payload_str)
                if ip_match: topic_to_ip_map[lookup_id] = ip_match.group(0)

            ip = topic_to_ip_map.get(lookup_id)
            if ip and payload_str.startswith("{"):
                try:
                    data = json.loads(payload_str)
                    for key, val in data.items():
                        if key.startswith("POWER"):
                            channel_num = key.replace("POWER", "")
                            if channel_num == "": channel_num = "1"
                            update_device_cache(ip, state=val, channel=channel_num)

                    sns = data.get("StatusSNS", data)
                    if 'ENERGY' in sns and isinstance(sns['ENERGY'], dict):
                        update_device_cache(ip, power=sns['ENERGY'].get('Power'))
                    elif 'GS303' in sns and isinstance(sns['GS303'], dict):
                        update_device_cache(ip, power=sns['GS303'].get('Power_cur'))
                except Exception: pass
        else:
            # --- DÉLESTAGE DES MODULES SHELLY NATIFS (GEN2 & GEN3) ---
            normalized_id = device_id.replace("_", ".")
            ip_match = re.search(r'192\.168\.\d+\.\d+', normalized_id)
            if not ip_match: return
            ip = ip_match.group(0)

            if payload_str.startswith("{"):
                try:
                    data = json.loads(payload_str)
                    
                    # 💡 DECODEUR SPECIAL : COMPTEURS TRIPHASÉS (Shelly Pro 3EM / Pro 3PM)
                    em_data = None
                    if "em:0" in data:
                        em_data = data["em:0"]
                    elif "em:0" in topic:
                        em_data = data
                    elif "events" in topic or "rpc" in topic:
                        if "params" in data and "em:0" in data["params"]:
                            em_data = data["params"]["em:0"]
                    
                    if em_data and isinstance(em_data, dict):
                        p_a = float(em_data.get("a_act_power", 0.0))
                        p_b = float(em_data.get("b_act_power", 0.0))
                        p_c = float(em_data.get("c_act_power", 0.0))
                        
                        # Ventilation par phase dans les sous-canaux
                        update_device_cache(ip, power=p_a, channel="0")
                        update_device_cache(ip, power=p_b, channel="1")
                        update_device_cache(ip, power=p_c, channel="2")
                        return  # Traitement triphasé validé, on stoppe ici

                    # Décodeur classique (Shelly monocanal ou Pro multiprises)
                    if "status" in topic:
                        sub_key = parts[-1]
                        channel = sub_key.split(':')[-1] if ':' in sub_key else "0"
                        if 'apower' in data or 'output' in data:
                            update_device_cache(ip, power=data.get('apower'), state=data.get('output'), channel=channel)

                        for key in list(data.keys()):
                            if (key.startswith("pm1:") or key.startswith("switch:")) and isinstance(data[key], dict):
                                ch = key.split(':')[-1]
                                update_device_cache(ip, power=data[key].get('apower'), state=data[key].get('output'), channel=ch)

                    elif "events" in topic or "rpc" in topic:
                        root_data = data.get("params", data)
                        for key in list(root_data.keys()):
                            if (key.startswith("pm1:") or key.startswith("switch:")) and isinstance(root_data[key], dict):
                                ch = key.split(':')[-1]
                                update_device_cache(ip, power=root_data[key].get('apower'), state=root_data[key].get('output'), channel=ch)
                except Exception: pass
            else:
                if "/relay/" in topic and topic.endswith("/power"):
                    try: update_device_cache(ip, power=float(payload_str), channel=parts[2])
                    except ValueError: pass
                elif "/relay/" in topic and re.match(r'^\d+$', parts[-1]):
                    update_device_cache(ip, state=payload_str, channel=parts[-1])
    except Exception: pass

def run_cache_builder():
    try: client = mqtt.Client(callback_api_version=mqtt.CallbackAPIVersion.VERSION1)
    except AttributeError: client = mqtt.Client()
    if MQTT_USER and MQTT_PASS: client.username_pw_set(MQTT_USER, MQTT_PASS)
    client.on_connect = on_connect
    client.on_message = on_message
    client.connect(MQTT_HOST, MQTT_PORT, 60)
    client.loop_start()

    last_tasmota_status = 0
    last_pc_ping = 0
    try:
        while True:
            now = time.time()
            if now - last_tasmota_status > 300:
                client.publish("cmnd/tasmota_solo/STATUS", "5")
                last_tasmota_status = now
            if now - last_pc_ping > 15:
                discover_and_ping_pcs()
                last_pc_ping = now
            time.sleep(1)
    except KeyboardInterrupt: client.loop_stop()

if __name__ == "__main__":
    run_cache_builder()