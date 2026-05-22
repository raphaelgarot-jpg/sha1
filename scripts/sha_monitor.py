#!/usr/bin/env python3
import json
import os
import time
import configparser
from pywebpush import webpush, WebPushException

# --- CHEMINS LOGIQUES SHA ---
APP_CONF = "/var/www/html/sha/config/app.conf"
RAM_LIVE_FILE = "/dev/shm/sha_live.json"
SUB_FILE = "/var/www/html/sha/config/devices.json"
PEM_FILE = "/var/www/html/sha/config/private_key.pem"

VAPID_CLAIMS = {"sub": "mailto:admin@rgsv.fr"}

# 1. CHARGEMENT CONFIG
config = configparser.ConfigParser()
config.read(APP_CONF)

# Configuration de la mémoire des deux machines
machines = {}

for section, key_name, label, emoji in [
    ('MONITORING_WASCHMASCHINE', 'wm', 'Waschmaschine', '🧺'),
    ('MONITORING_GESCHIRRSPUEHLER', 'gs', 'Geschirrspüler', '🍽️')
]:
    if config.has_section(section) and config.getboolean(section, 'enabled', fallback=False):
        state_file = f"/dev/shm/sha_monitor_state_{key_name}.json"
        
        # Valeurs par défaut
        m_data = {
            "key": key_name,
            "label": label,
            "emoji": emoji,
            "ip": config.get(section, 'ip').strip('"'),
            "threshold": config.getfloat(section, 'threshold_watts'),
            "min_cycle": config.getint(section, 'min_cycle_duration'),
            "alert_time": config.getint(section, 'alert_display_duration'),
            "state": "IDLE",
            "cycle_start_time": 0,
            "finished_time": 0,
            "state_file": state_file
        }
        
        # Rechargement de l'état persistant si existant
        if os.path.exists(state_file):
            try:
                with open(state_file, "r") as f:
                    saved = json.load(f)
                    m_data["state"] = saved.get("state", "IDLE")
                    m_data["cycle_start_time"] = saved.get("cycle_start_time", 0)
                    m_data["finished_time"] = saved.get("finished_time", 0)
            except: pass
            
        machines[key_name] = m_data

if not machines:
    print("ℹ️ Aucun monitoring activé dans app.conf.")
    exit()

# 2. FONCTION DE NOTIFICATION
def send_push_notification(title, message):
    if not os.path.exists(SUB_FILE) or not os.path.exists(PEM_FILE):
        return
    try:
        with open(SUB_FILE, "r") as f:
            subs = json.load(f)
        if isinstance(subs, dict): subs = [subs]
        payload = {"title": title, "body": message, "badge": 1}
        for sub_info in subs:
            try:
                webpush(subscription_info=sub_info, data=json.dumps(payload), vapid_private_key=PEM_FILE, vapid_claims=VAPID_CLAIMS)
            except WebPushException: pass
    except Exception: pass

# 3. BOUCLE PRINCIPALE UNIFIÉE (Analyse multi-appareils)
print(f"🚀 Moteur de règles S.H.A. activé pour : {', '.join([m['label'] for m in machines.values()])}...")

while True:
    try:
        time.sleep(2)
        now = int(time.time())

        if not os.path.exists(RAM_LIVE_FILE):
            continue
            
        with open(RAM_LIVE_FILE, "r") as f:
            live_data = json.load(f)
        devices = live_data.get("devices", {})

        # Traitement indépendant de chaque machine enregistrée
        for m_id, m in machines.items():
            if m["ip"] not in devices:
                continue
                
            current_power = float(devices[m["ip"]].get("power", 0.0))

            # --- MACHINE À ÉTATS DE L'APPAREIL ---
            if m["state"] == "IDLE":
                if current_power >= m["threshold"]:
                    print(f"{m['emoji']} {m['label']} vient de démarrer ({current_power}W). Passage en RUNNING.")
                    m["state"] = "RUNNING"
                    m["cycle_start_time"] = now

            elif m["state"] == "RUNNING":
                if current_power < m["threshold"]:
                    duration = now - m["cycle_start_time"]
                    if duration >= m["min_cycle"]:
                        print(f"🎉 {m['label']} terminé ! Durée : {duration}s. Notification envoyée.")
                        m["state"] = "FINISHED"
                        m["finished_time"] = now
                        send_push_notification(f"S.H.A. 2026 {m['emoji']}", f"{m['label']} fertig! ✨")
                    else:
                        print(f"💨 {m['label']} arrêté prématurément ({duration}s). Retour à l'état IDLE.")
                        m["state"] = "IDLE"
                        m["cycle_start_time"] = 0

            elif m["state"] == "FINISHED":
                if (now - m["finished_time"]) >= m["alert_time"]:
                    print(f"⏰ Fenêtre d'alerte expirée pour {m['label']}. Nettoyage.")
                    m["state"] = "IDLE"
                    m["finished_time"] = 0
                    m["cycle_start_time"] = 0
                elif current_power >= m["threshold"]:
                    m["state"] = "RUNNING"
                    m["cycle_start_time"] = now
                    m["finished_time"] = 0

            # --- ÉCRITURE DE L'ÉTAT DÉDIÉ EN RAM ---
            output_state = {
                "target_ip": m["ip"],
                "state": m["state"],
                "current_power": current_power,
                "cycle_start_time": m["cycle_start_time"],
                "finished_time": m["finished_time"],
                "time_left_display": max(0, m["alert_time"] - (now - m["finished_time"])) if m["state"] == "FINISHED" else 0
            }
            
            with open(m["state_file"], "w") as f:
                json.load(f) if False else json.dump(output_state, f, indent=2)

    except KeyboardInterrupt:
        break
    except Exception as e:
        print(f"⚠️ Erreur boucle moniteur : {e}")
        time.sleep(5)