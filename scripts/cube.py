#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
S.H.A. 2026 - Console Dédiée MAX! Cube 2 (Moteur CUL / a-culfw)
Contrôle exclusif de [Cube2] flashé sous a-culw (868 MHz).
Appairage radio, suppression/désappairage, sniffer temps réel, pilotage expert vannes, poll contact et boost antenne TX.
Lecture stricte de app.conf sans altérer config/home_structure.conf.
"""

import sys
import os
import time
import socket
import re
import configparser
import select

# --- GESTION DYNAMIQUE DES CHEMINS ---
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
SHA_ROOT = os.path.dirname(SCRIPT_DIR)

APP_CONF_PATHS = [
    "/opt/zabbix-docker/apps/sha/config/app.conf",
    os.path.join(SHA_ROOT, "config", "app.conf"),
    "/var/www/html/sha/config/app.conf",
    "/app/config/app.conf"
]

DEVICE_TYPES = {
    0: ("Cube CUL", "🔲"),
    1: ("Vanne thermostatique (Radiator Thermostat)", "🔥"),
    2: ("Vanne thermostatique+ (Radiator Thermostat+)", "🔥"),
    3: ("Thermostat mural (Wall Thermostat)", "🌡️"),
    4: ("Contact de fenêtre (Fensterkontakt)", "🪟"),
    5: ("Bouton Eco (Eco Taster)", "🔘")
}

# ==============================================================================
# 1. CONFIGURATION STRICTE [Cube2]
# ==============================================================================

def get_app_conf_path():
    """Localise le fichier app.conf en priorisant l'hôte de production."""
    for p in APP_CONF_PATHS:
        if os.path.exists(p):
            return p
    return APP_CONF_PATHS[0]

def load_cube2_config():
    """Extrait dynamiquement l'IP et le port de [Cube2] depuis app.conf."""
    conf_path = get_app_conf_path()
    if not os.path.exists(conf_path):
        print(f"❌ Erreur : Fichier de configuration introuvable ({conf_path})")
        return None, None

    config = configparser.ConfigParser()
    try:
        config.read(conf_path, encoding='utf-8')
    except Exception as e:
        print(f"⚠️ Erreur lors de la lecture de {conf_path} : {e}")
        return None, None

    selected_sec = None
    for sec in ["Cube2", "cube2", "CUBE2"]:
        if config.has_section(sec):
            selected_sec = sec
            break

    if not selected_sec:
        print(f"❌ Erreur : Section [Cube2] introuvable dans {conf_path}")
        return None, None

    ip = config.get(selected_sec, 'ip', fallback='').strip('"\'; ')
    port_str = config.get(selected_sec, 'port', fallback='62910').strip('"\'; ')
    try:
        port = int(port_str)
    except ValueError:
        port = 62910

    if not ip:
        print(f"❌ Erreur : Adresse IP vide dans [{selected_sec}]")
        return None, None

    return ip, port

# ==============================================================================
# 2. DÉCODEUR DE TRAMES RADIO CUL / a-culfw (MAX! Protocol)
# ==============================================================================

