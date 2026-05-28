#!/usr/bin/env python3
import json
import os
import re
import time
import configparser
import subprocess
import paho.mqtt.client as mqtt

# --- CHEMINS LOGIQUES SHA ---
APP_CONF = "/var/www/html/sha/config/app.conf"
HOME_CONF = "/var/www/html/sha/config/home_structure.conf"
RAM_FILE = "/dev/shm/sha_live.json"
MAX_AGE_SECONDS = 600

cached_devices = {}
topic_to_ip_map = {}

config = configparser.ConfigParser()
if os.path.exists(APP_CONF):
    config.read(APP_CONF)
    MQTT_HOST = config.get('MQTT', 'host', fallback='127.0.0.1').strip('"')
    MQTT_PORT = int(config.get('MQTT', 'port', fallback='1883'))
    MQTT_USER = config.get('MQTT', 'user', fallback='').strip('"')
    MQTT_PASS = config.get('MQTT', 'password', fallback='').strip('"')
else:
    raise FileNotFoundError("Le fichier config/app.conf est introuvable.")

def get_android_state(ip):
    try:
        # Tente d'exécuter la commande ADB avec un timeout de 2 secondes
        cmd = f"adb -s {ip} shell dumpsys power"
        result = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=2)

        # Si ADB répond correctement et contient l'état d'affichage
        if "state=ON" in result.stdout:
            return "ON"
        else:
            return "OFF"
    except subprocess.TimeoutExpired:
        # Si l'appareil est déconnecté du réseau ou en veille profonde
        return "OFF"
    except Exception:
        return "OFF"

def clean_expired_devices():
    global cached_devices
    now = int(time.time())
    expired = [ip for ip, dev in cached_devices.items() if now - dev["last_seen"] > MAX_AGE_SECONDS]
    for ip in expired: del cached_devices[ip]

def update_device_cache(ip, power=None, state=None, channel=None, dimmer=None, temperature=None, mqtt_name=None):
    global cached_devices
    now = int(time.time())

    if ip not in cached_devices:
        cached_devices[ip] = {"power": 0.0, "state": "OFF", "channels": {}, "channel_states": {}, "dimmer": 100, "temperature": 154, "last_seen": now}

    if mqtt_name is not None:
        cached_devices[ip]["mqtt_name"] = mqtt_name

    if channel is not None:
        ch_key = str(channel)
        if power is not None:
            p_val = round(float(power), 2)
            cached_devices[ip]["channels"][ch_key] = p_val
            cached_devices[ip]["channel_states"][ch_key] = "ON" if p_val > 1.5 else "OFF"
        if state is not None:
            cached_devices[ip]["channel_states"][ch_key] = "ON" if str(state).upper() in ["ON", "TRUE", "1"] else "OFF"

    if channel is None:
        if power is not None: cached_devices[ip]["power"] = round(float(power), 2)
        if state is not None: cached_devices[ip]["state"] = "ON" if str(state).upper() in ["ON", "TRUE", "1"] else "OFF"
    else:
        if cached_devices[ip]["channels"]: cached_devices[ip]["power"] = round(sum(cached_devices[ip]["channels"].values()), 2)
        if cached_devices[ip]["channel_states"]:
            cached_devices[ip]["state"] = "ON" if any(v == "ON" for v in cached_devices[ip]["channel_states"].values()) else "OFF"

    if dimmer is not None:
        try: cached_devices[ip]["dimmer"] = int(dimmer)
        except: pass
    if temperature is not None:
        try: cached_devices[ip]["temperature"] = int(temperature)
        except: pass

    cached_devices[ip]["last_seen"] = now
    clean_expired_devices()
    write_ram_cache()

