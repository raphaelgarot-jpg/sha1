#!/usr/bin/env python3
import json
import os
import time
import configparser
from pywebpush import webpush, WebPushException

# --- CHEMINS LOGIQUES SHA ---
APP_CONF = "/var/www/html/sha/config/app.conf"
RAM_LIVE_FILE = "/dev/shm/sha_live.json"
RAM_STATE_FILE = "/dev/shm/sha_monitor_state.json"
SUB_FILE = "/var/www/html/sha/config/devices.json"
PEM_FILE = "/var/www/html/sha/config/private_key.pem"

VAPID_CLAIMS = {"sub": "mailto:admin@rgsv.fr"}

# 1. CHARGEMENT CONFIG
config = configparser.ConfigParser()
config.read(APP_CONF)

if not config.has_section('MONITORING_WASCHMASCHINE') or config.getboolean('MONITORING_WASCHMASCHINE', 'enabled', fallback=False) == False:
    print("ℹ️ Monitoring désactivé ou non configuré dans app.conf.")
    exit()

TARGET_IP = config.get('MONITORING_WASCHMASCHINE', 'ip').strip('"')
THRESHOLD = config.getfloat('MONITORING_WASCHMASCHINE', 'threshold_watts')
MIN_CYCLE = config.getint('MONITORING_WASCHMASCHINE', 'min_cycle_duration')
ALERT_TIME = config.getint('MONITORING_WASCHMASCHINE', 'alert_display_duration')

# 2. FONCTION DE NOTIFICATION (Intégrée)
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
            except WebPushException:
                pass
    except Exception:
        pass

# 3. CHARGEMENT DE L'ANCIEN ÉTAT PERSISTANT (Pour les timers)
# États possibles : "IDLE" (Éteint), "RUNNING" (En cours), "FINISHED" (Alerte 30min)
current_state = "IDLE"
cycle_start_time = 0
finished_time = 0

if os.path.exists(RAM_STATE_FILE):
    try:
        with open(RAM_STATE_FILE, "r") as f:
            saved = json.load(f)
            current_state = saved.get("state", "IDLE")
            cycle_start_time = saved.get("cycle_start_time", 0)
            finished_time = saved.get("finished_time", 0)
    except: pass

# 4. BOUCLE PRINCIPALE DE MONITORING
print("🚀 Moteur de règles S.H.A. activé...")
while True:
    try:
        time.sleep(2) # Analyse toutes les 2 secondes
        now = int(time.time())

        # Lire la conso actuelle dans la RAM Live de l'architecture
        if not os.path.exists(RAM_LIVE_FILE):
            continue
            
        with open(RAM_LIVE_FILE, "r") as f:
            live_data = json.load(f)
        
        devices = live_data.get("devices", {})
        if TARGET_IP not in devices:
            continue # La prise n'a pas encore publié
            
        current_power = float(devices[TARGET_IP].get("power", 0.0))

        # --- MACHINE À ÉTATS (LOGIQUE DU CYCLE) ---
        
        if current_state == "IDLE":
            if current_power >= THRESHOLD:
                print(f"🧺 La machine vient de démarrer ({current_power}W). Passage en RUNNING.")
                current_state = "RUNNING"
                cycle_start_time = now

        elif current_state == "RUNNING":
            # Si elle redescend sous le seuil
            if current_power < THRESHOLD:
                duration = now - cycle_start_time
                if duration >= MIN_CYCLE:
                    # ✅ VALIDÉ : Elle a tourné plus de 10 min et vient de finir !
                    print(f"🎉 Machine terminée ! Durée du cycle : {duration}s. Notification envoyée.")
                    current_state = "FINISHED"
                    finished_time = now
                    send_push_notification("S.H.A. 2026 🧺", "Waschmaschine oder Trockner fertig! 🫧")
                else:
                    # Fausse alerte (micro-allumage ou pic transitoire < 10 min)
                    print(f"💨 Machine arrêtée trop tôt ({duration}s). Retour à l'état IDLE.")
                    current_state = "IDLE"
                    cycle_start_time = 0

        elif current_state == "FINISHED":
            # Si on dépasse les 30 minutes d'affichage
            if (now - finished_time) >= ALERT_TIME:
                print("⏰ Fenêtre de 30 minutes expirée. Nettoyage de l'état.")
                current_state = "IDLE"
                finished_time = 0
                cycle_start_time = 0
            # Sécurité : Si on la relance pendant la fenêtre des 30min
            elif current_power >= THRESHOLD:
                current_state = "RUNNING"
                cycle_start_time = now
                finished_time = 0

        # 5. ÉCRITURE DE L'ÉTAT POUR L'INTERFACE WEB
        output_state = {
            "target_ip": TARGET_IP,
            "state": current_state,
            "current_power": current_power,
            "time_left_display": max(0, ALERT_TIME - (now - finished_time)) if current_state == "FINISHED" else 0
        }
        
        with open(RAM_STATE_FILE, "w") as f:
            json.dump(output_state, f, indent=2)

    except KeyboardInterrupt:
        break
    except Exception as e:
        print(f"⚠️ Erreur boucle moniteur : {e}")
        time.sleep(5)