def parse_cul_max_packet(raw_line):
    """
    Décode les trames ASCII brutes reçues du firmware a-culw (ex: Z17080400084E4B...).
    Structure standard MAX! :
      - Z : Préfixe protocole MAX!
      - Length (2 hex) | MsgCount (2 hex) | MsgFlag (2 hex) | MsgType (2 hex)
      - SrcRF (6 hex)  | DstRF (6 hex)    | GroupID (2 hex)  | Payload (var)
    """
    line = raw_line.strip()
    if not line.startswith("Z") or len(line) < 18:
        return None

    try:
        data_hex = line[1:]
        length = int(data_hex[0:2], 16)
        msg_count = data_hex[2:4]
        msg_flag = data_hex[4:6]
        msg_type = data_hex[6:8]
        src_rf = data_hex[8:14].lower()
        dst_rf = data_hex[14:20].lower()
        group_id = data_hex[20:22]
        payload = data_hex[22:]

        result = {
            "raw": line,
            "msg_type": msg_type,
            "src_rf": src_rf,
            "dst_rf": dst_rf,
            "group_id": group_id,
            "desc": "INCONNU",
            "icon": "📡",
            "dev_type_id": 0,
            "dev_type_label": "Inconnu",
            "info": "",
            "serial": "N/A"
        }

        # 1. TRAME D'APPAIRAGE (PairPing : 0x00)
        if msg_type == "00":
            fw_version = int(payload[0:2], 16) if len(payload) >= 2 else 0
            dev_type_id = int(payload[2:4], 16) if len(payload) >= 4 else 1
            serial_hex = payload[6:26] if len(payload) >= 26 else payload[6:]
            
            try:
                serial_str = bytes.fromhex(serial_hex).decode('latin1', errors='ignore').strip()
            except Exception:
                serial_str = "INCONNU"

            t_label, t_ico = DEVICE_TYPES.get(dev_type_id, (f"Type {dev_type_id}", "❓"))
            result.update({
                "desc": "PAIRING_PING",
                "icon": t_ico,
                "dev_type_id": dev_type_id,
                "dev_type_label": t_label,
                "firmware": f"v{fw_version / 10.0:.1f}" if fw_version else "N/A",
                "serial": serial_str,
                "info": f"SN: {serial_str}"
            })

        # 2. CONTACT DE FENÊTRE (ShutterContactState : 0x30)
        elif msg_type == "30":
            status_byte = int(payload[0:2], 16) if len(payload) >= 2 else 0
            is_open = bool(status_byte & 0x02)
            is_batt_low = bool(status_byte & 0x80)
            result.update({
                "desc": "FENSTERKONTAKT",
                "icon": "🪟",
                "dev_type_id": 4,
                "dev_type_label": "Contact de fenêtre",
                "state": "OFFEN (Ouvert)" if is_open else "ZU (Fermé)",
                "batt_low": is_batt_low,
                "info": f"État: {'OUVERT 🪟' if is_open else 'FERMÉ 🔒'}" + (" [🪫 Pile faible]" if is_batt_low else " [🔋 OK]")
            })

        # 3. VANNE THERMOSTATIQUE (HeatingThermostatState : 0x50)
        elif msg_type == "50":
            mode_byte = int(payload[0:2], 16) if len(payload) >= 2 else 0
            valve_pos = int(payload[2:4], 16) if len(payload) >= 4 else 0
            setpoint_raw = int(payload[4:6], 16) if len(payload) >= 6 else 0
            setpoint = (setpoint_raw & 0x3F) / 2.0

            modes = {0: "AUTO", 1: "MANUAL", 2: "VACATION", 3: "BOOST"}
            current_mode = modes.get(mode_byte & 0x03, "AUTO")
            is_batt_low = bool(mode_byte & 0x80)

            actual_temp = "--"
            if len(payload) >= 8:
                try:
                    t_val = int(payload[6:8], 16)
                    if t_val > 0:
                        actual_temp = f"{t_val / 10.0:.1f}°C"
                except Exception:
                    pass

            result.update({
                "desc": "THERMOSTAT_STATE",
                "icon": "🔥",
                "dev_type_id": 1,
                "dev_type_label": "Vanne thermostatique",
                "mode": current_mode,
                "setpoint": setpoint,
                "valve_pos": f"{valve_pos}%",
                "actual_temp": actual_temp,
                "batt_low": is_batt_low,
                "info": f"Consigne: {setpoint}°C | Mode: {current_mode} | Ouv: {valve_pos}% | IST: {actual_temp}" + (" [🪫 Pile faible]" if is_batt_low else " [🔋 OK]")
            })

        # 4. ACQUITTEMENT / RÉPONSE (Ack : 0x02)
        elif msg_type == "02":
            result.update({
                "desc": "ACK_CONFIRMATION",
                "icon": "✅",
                "info": "Accusé de réception RF"
            })

        # 5. PAIR PONG (Réponse appairage : 0x01)
        elif msg_type == "01":
            result.update({
                "desc": "PAIR_PONG",
                "icon": "🔲",
                "info": "Réponse appairage Cube"
            })

        # 6. RESET / REMOVE NOTIFICATION (0xF0 / 0x08)
        elif msg_type in ["f0", "F0", "08"]:
            result.update({
                "desc": "DEVICE_RESET_OR_REMOVE",
                "icon": "🗑️",
                "info": "Trame de réinitialisation / suppression"
            })

        return result

    except Exception:
        return None

# ==============================================================================
# 3. GESTION SOCKET TCP & MODES RADIO
# ==============================================================================

def open_cul_connection(ip, port):
    """Initialise la socket et bascule le firmware a-culw en mode récepteur MAX!."""
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.settimeout(4.0)
    try:
        sock.connect((ip, port))
    except Exception as e:
        print(f"❌ Connexion impossible sur Cube2 ({ip}:{port}) : {e}")
        return None

    # Initialisation CUL : Version, RSSI étendu, Mode récepteur MAX! et Auto-Ack
    sock.sendall(b"V\r\nX21\r\nZr\r\nZa\r\n")
    time.sleep(0.3)
    return sock

