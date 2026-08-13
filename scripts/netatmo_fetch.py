#!/usr/bin/env python3
import os
import json
import requests
import configparser

# --- DÉTECTION DYNAMIQUE DE LA RACINE DU PROJET ---
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
SHA_ROOT = os.path.dirname(SCRIPT_DIR)

APP_CONF = os.path.join(SHA_ROOT, "config", "app.conf")
TOKEN_FILE = os.path.join(SHA_ROOT, "config", "netatmo_tokens.json")
OUTPUT_FILE = os.path.join(SHA_ROOT, "data", "weather.json")

def load_app_config():
    """Charge les identifiants Netatmo depuis config/app.conf."""
    config = configparser.ConfigParser()
    if not os.path.exists(APP_CONF):
        print(f"❌ Erreur : Fichier de configuration introuvable ({APP_CONF})")
        return None, None, None

    try:
        config.read(APP_CONF, encoding='utf-8')
        if not config.has_section('netatmo'):
            print("❌ Erreur : Section [netatmo] manquante dans app.conf")
            return None, None, None

        client_id = config.get('netatmo', 'client_id', fallback='').strip('"\'')
        client_secret = config.get('netatmo', 'client_secret', fallback='').strip('"\'')
        refresh_token = config.get('netatmo', 'refresh_token', fallback='').strip('"\'')

        return client_id, client_secret, refresh_token
    except Exception as e:
        print(f"❌ Erreur lors de la lecture de {APP_CONF} : {e}")
        return None, None, None

def get_tokens(default_refresh):
    """Récupère les jetons OAuth2 en cache ou utilise le refresh initial."""
    if os.path.exists(TOKEN_FILE):
        try:
            with open(TOKEN_FILE, 'r', encoding='utf-8') as f:
                content = f.read().strip()
                if content:
                    data = json.loads(content)
                    if isinstance(data, dict) and data.get('refresh_token'):
                        return data
        except (json.JSONDecodeError, ValueError, IOError) as e:
            print(f"⚠️ Fichier de jetons corrompu ({e}). Réinitialisation...")
            if os.path.exists(TOKEN_FILE):
                try:
                    os.remove(TOKEN_FILE)
                except OSError:
                    pass

    return {"refresh_token": default_refresh}

def refresh_access_token(client_id, client_secret, current_refresh):
    """Renouvelle le jeton d'accès auprès de l'API Netatmo."""
    url = "https://api.netatmo.com/oauth2/token"
    payload = {
        "grant_type": "refresh_token",
        "refresh_token": current_refresh,
        "client_id": client_id,
        "client_secret": client_secret
    }
    headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) SHA-Engine/2026"}

    try:
        resp = requests.post(url, data=payload, headers=headers, timeout=10)
        if resp.status_code == 200:
            tokens = resp.json()
            
            # Écriture atomique du nouveau jeton
            tmp_token_file = TOKEN_FILE + ".tmp"
            with open(tmp_token_file, 'w', encoding='utf-8') as f:
                json.dump(tokens, f, indent=4)
            os.replace(tmp_token_file, TOKEN_FILE)
            
            return tokens
        else:
            print(f"❌ Échec rafraîchissement Netatmo [{resp.status_code}] : {resp.text}")
            return None
    except requests.RequestException as e:
        print(f"❌ Erreur réseau Netatmo : {e}")
        return None

def fetch_data():
    print("--- S.H.A. 2026 : Récupération Météo Netatmo ---")

    client_id, client_secret, initial_refresh = load_app_config()
    if not client_id or not client_secret or not initial_refresh:
        print("❌ Abandon : Identifiants Netatmo manquants dans app.conf.")
        return

    tokens = get_tokens(initial_refresh)
    current_refresh = tokens.get('refresh_token')

    if not current_refresh:
        print("❌ Abandon : Aucun refresh_token disponible.")
        return

    new_tokens = refresh_access_token(client_id, client_secret, current_refresh)
    if not new_tokens:
        print("❌ Abandon : Impossible d'obtenir un access_token valide.")
        return

    access_token = new_tokens.get('access_token')
    url = "https://api.netatmo.com/api/getstationsdata"
    params = {"access_token": access_token}

    try:
        resp = requests.get(url, params=params, timeout=10)
        if resp.status_code != 200:
            print(f"❌ Erreur API Données Netatmo [{resp.status_code}]")
            return

        devices = resp.json().get('body', {}).get('devices', [])
        weather_list = []

        for s in devices:
            d = s.get('dashboard_data', {})
            # Station principale (Secteur -> battery_percent mis à 100 par défaut)
            weather_list.append({
                "name": s.get('station_name', 'Salon'),
                "type": "Indoor",
                "temp": d.get('Temperature', 0),
                "hum": d.get('Humidity', 0),
                "co2": d.get('CO2'),
                "pres": d.get('Pressure'),
                "battery": s.get('battery_percent', 100)
            })

            # Modules satellites (Sur piles)
            for m in s.get('modules', []):
                md = m.get('dashboard_data', {})
                if not md:
                    continue

                m_type = "Outdoor" if m.get('type') == "NAModule1" else "Indoor"
                
                # Extraction du pourcentage de batterie envoyé par l'API Netatmo
                battery_pct = m.get('battery_percent', 100)

                weather_list.append({
                    "name": m.get('module_name', 'Module'),
                    "type": m_type,
                    "temp": md.get('Temperature', 0),
                    "hum": md.get('Humidity', 0),
                    "co2": md.get('CO2'),
                    "battery": battery_pct
                })

        # Écriture atomique dans data/weather.json
        tmp_output_file = OUTPUT_FILE + ".tmp"
        with open(tmp_output_file, 'w', encoding='utf-8') as f:
            json.dump(weather_list, f, indent=4, ensure_ascii=False)
        os.replace(tmp_output_file, OUTPUT_FILE)

        print(f"✅ SUCCÈS : {len(weather_list)} modules mis à jour dans {OUTPUT_FILE}.")

    except Exception as e:
        print(f"💥 Erreur critique lors de la récupération : {e}")

if __name__ == "__main__":
    fetch_data()