def discover_and_ping_pcs():
    global cached_devices
    if not os.path.exists(HOME_CONF): return
    try:
        with open(HOME_CONF, 'r', encoding='utf-8') as f: lines = f.readlines()
    except: return
    updated = False

    for line in lines:
        line = line.strip()
        # Évite les lignes de commentaires ou vides
        if not line or line.startswith("#"): continue

        # --- ANCIEN CAS : LES PC ---
        if "pc|" in line:
            match = re.search(r'pc\|(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})', line)
            if match:
                ip = match.group(1)
                is_online = os.system(f"ping -c 1 -W 0.5 {ip} > /dev/null 2>&1") == 0
                cached_devices[ip] = {"power": 0.0, "state": "ON" if is_online else "OFF", "channels": {}, "channel_states": {}, "last_seen": int(time.time())}
                updated = True

        # --- CAS 2 : LES APPAREILS ANDROID (Avec gestion MAC) ---
        elif "android|" in line:
            # Extraction de l'IP (groupe 1) et de la MAC (groupe 2)
            match = re.search(r'android\|(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\|([0-9A-Fa-f:]{17})', line)
            if match:
                ip = match.group(1)
                # Note : On n'utilise pas la MAC dans le monitoring d'état,
                # mais elle est bien lue et prête si besoin.

                state = get_android_state(ip)

                cached_devices[ip] = {
                    "power": 0.0,
                    "state": state,
                    "channels": {},
                    "channel_states": {"1": state},
                    "last_seen": int(time.time())
                }
                updated = True

    if updated: write_ram_cache()

def write_ram_cache():
    payload = {"devices": cached_devices, "source": "MQTT Live Stream", "updated_at": int(time.time())}
    temp_file = RAM_FILE + ".tmp"
    try:
        with open(temp_file, 'w') as f: json.dump(payload, f, indent=2)
        os.replace(temp_file, RAM_FILE)
    except: pass

def on_connect(client, userdata, flags, rc, *extra_args):
    if rc == 0:
        client.subscribe("#")
        client.publish("cmnd/tasmota_solo/STATUS", "5")
    else: print(f"❌ MQTT Error {rc}")

def on_message(client, userdata, msg):
    global topic_to_ip_map
    topic = msg.topic
    try: payload_str = msg.payload.decode('utf-8', errors='ignore').strip()
    except: return

    try:
        parts = topic.split('/')
        device_id = parts[0]

        # --- CAS 1 : TASMOTA ---
        if topic.startswith("tele/") or topic.startswith("stat/"):
            lookup_id = parts[1] if len(parts) > 1 else device_id
            if "STATUS5" in topic and payload_str.startswith("{"):
                try:
                    ip = json.loads(payload_str).get("StatusNET", {}).get("IPAddress")
                    if ip and re.match(r'^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$', ip):
                        topic_to_ip_map[lookup_id] = ip
                        update_device_cache(ip)
                except: pass

            if lookup_id not in topic_to_ip_map and payload_str.startswith("{"):
                ip_match = re.search(r'192\.168\.\d+\.\d+', payload_str)
                if ip_match: topic_to_ip_map[lookup_id] = ip_match.group(0)

            ip = topic_to_ip_map.get(lookup_id)
            if ip and payload_str.startswith("{"):
                try:
                    data = json.loads(payload_str)
                    for key, val in data.items():
                        if key.startswith("POWER"): update_device_cache(ip, state=val, channel=key.replace("POWER", "") or "1")
                    sns = data.get("StatusSNS", data)
                    if 'ENERGY' in sns and isinstance(sns['ENERGY'], dict): update_device_cache(ip, power=sns['ENERGY'].get('Power'))
                    elif 'GS303' in sns and isinstance(sns['GS303'], dict): update_device_cache(ip, power=sns['GS303'].get('Power_cur'))
                except: pass


        