def send_and_wait_ack(ip, port, telegram_str, target_rf, timeout_sec=6.0):
    """Transmet un télégramme radio et attend l'acquittement ou le retour de l'appareil."""
    sock = open_cul_connection(ip, port)
    if not sock:
        return False

    print(f"📡 Émission du télégramme : {telegram_str.strip()}")
    sock.sendall(telegram_str.encode('ascii'))
    
    end_time = time.time() + timeout_sec
    buffer = ""
    got_response = False

    try:
        while time.time() < end_time:
            remaining = int(end_time - time.time())
            sys.stdout.write(f"\r⏳ Attente confirmation de [{target_rf}]... {remaining:02d}s")
            sys.stdout.flush()

            r, _, _ = select.select([sock], [], [], 0.4)
            if r:
                chunk = sock.recv(4096).decode('latin1', errors='ignore')
                if not chunk:
                    break
                buffer += chunk

                while "\r\n" in buffer:
                    line, buffer = buffer.split("\r\n", 1)
                    line = line.strip()
                    if not line:
                        continue

                    packet = parse_cul_max_packet(line)
                    if packet and packet.get("src_rf") == target_rf:
                        print(f"\n\n✅ RÉPONSE REÇUE DE L'APPAREIL [{target_rf}] :")
                        print(f"   • Trame        : {packet['desc']} (Type: 0x{packet['msg_type']})")
                        print(f"   • Détails      : {packet.get('info', 'N/A')}")
                        print(f"   • Donnée brute : {packet['raw']}\n")
                        got_response = True
                        break
            if got_response:
                break
        
        if not got_response:
            print(f"\n\nℹ️ Télégramme envoyé. Pas de réponse immédiate (le périphérique appliquera l'ordre à son prochain réveil RF/WOR).")
    except KeyboardInterrupt:
        print("\n⏹️ Interrompu.")
    finally:
        try:
            sock.sendall(b"q:\r\n")
            sock.close()
        except Exception:
            pass

    return got_response

# ==============================================================================
# 4. MODULE EXPERT : CONTRÔLE DES VANNES
# ==============================================================================

def validate_rf(rf_input):
    """Valide le format d'adresse RF hexadécimal à 6 caractères."""
    rf = rf_input.strip().lower()
    if len(rf) == 6 and re.match(r'^[0-9a-f]{6}$', rf):
        return rf
    return None

def valve_set_temperature_manual(ip, port):
    """Envoie une consigne personnalisée en mode MANUEL (5.0°C à 30.0°C)."""
    rf = validate_rf(input("👉 Adresse RF de la vanne (6 hex, ex: 190e12) : "))
    if not rf:
        print("❌ Adresse RF invalide.")
        return

    temp_str = input("👉 Consigne en °C (5.0 à 30.0 par pas de 0.5) [20.0] : ").strip() or "20.0"
    try:
        temp_val = float(temp_str)
        if not (4.5 <= temp_val <= 30.5):
            print("❌ Plage autorisée : 4.5°C à 30.5°C.")
            return
    except ValueError:
        print("❌ Valeur numérique invalide.")
        return

    temp_byte = (1 << 6) | (int(round(temp_val * 2)) & 0x3F)
    payload = f"{temp_byte:02x}"
    telegram = f"Zs0b010040000000{rf}00{payload}\r\n"
    send_and_wait_ack(ip, port, telegram, rf)

def valve_set_auto(ip, port):
    """Bascule la vanne en mode AUTOMATIQUE (programme interne)."""
    rf = validate_rf(input("👉 Adresse RF de la vanne (6 hex, ex: 190e12) : "))
    if not rf:
        print("❌ Adresse RF invalide.")
        return

    temp_byte = (0 << 6) | (int(20.0 * 2) & 0x3F)
    payload = f"{temp_byte:02x}"
    telegram = f"Zs0b010040000000{rf}00{payload}\r\n"
    print(f"🚀 Bascule de la vanne [{rf}] en mode AUTOMATIQUE...")
    send_and_wait_ack(ip, port, telegram, rf)

def valve_set_boost(ip, port):
    """Enclenche le mode BOOST (ouverture maximale temporisée)."""
    rf = validate_rf(input("👉 Adresse RF de la vanne (6 hex, ex: 190e12) : "))
    if not rf:
        print("❌ Adresse RF invalide.")
        return

    temp_byte = (3 << 6) | (int(30.0 * 2) & 0x3F)
    payload = f"{temp_byte:02x}"
    telegram = f"Zs0b010040000000{rf}00{payload}\r\n"
    print(f"🚀 Déclenchement du BOOST sur la vanne [{rf}]...")
    send_and_wait_ack(ip, port, telegram, rf)

