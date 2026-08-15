#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
S.H.A. 2026 - Démon Passif CUL / MAX! Cube 2 (Passerelle a-culfw 868 MHz)
Fichier : /opt/zabbix-docker/apps/sha/scripts/maxcube_cul_daemon.py
- Écoute passive stricte filtrée sur home_structure.conf.
- Création automatique des appareils manquants dans le cache JSON.
- Reprise de l'ancien cache au démarrage (pas de reset).
- Traitement des ordres Web (Température & Boost) selon la trame expert éprouvée.
- Écriture atomique dans data/heiz_cul_out.json.
"""

import sys
import os
import time
import socket
import json
import configparser
import select
import re

# --- CHEMINS DYNAMIQUES DU PROJET ---
BASE_DIR       = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
APP_CONF_FILE  = os.path.join(BASE_DIR, "config", "app.conf")
HOME_CONF_FILE = os.path.join(BASE_DIR, "config", "home_structure.conf")
OUT_FILE       = os.path.join(BASE_DIR, "data", "heiz_cul_out.json")
CMD_FILE       = os.path.join(BASE_DIR, "data", "heiz_cmd.json")

DEVICE_CACHE = {}
DEVICE_METADATA = {}

SYSTEM_STATUS = {
    "cul_status": "DISCONNECTED",
    "duty_val": 0,
    "slots_val": 64,
    "duty_color": "var(--green)",
    "firmware": "a-culfw 868MHz",
    "updated_at": int(time.time())
}

# ==============================================================================
# 1. CONFIGURATION & CACHE
# ==============================================================================

def load_cube2_config():
    if not os.path.exists(APP_CONF_FILE):
        print(f"❌ [CONF] Fichier introuvable : {APP_CONF_FILE}", flush=True)
        return None, None, "000000"

    config = configparser.ConfigParser()
    try:
        config.read(APP_CONF_FILE, encoding='utf-8')
    except Exception as e:
        print(f"⚠️ [CONF] Erreur lecture {APP_CONF_FILE} : {e}", flush=True)
        return None, None, "000000"

    selected_sec = None
    for sec in ["Cube2", "cube2", "CUBE2"]:
        if config.has_section(sec):
            selected_sec = sec
            break

    if not selected_sec:
        print(f"❌ [CONF] Section [Cube2] absente dans {APP_CONF_FILE}", flush=True)
        return None, None, "000000"

    ip = config.get(selected_sec, 'ip', fallback='').strip('"\'; ')
    src_rf = config.get(selected_sec, 'src_rf', fallback='000000').strip('"\'; ').lower()
    
    try:
        port = int(config.get(selected_sec, 'port', fallback='62910').strip('"\'; '))
    except ValueError:
        port = 62910

    if not ip:
        print(f"❌ [CONF] IP non configurée", flush=True)
        return None, None, "000000"

    return ip, port, src_rf

def load_device_metadata():
    global DEVICE_METADATA
    DEVICE_METADATA = {}
    if not os.path.exists(HOME_CONF_FILE): return

    config = configparser.ConfigParser(strict=False)
    try:
        config.read(HOME_CONF_FILE, encoding='utf-8')
        for section in config.sections():
            if section in ['System', 'Defaults']: continue

            if config.has_option(section, 'heizung_id'):
                h_val = config.get(section, 'heizung_id').strip('"\'; ')
                if h_val and h_val.lower() != 'none':
                    parts = h_val.split('|')
                    rf = (parts[1] if len(parts) > 1 else parts[0]).strip().lower()
                    if re.match(r'^[0-9a-f]{6}$', rf):
                        DEVICE_METADATA[rf] = {"room": section.upper(), "label": f"Thermostat {section}", "type": "heizung"}

            for key, val in config.items(section):
                if 'devices' in key or key.startswith('devices'):
                    lines = val.split('\n') if '\n' in val else [val]
                    for line in lines:
                        parts = line.strip('"\'; ').split('|')
                        if len(parts) >= 2:
                            dtype = parts[0].strip().lower()
                            rf = parts[1].strip().lower()
                            label = parts[2].strip() if len(parts) > 2 else f"{dtype} {section}"
                            if len(parts) >= 5: label = parts[3].strip()
                            if re.match(r'^[0-9a-f]{6}$', rf):
                                DEVICE_METADATA[rf] = {"room": section.upper(), "label": label, "type": dtype}
        print(f"📋 [MAP] {len(DEVICE_METADATA)} appareil(s) déclaré(s).", flush=True)
    except Exception as e:
        print(f"⚠️ [CONF] Erreur lecture : {e}", flush=True)

def load_persisted_cache():
    global DEVICE_CACHE, SYSTEM_STATUS
    if os.path.exists(OUT_FILE) and os.path.getsize(OUT_FILE) > 0:
        try:
            with open(OUT_FILE, 'r', encoding='utf-8') as f:
                data = json.load(f)
                if isinstance(data, dict) and isinstance(data.get("devices"), dict):
                    for rf, dev in data["devices"].items():
                        rf_clean = rf.lower().strip()
                        if rf_clean in DEVICE_METADATA:
                            DEVICE_CACHE[rf_clean] = dev
                    print(f"📦 [CACHE] Reprise de {len(DEVICE_CACHE)} état(s) en RAM.", flush=True)
                
                if isinstance(data, dict) and "system" in data and "firmware" in data["system"]:
                    SYSTEM_STATUS["firmware"] = data["system"]["firmware"]
        except Exception as e:
            print(f"⚠️ [CACHE] Erreur restauration : {e}", flush=True)

    for rf, meta in DEVICE_METADATA.items():
        if rf not in DEVICE_CACHE:
            base_dev = {
                "id": rf, "src_rf": rf, "type": meta["type"], "category": meta["type"],
                "room": meta["room"], "label": meta["label"], "last_seen": 0
            }
            if meta["type"] == "heizung":
                base_dev.update({"mode": "AUTO", "soll": 20.0, "ist": "--", "valve_pos": "0%", "batt": False, "err": False, "boost": False, "win": False})
            elif meta["type"] == "fensterkontakt":
                base_dev.update({"state": "CLOSED", "is_open": False, "batt": False, "win": False})
            
            DEVICE_CACHE[rf] = base_dev
            print(f"➕ [INIT] Création par défaut pour [{rf}] ({meta['label']})", flush=True)

def write_json_cache():
    duty = SYSTEM_STATUS.get("duty_val", 0)
    SYSTEM_STATUS["duty_color"] = "var(--red)" if duty > 80 else ("var(--orange)" if duty > 50 else "var(--green)")
    SYSTEM_STATUS["updated_at"] = int(time.time())
    
    output = {"system": SYSTEM_STATUS, "devices": DEVICE_CACHE}

    os.makedirs(os.path.dirname(OUT_FILE), exist_ok=True)
    tmp_file = OUT_FILE + ".tmp"
    try:
        with open(tmp_file, 'w', encoding='utf-8') as f:
            json.dump(output, f, indent=4, ensure_ascii=False)
        os.replace(tmp_file, OUT_FILE)
    except Exception as e:
        print(f"❌ [DISK] Erreur écriture JSON : {e}", flush=True)

# ==============================================================================
# 2. DÉCODEUR RADIO MAX! CUL
# ==============================================================================

def parse_cul_max_packet(raw_line):
    line = raw_line.strip()
    if not line.startswith("Z") or len(line) < 18: return None
    if line.startswith(("Zs", "Zr", "Za", "Zx", "ZV", "Zq")): return None

    data_hex = line[1:]
    if not re.match(r'^[0-9a-fA-F]+$', data_hex): return None

    try:
        msg_type = data_hex[6:8].lower()
        src_rf = data_hex[8:14].lower()
        payload = data_hex[22:]

        if src_rf not in DEVICE_METADATA: return None
        meta = DEVICE_METADATA.get(src_rf, {})
        room_name = meta.get("room", "UNKNOWN")
        label_name = meta.get("label", f"Device {src_rf}")

        if msg_type == "30" and len(payload) >= 2:
            st = int(payload[0:2], 16)
            is_open = bool(st & 0x02)
            return {
                "id": src_rf, "src_rf": src_rf, "type": "fensterkontakt", "category": "fensterkontakt", 
                "room": room_name, "label": label_name, "state": "OPEN" if is_open else "CLOSED", 
                "is_open": is_open, "batt": bool(st & 0x80), "win": is_open, "raw": line
            }

        elif msg_type in ["50", "60", "02"] and len(payload) >= 6:
            mb = int(payload[0:2], 16)
            modes = {0: "AUTO", 1: "MANUAL", 2: "VACATION", 3: "BOOST"}
            setpoint = (int(payload[4:6], 16) & 0x3F) / 2.0
            actual_temp = "--"
            
            if len(payload) >= 14: 
                actual_temp = f"{(((int(payload[10:12], 16) & 0x01) << 8) | int(payload[12:14], 16)) / 10.0:.1f}"
            elif len(payload) >= 10: 
                actual_temp = f"{(((int(payload[6:8], 16) & 0x01) << 8) | int(payload[8:10], 16)) / 10.0:.1f}"
            elif len(payload) >= 8:
                actual_temp = f"{int(payload[6:8], 16) / 10.0:.1f}"
                
            return {
                "id": src_rf, "src_rf": src_rf, "type": "heizung", "category": "heizung", 
                "room": room_name, "label": label_name, "mode": modes.get(mb & 0x03, "AUTO"), 
                "soll": setpoint, "ist": actual_temp, "valve_pos": f"{int(payload[2:4], 16)}%", 
                "batt": bool(mb & 0x80), "err": bool(mb & 0x30), "boost": (modes.get(mb & 0x03) == "BOOST"), 
                "win": (setpoint <= 5.1), "raw": line
            }

        elif msg_type in ["70", "40"] and len(payload) >= 2:
            return {
                "id": src_rf, "src_rf": src_rf, "type": "heizung", "category": "heizung", 
                "room": room_name, "label": label_name, "mode": "AUTO", 
                "soll": (int(payload[0:2], 16) & 0x3F) / 2.0, "ist": "--", "win": False, "raw": line
            }
    except Exception: pass
    return None

# ==============================================================================
# 3. GESTION RÉSEAU & BOUCLE PRINCIPALE
# ==============================================================================

def open_cul_stream(ip, port):
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.settimeout(4.0)
    try:
        sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_KEEPALIVE, 1)
    except Exception: pass

    try:
        sock.connect((ip, port))
        print(f"✅ [NET] Connecté à Cube 2 ({ip}:{port})", flush=True)
        
        sock.sendall(b"V\r\nX21\r\nZr\r\nZa\r\n")
        time.sleep(0.3)
        
        SYSTEM_STATUS["cul_status"] = "CONNECTED"
        write_json_cache()
        print("👂 [CUL] Mode Écoute Passive globale activé.", flush=True)
        return sock
    except Exception as e:
        print(f"❌ [NET] Connexion impossible : {e}", flush=True)
        return None

def run_daemon():
    load_device_metadata()
    load_persisted_cache()
    write_json_cache()

    ip, port, src_rf = load_cube2_config()
    if not ip: sys.exit(1)

    sock = None
    buffer = ""

    while True:
        try:
            if sock is None:
                sock = open_cul_stream(ip, port)
                if not sock: 
                    time.sleep(4)
                    continue
                buffer = ""

            # 1. Traitement des ordres UI Web (Consigne / BOOST)
            if os.path.exists(CMD_FILE):
                try:
                    with open(CMD_FILE, 'r', encoding='utf-8') as f:
                        cmd = json.load(f)
                    try: os.remove(CMD_FILE)
                    except: pass

                    fct = cmd.get('fct')
                    target_id = cmd.get('id', '').lower()
                    val = cmd.get('temp')

                    if fct == 'temp' and re.match(r'^[0-9a-f]{6}$', target_id):
                        t_val = float(val)
                        temp_byte = (1 << 6) | (int(round(t_val * 2)) & 0x3F)
                        payload = f"{temp_byte:02x}"
                        telegram = f"Zs0b010040000000{target_id}00{payload}\r\n"
                        
                        sock.sendall(telegram.encode('ascii'))
                        print(f"📤 [TX] Consigne {t_val}°C -> [{target_id}]", flush=True)

                        if target_id in DEVICE_CACHE:
                            DEVICE_CACHE[target_id]["soll"] = t_val
                            DEVICE_CACHE[target_id]["mode"] = "MANUAL"
                            DEVICE_CACHE[target_id]["boost"] = False
                            DEVICE_CACHE[target_id]["win"] = (t_val <= 5.1)
                            write_json_cache()

                    elif fct == 'mode' and val == 'BOOST' and re.match(r'^[0-9a-f]{6}$', target_id):
                        temp_byte = (3 << 6) | (int(30.0 * 2) & 0x3F)
                        payload = f"{temp_byte:02x}"
                        telegram = f"Zs0b010040000000{target_id}00{payload}\r\n"
                        
                        sock.sendall(telegram.encode('ascii'))
                        print(f"📤 [TX] Mode BOOST -> [{target_id}]", flush=True)

                        if target_id in DEVICE_CACHE:
                            DEVICE_CACHE[target_id]["boost"] = True
                            DEVICE_CACHE[target_id]["mode"] = "BOOST"
                            write_json_cache()

                except Exception as e:
                    print(f"⚠️ [CMD WEB] Erreur exécution ordre : {e}", flush=True)

            # 2. Écoute passive non-bloquante
            r, _, _ = select.select([sock], [], [], 1.0)
            if r:
                try: 
                    chunk = sock.recv(4096).decode('latin1', errors='ignore')
                except Exception: 
                    sock = None
                    time.sleep(2)
                    continue
                
                if not chunk: 
                    sock = None
                    time.sleep(2)
                    continue
                
                buffer += chunk

                while "\r\n" in buffer:
                    line, buffer = buffer.split("\r\n", 1)
                    line = line.strip()
                    if not line: continue

                    if line.startswith("21  "):
                        try:
                            raw_hex = line[4:].strip()
                            credits_val = int(raw_hex, 16)
                            
                            if credits_val > 450:
                                pct = max(0, min(100, int(round(((900 - credits_val) / 900.0) * 100))))
                                info_str = f"Crédits Libres: {credits_val}/900"
                            else:
                                pct = max(0, min(100, int(round((credits_val / 900.0) * 100))))
                                info_str = f"Crédits Utilisés: {credits_val}/900"
                            
                            print(f"📊 [DUTY CYCLE] Brut: '{line}' | Hex: {raw_hex} | Décimal: {credits_val} => Calcul: {pct}% ({info_str})", flush=True)
                            
                            if SYSTEM_STATUS["duty_val"] != pct:
                                SYSTEM_STATUS["duty_val"] = pct
                                write_json_cache()
                            
                        except Exception as e: 
                            print(f"⚠️ [DUTY PARSE ERROR] {e}", flush=True)
                        continue

                    if "LOVF" in line:
                        SYSTEM_STATUS["duty_val"] = 100
                        write_json_cache()
                        print("🚨 [DUTY CYCLE] Saturation radio (LOVF) détectée !", flush=True)
                        continue

                    if line.startswith("V 1.26"):
                        SYSTEM_STATUS["firmware"] = line
                        continue

                    packet = parse_cul_max_packet(line)
                    if packet:
                        rf = packet["src_rf"]
                        if rf not in DEVICE_CACHE: DEVICE_CACHE[rf] = {}
                        
                        for k, v in packet.items():
                            if k != "raw": DEVICE_CACHE[rf][k] = v
                            
                        DEVICE_CACHE[rf]["last_seen"] = int(time.time())
                        write_json_cache()

                        if packet["category"] == "fensterkontakt":
                            st = "OUVERTE 🪟" if packet["is_open"] else "FERMÉE 🔒"
                            batt = " [🪫 Pile]" if packet.get("batt") else ""
                            print(f"✨ [RX] 🪟 [{packet['label']} ({packet['room']})] -> {st}{batt}", flush=True)
                        elif packet["category"] == "heizung":
                            batt = " [🪫 Pile]" if packet.get("batt") else ""
                            print(f"✨ [RX] 🔥 [{packet['label']} ({packet['room']})] -> Mode: {packet['mode']} | Consigne: {packet['soll']}°C | Mesure: {packet['ist']}°C | Ouv: {packet['valve_pos']}{batt}", flush=True)

        except Exception as e:
            print(f"💥 [ERROR] : {e}", flush=True)
            if sock: 
                try: sock.close() 
                except Exception: pass
            sock = None
            time.sleep(3)

if __name__ == "__main__":
    run_daemon()