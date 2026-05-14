import requests
import json
import os
import configparser

# --- CONFIGURATION S.H.A. 2026 ---
config = configparser.ConfigParser()
config.read('/var/www/html/sha/config/app.conf')
CLIENT_ID = config['netatmo']['client_id']
CLIENT_SECRET = config['netatmo']['client_secret']
# Colle ici le Refresh Token généré sur le site Netatmo
REFRESH_TOKEN = config['netatmo']['refresh_token']

TOKEN_FILE    = "/var/www/html/sha/config/netatmo_tokens.json"
OUTPUT_FILE   = "/var/www/html/sha/data/weather.json"

def get_tokens():
    """Récupère les tokens stockés ou utilise le refresh initial"""
    if os.path.exists(TOKEN_FILE):
        try:
            with open(TOKEN_FILE, 'r') as f:
                content = f.read().strip()
                if content:
                    return json.loads(content)
        except (json.JSONDecodeError, ValueError):
            print("! Fichier tokens mal formé. Réinitialisation...")
            if os.path.exists(TOKEN_FILE):
                os.remove(TOKEN_FILE)
    
    # Si le fichier n'existe pas ou est corrompu, on rend le refresh initial
    return {"refresh_token": REFRESH_TOKEN}

def refresh_access_token(current_refresh):
    """Demande un nouvel access_token à l'API Netatmo"""
    url = "https://api.netatmo.com/oauth2/token"
    payload = {
        "grant_type": "refresh_token",
        "refresh_token": current_refresh,
        "client_id": CLIENT_ID,
        "client_secret": CLIENT_SECRET
    }
    
    # On ajoute un User-Agent pour éviter les blocages pare-feu
    headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"}
    
    try:
        resp = requests.post(url, data=payload, headers=headers, timeout=10)
        if resp.status_code == 200:
            tokens = resp.json()
            # On sauvegarde les nouveaux tokens pour la prochaine fois
            with open(TOKEN_FILE, 'w') as f:
                json.dump(tokens, f, indent=4)
            return tokens
        else:
            print(f"ERREUR Rafraîchissement [{resp.status_code}] : {resp.text}")
            return None
    except Exception as e:
        print(f"ERREUR Réseau : {str(e)}")
        return None

def fetch_data():
    print("--- S.H.A. 2026 : Récupération Météo ---")
    
    # 1. Obtenir les tokens valides
    tokens = get_tokens()
    new_tokens = refresh_access_token(tokens.get('refresh_token'))
    
    if not new_tokens:
        print("ÉCHEC : Impossible d'obtenir un token valide.")
        return

    access_token = new_tokens.get('access_token')
    
    # 2. Récupérer les données de la station
    url = "https://api.netatmo.com/api/getstationsdata"
    params = {"access_token": access_token}
    
    try:
        resp = requests.get(url, params=params, timeout=10)
        if resp.status_code != 200:
            print(f"ERREUR API Données : {resp.status_code}")
            return

        data = resp.json().get('body', {}).get('devices', [])
        weather_list = []
        
        for s in data:
            # Station principale (Intérieur)
            d = s.get('dashboard_data', {})
            weather_list.append({
                "name": s.get('station_name', 'Salon'),
                "type": "Indoor",
                "temp": d.get('Temperature', 0),
                "hum":  d.get('Humidity', 0),
                "co2":  d.get('CO2'),
                "pres": d.get('Pressure')
            })
            
            # Modules (Extérieur, Pluie, Vent, etc.)
            for m in s.get('modules', []):
                md = m.get('dashboard_data', {})
                if not md: continue
                
                # Type NAModule1 = Extérieur
                m_type = "Outdoor" if m.get('type') == "NAModule1" else "Indoor"
                
                weather_list.append({
                    "name": m.get('module_name', 'Module'),
                    "type": m_type,
                    "temp": md.get('Temperature', 0),
                    "hum":  md.get('Humidity', 0),
                    "co2":  md.get('CO2')
                })

        # 3. Sauvegarder le résultat pour le Dashboard PHP
        with open(OUTPUT_FILE, 'w') as f:
            json.dump(weather_list, f, indent=4)
            
        print(f"SUCCÈS : {len(weather_list)} modules mis à jour.")

    except Exception as e:
        print(f"ERREUR CRITIQUE : {str(e)}")

if __name__ == "__main__":
    fetch_data()