def valve_set_off(ip, port):
    """Ferme complètement la vanne (Mode OFF / Hors-gel 4.5°C)."""
    rf = validate_rf(input("👉 Adresse RF de la vanne (6 hex, ex: 190e12) : "))
    if not rf:
        print("❌ Adresse RF invalide.")
        return

    temp_byte = (1 << 6) | 9
    payload = f"{temp_byte:02x}"
    telegram = f"Zs0b010040000000{rf}00{payload}\r\n"
    print(f"🔒 Fermeture totale (OFF / 4.5°C) de la vanne [{rf}]...")
    send_and_wait_ack(ip, port, telegram, rf)

def valve_set_on_max(ip, port):
    """Ouvre la vanne à 100% (Mode ON permanent / 30.5°C)."""
    rf = validate_rf(input("👉 Adresse RF de la vanne (6 hex, ex: 190e12) : "))
    if not rf:
        print("❌ Adresse RF invalide.")
        return

    temp_byte = (1 << 6) | 61
    payload = f"{temp_byte:02x}"
    telegram = f"Zs0b010040000000{rf}00{payload}\r\n"
    print(f"🔥 Ouverture maximale continue (ON / 30.5°C) sur la vanne [{rf}]...")
    send_and_wait_ack(ip, port, telegram, rf)

def valve_poll_status(ip, port):
    """Interroge activement la vanne via son réveil WOR (Wake-On-Radio - Type 0x20)."""
    rf = validate_rf(input("👉 Adresse RF de la vanne à interroger (6 hex, ex: 190e12) : "))
    if not rf:
        print("❌ Adresse RF invalide.")
        return

    telegram = f"Zs0a010020000000{rf}00\r\n"
    print(f"📡 Envoi de la demande de statut immédiate vers [{rf}]...")
    send_and_wait_ack(ip, port, telegram, rf, timeout_sec=8.0)

def valve_configure_thresholds(ip, port):
    """Configure les paramètres de seuils dans l'EEPROM de la vanne (Type 0x10)."""
    rf = validate_rf(input("👉 Adresse RF de la vanne (6 hex, ex: 190e12) : "))
    if not rf:
        print("❌ Adresse RF invalide.")
        return

    print("\n⚙️ Configuration des seuils thermiques internes :")
    comf = float(input("   • Température de Confort °C [21.0] : ").strip() or "21.0")
    eco  = float(input("   • Température Éco / Nuit °C [17.0] : ").strip() or "17.0")
    max_t = float(input("   • Température Max autorisée °C [30.5] : ").strip() or "30.5")
    min_t = float(input("   • Température Min autorisée °C [4.5] : ").strip() or "4.5")
    offset = float(input("   • Décalage Sonde (Offset entre -3.5 et +3.5°C) [0.0] : ").strip() or "0.0")
    win_t = float(input("   • Température Fenêtre Ouverte °C [12.0] : ").strip() or "12.0")
    win_dur = int(input("   • Durée Fenêtre Ouverte en minutes [15] : ").strip() or "15")

    b_comf = int(round(comf * 2)) & 0xFF
    b_eco = int(round(eco * 2)) & 0xFF
    b_max = int(round(max_t * 2)) & 0xFF
    b_min = int(round(min_t * 2)) & 0xFF
    b_offset = int(round((offset + 3.5) * 2)) & 0xFF
    b_wint = int(round(win_t * 2)) & 0xFF
    b_windur = int(round(win_dur / 5.0)) & 0xFF

    payload = f"{b_comf:02x}{b_eco:02x}{b_max:02x}{b_min:02x}{b_offset:02x}{b_wint:02x}{b_windur:02x}"
    telegram = f"Zs11010010000000{rf}00{payload}\r\n"

    print(f"📡 Injection des paramètres internes vers [{rf}]...")
    send_and_wait_ack(ip, port, telegram, rf)

def valve_simulate_window_contact(ip, port):
    """Simule un contact de fenêtre vers une vanne (force l'état ouvert/fermé)."""
    rf = validate_rf(input("👉 Adresse RF de la vanne cible (6 hex, ex: 190e12) : "))
    if not rf:
        print("❌ Adresse RF invalide.")
        return

    print("1. 🪟 Simuler FENÊTRE OUVERTE (Baisse à la consigne fenêtre / 12°C)")
    print("2. 🔒 Simuler FENÊTRE FERMÉE (Retour à la consigne normale)")
    choice = input("👉 Ton choix (1 ou 2) : ").strip()

    status_byte = "02" if choice == "1" else "00"
    telegram = f"Zs0b010030000000{rf}00{status_byte}\r\n"
    print(f"📡 Émission de l'état fenêtre simulé vers [{rf}]...")
    send_and_wait_ack(ip, port, telegram, rf)