# --- CAS 2 : APPAREILS APYRAMIDAUX (OPENBEKEN & SHELLY GEN 1) ---
        elif device_id.startswith("obk") or "shellies" in topic or "OpenBK" in topic:
            # REVOLUTION DE L'ID UNIQUE : On extrait le vrai nom (index 1) si la racine est générique
            resolved_id = device_id
            if device_id == "shellies" and len(parts) > 1:
                resolved_id = parts[1]
            elif "OpenBK" in topic and len(parts) > 1 and not device_id.startswith("obk"):
                resolved_id = parts[1] if parts[1].startswith("obk") else device_id

            # Extraction et association de l'IP avec le VRAI ID unique résolu
            ip_match = re.search(r'192\.168\.\d+\.\d+', payload_str)
            if ip_match: 
                topic_to_ip_map[resolved_id] = ip_match.group(0)
            
            ip = topic_to_ip_map.get(resolved_id)
            if ip:
                # On enregistre la correspondance dans sha_live.json
                update_device_cache(ip, mqtt_name=resolved_id)
                
                # 🚀 1. PRIORITÉ ABSOLUE : Statut Maître via led_enableAll (gère led_enableAll et led_enableAll/get)
                if "led_enableAll" in topic:
                    new_state = "ON" if payload_str in ["1", "ON", "TRUE"] else "OFF"
                    update_device_cache(ip, state=new_state)
                    update_device_cache(ip, state=new_state, channel="1")
                    update_device_cache(ip, state=new_state, channel="0")
                
                # 🚀 2. LEVIER SECONDAIRE : L'ordre d'intensité /1/set
                elif topic.endswith("/1/set") or (topic.endswith("/1") and not topic.endswith("/get")):
                    try:
                        val = int(float(payload_str))
                        if val == 0:
                            update_device_cache(ip, state="OFF")
                            update_device_cache(ip, state="OFF", channel="1")
                            update_device_cache(ip, state="OFF", channel="0")
                        else:
                            update_device_cache(ip, dimmer=val, state="ON")
                            update_device_cache(ip, state="ON", channel="1")
                            update_device_cache(ip, state="ON", channel="0")
                    except: pass

                # 🚀 3. VALEUR DE GRADATION : Lecture seule de l'intensité
                elif "led_dimmer" in topic:
                    try:
                        dim_val = int(float(payload_str))
                        if dim_val > 0:
                            current_cached_dimmer = cached_devices.get(ip, {}).get("dimmer")
                            if current_cached_dimmer is None:
                                update_device_cache(ip, dimmer=dim_val)
                    except: pass
                    
                elif "led_temperature" in topic or topic.endswith("/0/set") or topic.endswith("/0"): 
                    update_device_cache(ip, temperature=payload_str)
                    
                elif "connected" in topic: 
                    update_device_cache(ip)

        # --- CAS 3 : SHELLY (Extraction d'IP adaptative) ---
        elif "192_168_" in device_id or device_id.startswith("shelly"):
            ip_match = re.search(r'192_168_\d+_\d+', device_id)
            if not ip_match: return
            ip = ip_match.group(0).replace("_", ".")
            update_device_cache(ip, mqtt_name=device_id)
            if payload_str.startswith("{"):
                try:
                    data = json.loads(payload_str)
                    em_data = data.get("em:0") or (data["params"]["em:0"] if "params" in data and "em:0" in data["params"] else None)
                    if "em:0" in topic: em_data = data

                    if em_data and isinstance(em_data, dict):
                        update_device_cache(ip, power=float(em_data.get("a_act_power", 0.0)), channel="0")
                        update_device_cache(ip, power=float(em_data.get("b_act_power", 0.0)), channel="1")
                        update_device_cache(ip, power=float(em_data.get("c_act_power", 0.0)), channel="2")
                        return

                    if "status" in topic:
                        ch = parts[-1].split(':')[-1] if ':' in parts[-1] else "0"
                        if 'apower' in data or 'output' in data: update_device_cache(ip, power=data.get('apower'), state=data.get('output'), channel=ch)
                        for k in data:
                            if (k.startswith("pm1:") or k.startswith("switch:")) and isinstance(data[k], dict):
                                update_device_cache(ip, power=data[k].get('apower'), state=data[k].get('output'), channel=k.split(':')[-1])
                    elif "events" in topic or "rpc" in topic:
                        rd = data.get("params", data)
                        for k in rd:
                            if (k.startswith("pm1:") or k.startswith("switch:")) and isinstance(rd[k], dict):
                                update_device_cache(ip, power=rd[k].get('apower'), state=rd[k].get('output'), channel=k.split(':')[-1])
                except: pass
            else:
                if "/relay/" in topic and topic.endswith("/power"): update_device_cache(ip, power=float(payload_str), channel=parts[2])
                elif "/relay/" in topic: update_device_cache(ip, state=payload_str, channel=parts[-1])
    except: pass

def run_cache_builder():
    try: client = mqtt.Client(callback_api_version=mqtt.CallbackAPIVersion.VERSION1)
    except: client = mqtt.Client()
    if MQTT_USER and MQTT_PASS: client.username_pw_set(MQTT_USER, MQTT_PASS)
    client.on_connect = on_connect
    client.on_message = on_message
    client.connect(MQTT_HOST, MQTT_PORT, 60)
    client.loop_start()
    l_tasmota, l_pc = 0, 0
    while True:
        now = time.time()
        if now - l_tasmota > 300: client.publish("cmnd/tasmota_solo/STATUS", "5"); l_tasmota = now
        if now - l_pc > 15: discover_and_ping_pcs(); l_pc = now
        time.sleep(1)

if __name__ == "__main__": run_cache_builder()