def menu_valve_expert(ip, port):
    """Sous-menu dédié au pilotage et réglage exhaustif des vannes thermostatiques."""
    while True:
        print("\n" + "╔" + "═"*60 + "╗")
        print("║      🔥 MENU EXPERT : VANNES THERMOSTATIQUES (CUBE 2)     ║")
        print("╚" + "═"*60 + "╝")
        print(" 1. 🌡️ Consigne Manuelle Personnalisée (5.0°C à 30.0°C)")
        print(" 2. 🔄 Mode AUTOMATIQUE (Programme horaire interne)")
        print(" 3. 🚀 Mode BOOST (Chauffe forcée 80% / 5 minutes)")
        print(" 4. 🔒 Mode OFF (Fermeture complète / Hors-gel 4.5°C)")
        print(" 5. 🔥 Mode ON (Ouverture maximale continue / 30.5°C)")
        print(" 6. 📡 Interroger l'état en direct (Poll WOR 0x20)")
        print(" 7. ⚙️ Configurer les seuils (Confort, Éco, Min, Max, Offset)")
        print(" 8. 🪟 Simuler un contact de fenêtre (Forcer Ouvert/Fermé)")
        print(" 0. ↩️ Retour au menu principal")
        print(" ────────────────────────────────────────────────────────────")

        ch = input("👉 Choisis une option (0-8) : ").strip()
        if ch == '1':
            valve_set_temperature_manual(ip, port)
        elif ch == '2':
            valve_set_auto(ip, port)
        elif ch == '3':
            valve_set_boost(ip, port)
        elif ch == '4':
            valve_set_off(ip, port)
        elif ch == '5':
            valve_set_on_max(ip, port)
        elif ch == '6':
            valve_poll_status(ip, port)
        elif ch == '7':
            valve_configure_thresholds(ip, port)
        elif ch == '8':
            valve_simulate_window_contact(ip, port)
        elif ch in ['0', 'r', 'q']:
            break
        else:
            print("⚠️ Option non reconnue.")

# ==============================================================================
# 5. FONCTIONS RADIO (APPAIRAGE, SUPPRESSION, SNIFFER, BOOST TX, POLL)
# ==============================================================================

def delete_unpair_device(ip, port):
    """
    Supprime et désappaire un équipement eQ-3 (Vanne ou Contact) :
    1. Transmet l'ordre radio de Reset/Unpair (Type 0xF0 ou 0x08) vers l'appareil.
    2. Purge l'entrée correspondante dans la mémoire CUL (commande Zd / Zc).
    """
    print("\n" + "═"*75)
    print(" 🗑️ SUPPRESSION ET DÉSAPPAIRAGE D'UN APPAREIL RADIO (CUBE 2)")
    print("═"*75)
    
    rf = validate_rf(input("👉 Adresse RF de l'appareil à supprimer (6 hex, ex: 084e4b ou 11d16d) : "))
    if not rf:
        print("❌ Adresse RF invalide.")
        return

    print(f"\n⚠️ Action : Suppression de l'appareil [\033[1;31m{rf}\033[0m]")
    print(" 1. ⚡ Désappairage + Reset Usine Radio (Ordre 0xF0 - Recommandé)")
    print(" 2. 🔲 Ordre d'effacement standard MAX! (Ordre 0x08 Remove)")
    print(" 3. 🧹 Purger uniquement de la table d'appairage CUL (Commande Zd)")
    print(" 0. ↩️ Annuler")
    print("───────────────────────────────────────────────────────────────────────────")

    ch = input("👉 Choisis une option (0-3) : ").strip()
    if ch in ['0', 'q', 'exit', '']:
        print("⏹️ Suppression annulée.")
        return

    sock = open_cul_connection(ip, port)
    if not sock:
        return

    try:
        if ch == '1':
            # Télégramme MAX! Reset (Type 0xF0)
            telegram = f"Zs0a0100f0000000{rf}00\r\n"
            print(f"📡 Émission de l'ordre de Reset Usine Radio (0xF0) vers [{rf}]...")
            send_and_wait_ack(ip, port, telegram, rf, timeout_sec=6.0)

            # Purge CUL conjointe
            sock_purge = open_cul_connection(ip, port)
            if sock_purge:
                sock_purge.sendall(f"Zd{rf}\r\n".encode('ascii'))
                time.sleep(0.2)
                sock_purge.close()

        elif ch == '2':
            # Télégramme MAX! RemoveDevice (Type 0x08)
            telegram = f"Zs0a010008000000{rf}00\r\n"
            print(f"📡 Émission de l'ordre de désappairage standard (0x08) vers [{rf}]...")
            send_and_wait_ack(ip, port, telegram, rf, timeout_sec=6.0)

            sock_purge = open_cul_connection(ip, port)
            if sock_purge:
                sock_purge.sendall(f"Zd{rf}\r\n".encode('ascii'))
                time.sleep(0.2)
                sock_purge.close()

        elif ch == '3':
            print(f"🧹 Purge de la clé [{rf}] dans la mémoire CUL...")
            sock.sendall(f"Zd{rf}\r\n".encode('ascii'))
            time.sleep(0.5)
            print("✅ Entrée purgée du firmware CUL.")

        print("\n" + "─"*75)
        print(f"✅ Procédure de suppression terminée pour [\033[1;32m{rf}\033[0m].")
        print("💡 N'oublie pas de supprimer la ligne correspondante dans :")
        print(f"   📂 config/home_structure.conf (ex: devices[] = \"...|{rf}|...\")")
        print("─"*75)

    except Exception as e:
        print(f"❌ Erreur lors de la suppression : {e}")
    finally:
        try:
            sock.sendall(b"q:\r\n")
            sock.close()
        except:
            pass

def set_antenna_boost(ip, port):
    """
    Interroge et configure la puissance d'émission RF (PATABLE / Gain d'antenne CC1101)
    sur le firmware a-culw via la commande native 'x<hex>'.
    """
    print("\n" + "═"*75)
    print(" 📶 GESTION DE LA PUISSANCE D'ÉMISSION RF (BOOST ANTENNE CUL)")
    print("═"*75)

    sock = open_cul_connection(ip, port)
    if not sock:
        return

    current_val = "N/A"
    try:
        sock.sendall(b"x\r\n")
        time.sleep(0.3)
        r, _, _ = select.select([sock], [], [], 1.0)
        if r:
            chunk = sock.recv(1024).decode('latin1', errors='ignore').strip()
            for l in chunk.split("\r\n"):
                l = l.strip()
                if l and not l.startswith("V") and not l.startswith("Z"):
                    current_val = l
    except Exception as e:
        print(f"⚠️ Impossible de lire le niveau courant : {e}")

    print(f"📊 Registre PATABLE actuel du transceiver CC1101 : \033[1;33m{current_val}\033[0m")
    print("───────────────────────────────────────────────────────────────────────────")
    print(" 1. 🚀 ACTIVER LE BOOST MAXIMAL (+10 dBm / +12 dBm -> xC2)")
    print(" 2. ⚡ Puissance Élevée (+5 dBm -> x84)")
    print(" 3. ⚖️ Puissance Standard (0 dBm -> x26 - Valeur d'origine)")
    print(" 4. 🍃 Puissance Réduite (-10 dBm -> x12)")
    print(" 5. ⌨️ Définir une valeur hexadécimale manuelle (ex: C0, C2, 84, 26...)")
    print(" 0. ↩️ Annuler")
    print("───────────────────────────────────────────────────────────────────────────")

    choice = input("👉 Ton choix (0-5) : ").strip()
    target_cmd = None
    label_pwr = ""

    if choice == '1':
        target_cmd = "xC2"
        label_pwr = "BOOST MAXIMAL (+10 dBm / +12 dBm)"
    elif choice == '2':
        target_cmd = "x84"
        label_pwr = "Élevée (+5 dBm)"
    elif choice == '3':
        target_cmd = "x26"
        label_pwr = "Standard (0 dBm)"
    elif choice == '4':
        target_cmd = "x12"
        label_pwr = "Réduite (-10 dBm)"
    elif choice == '5':
        val_custom = input("👉 Valeur hexadécimale PATABLE (ex: C0, C2, 84, 26) : ").strip().upper()
        if re.match(r'^[0-9A-F]{2}$', val_custom):
            target_cmd = f"x{val_custom}"
            label_pwr = f"Personnalisée ({target_cmd})"
        else:
            print("❌ Format invalide (2 caractères hexadécimaux requis, ex: C2).")
            try:
                sock.close()
            except:
                pass
            return
    elif choice in ['0', 'q', 'exit']:
        try:
            sock.close()
        except:
            pass
        return
    else:
        print("⚠️ Choix non reconnu.")
        try:
            sock.close()
        except:
            pass
        return

    if target_cmd:
        try:
            print(f"\n📡 Envoi de l'ordre CUL : \033[1;32m{target_cmd}\033[0m ({label_pwr})...")
            sock.sendall(f"{target_cmd}\r\n".encode('ascii'))
            time.sleep(0.3)

            sock.sendall(b"x\r\n")
            time.sleep(0.3)
            r, _, _ = select.select([sock], [], [], 1.0)
            confirmed = "N/A"
            if r:
                chunk = sock.recv(1024).decode('latin1', errors='ignore').strip()
                for l in chunk.split("\r\n"):
                    l = l.strip()
                    if l and not l.startswith("V") and not l.startswith("Z"):
                        confirmed = l

            print(f"✅ Configuration radio validée !")
            print(f"   • Nouvelle valeur enregistrée : \033[1;32m{confirmed}\033[0m")
        except Exception as e:
            print(f"❌ Erreur lors de l'envoi de la commande : {e}")
        finally:
            try:
                sock.sendall(b"q:\r\n")
                sock.close()
            except:
                pass

    print("═"*75)

def poll_window_contact(ip, port):
    """Envoie un télégramme d'interrogation de statut (0x20) vers un contact."""
    rf = validate_rf(input("👉 Adresse RF du contact de fenêtre (6 hex, ex: 084e4b) : "))
    if not rf:
        print("❌ Adresse RF invalide.")
        return

    telegram = f"Zs0a010020000000{rf}00\r\n"
    print(f"📡 Envoi de la requête vers le contact [{rf}]...")
    send_and_wait_ack(ip, port, telegram, rf, timeout_sec=8.0)

def run_pairing_sniffer(ip, port, duration=90):
    """Capture en temps réel les trames d'appairage radio."""
    sock = open_cul_connection(ip, port)
    if not sock:
        return

    print("\n" + "━"*75)
    print(" 📡 CUBE 2 (a-culw) : SESSION D'APPAIRAGE RADIO ACTIVÉE")
    print("━"*75)
    print("👉 METS TES ÉQUIPEMENTS EN MODE APPAIRAGE :")
    print("   • Vanne EQ-3      : Maintiens 'BOOST' 3s (Affiche 'AC' ou compte 30s)")
    print("   • Contact Fenêtre : Maintiens le bouton 3s (LED clignote lentement)")
    print("   • Quitter         : Appuie sur Ctrl+C")
    print("━"*75 + "\n")

    discovered = {}
    end_time = time.time() + duration
    buffer = ""

    try:
        while time.time() < end_time:
            remaining = int(end_time - time.time())
            sys.stdout.write(f"\r⏳ Écoute a-culw... Reste : {remaining:02d}s | Équipements détectés : {len(discovered)}")
            sys.stdout.flush()

            r, _, _ = select.select([sock], [], [], 0.5)
            if r:
                chunk = sock.recv(4096).decode('latin1', errors='ignore')
                if not chunk:
                    print("\n⚠️ Connexion fermée par Cube2.")
                    break
                buffer += chunk

                while "\r\n" in buffer:
                    line, buffer = buffer.split("\r\n", 1)
                    line = line.strip()
                    if not line:
                        continue

                    packet = parse_cul_max_packet(line)
                    if packet:
                        if packet["desc"] == "PAIRING_PING":
                            rf = packet["src_rf"]
                            if rf not in discovered:
                                discovered[rf] = packet
                                print(f"\n\n🎉 NOUVEL ÉQUIPEMENT DÉTECTÉ ET CAPTURÉ !")
                                print(f"   • Type         : {packet['icon']} {packet['dev_type_label']}")
                                print(f"   • Adresse RF   : \033[1;32m{rf}\033[0m (Hex)")
                                print(f"   • Serial (SN)  : {packet['serial']}")
                                print(f"   • Firmware     : {packet.get('firmware', 'N/A')}")
                                print(f"   • Trame Brute  : {packet['raw']}")
                                print("   ------------------------------------------------------------")
                                print("   💡 Configuration de référence pour home_structure.conf :")
                                if packet.get("dev_type_id") in [1, 2]:
                                    print(f'   devices[] = "heizung|{rf}|1|TM NomPièce|🔥"')
                                elif packet.get("dev_type_id") == 4:
                                    print(f'   devices[] = "fensterkontakt|{rf}|1|Fenster NomPièce|🪟"')
                                print("   ------------------------------------------------------------\n")
                        else:
                            icon = packet.get("icon", "📡")
                            desc = packet.get("desc", "RADIO")
                            src = packet.get("src_rf", "???")
                            print(f"\n📡 [{icon} {desc}] Reçu de \033[1;33m{src}\033[0m (Trame: {packet['raw']})")

    except KeyboardInterrupt:
        print("\n\n⏹️ Session arrêtée.")
    finally:
        try:
            sock.sendall(b"q:\r\n")
            sock.close()
        except Exception:
            pass

    print(f"\n🏁 Fin de session. Total équipements capturés : {len(discovered)}")

def run_live_traffic_monitor(ip, port):
    """Moniteur continu des flux radio 868 MHz du domicile."""
    sock = open_cul_connection(ip, port)
    if not sock:
        return

    print("\n" + "═"*75)
    print(f" 🛰️ MONITEUR DE TRAFIC RADIO MAX! (a-culw sur {ip}:{port})")
    print("═"*75)
    print("Appuie sur Ctrl+C pour arrêter le moniteur.\n")

    buffer = ""
    try:
        while True:
            r, _, _ = select.select([sock], [], [], 0.5)
            if r:
                chunk = sock.recv(4096).decode('latin1', errors='ignore')
                if not chunk:
                    break
                buffer += chunk
                while "\r\n" in buffer:
                    line, buffer = buffer.split("\r\n", 1)
                    line = line.strip()
                    if not line:
                        continue

                    packet = parse_cul_max_packet(line)
                    now_str = time.strftime('%H:%M:%S')
                    if packet:
                        if packet["desc"] == "THERMOSTAT_STATE":
                            print(f"[{now_str}] 🔥 Vanne [{packet['src_rf']}] -> Mode: {packet['mode']} | Consigne: {packet['setpoint']}°C | Ouv: {packet['valve_pos']} | Mesure: {packet['actual_temp']}")
                        elif packet["desc"] == "FENSTERKONTAKT":
                            print(f"[{now_str}] 🪟 Contact [{packet['src_rf']}] -> État: {packet['state']} | Pile faible: {packet['batt_low']}")
                        elif packet["desc"] == "PAIRING_PING":
                            print(f"[{now_str}] ⚠️ Demande d'appairage de [{packet['src_rf']}] ({packet['dev_type_label']})")
                        else:
                            print(f"[{now_str}] 📥 {packet.get('icon', '📡')} {packet.get('desc', 'TRAME')} depuis [{packet.get('src_rf', '???')}]")
                    else:
                        print(f"[{now_str}] 📥 RAW: {line}")
    except KeyboardInterrupt:
        print("\n⏹️ Arrêt du moniteur.")
    finally:
        try:
            sock.close()
        except Exception:
            pass

# ==============================================================================
# 6. MENU PRINCIPAL
# ==============================================================================

def main_menu():
    ip, port = load_cube2_config()
    if not ip or not port:
        print("❌ Impossible de charger [Cube2] depuis app.conf.")
        sys.exit(1)

    while True:
        print("\n" + "╔" + "═"*60 + "╗")
        print("║          🐦‍🔥 S.H.A. - CONSOLE CUBE 2 (a-culfw)            ║")
        print("╚" + "═"*60 + "╝")
        print(f" 🎯 Passerelle : Cube2 ({ip}:{port}) [Mode a-culw 868MHz]")
        print(f" 🔒 Fichier    : {get_app_conf_path()}")
        print(" ────────────────────────────────────────────────────────────")
        print(" 1. 📡 Lancer l'Appairage Radio (Capture vannes / contacts)")
        print(" 2. 🗑️ Supprimer / Désappairer un appareil (Reset radio & CUL)")
        print(" 3. 🛰️ Moniteur de Trafic Radio Temps Réel (Sniffer MAX!)")
        print(" 4. 🔥 Menu Expert Vannes (Consigne, Auto, Boost, Off/On, Poll)")
        print(" 5. 🪟 Interroger l'état d'un Contact de fenêtre (Poll RF)")
        print(" 6. 📶 Réglage Puissance / Boost Antenne RF (a-culfw TX Power)")
        print(" 0. 🚪 Quitter")
        print(" ────────────────────────────────────────────────────────────")

        choice = input("👉 Choisis une option (0-6) : ").strip()

        if choice == '1':
            dur = input("👉 Durée d'écoute en secondes [90] : ").strip() or "90"
            run_pairing_sniffer(ip, port, int(dur))
        elif choice == '2':
            delete_unpair_device(ip, port)
        elif choice == '3':
            run_live_traffic_monitor(ip, port)
        elif choice == '4':
            menu_valve_expert(ip, port)
        elif choice == '5':
            poll_window_contact(ip, port)
        elif choice == '6':
            set_antenna_boost(ip, port)
        elif choice in ['0', 'q', 'exit']:
            print("👋 Fermeture de la console Cube2.")
            break
        else:
            print("⚠️ Option non reconnue.")

if __name__ == "__main__":
    main_menu()