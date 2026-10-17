#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
S.H.A. 2026 - Initialiseur OAuth2 Daikin Onecta
Fichier : /opt/zabbix-docker/apps/sha/scripts/daikin_auth.py
- Génère l'URL d'autorisation officielle Daikin Onecta.
- Échange le code d'autorisation contre le premier refresh_token et access_token.
- Enregistre les jetons dans config/daikin_tokens.json.
"""

import sys
import os
import json
import requests
import configparser

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
APP_CONF = os.path.join(BASE_DIR, "config", "app.conf")
TOKEN_FILE = os.path.join(BASE_DIR, "config", "daikin_tokens.json")

AUTH_URL = "https://idp.onecta.daikineurope.com/v1/oidc/authorize"
TOKEN_URL = "https://idp.onecta.daikineurope.com/v1/oidc/token"

def load_credentials():
    if not os.path.exists(APP_CONF):
        print(f"❌ [CONF] Fichier {APP_CONF} introuvable.")
        sys.exit(1)
        
    config = configparser.ConfigParser()
    config.read(APP_CONF, encoding='utf-8')
    try:
        client_id = config.get('api_keys', 'daikin_client_id').strip('"\'; ')
        client_secret = config.get('api_keys', 'daikin_secret').strip('"\'; ')
        # Callback standard configuré sur le portail développeur Daikin
        redirect_uri = config.get('api_keys', 'daikin_redirect_uri', fallback='http://localhost:8080/callback').strip('"\'; ')
        return client_id, client_secret, redirect_uri
    except Exception as e:
        print(f"❌ [CONF] Identifiants Daikin manquants dans app.conf : {e}")
        sys.exit(1)

def main():
    client_id, client_secret, redirect_uri = load_credentials()
    
    print("\n=======================================================")
    print(" 🚀 S.H.A. 2026 - INITIALISATION OAUTH2 DAIKIN ONECTA")
    print("=======================================================\n")
    
    # 1. Génération du lien de consentement
    scope = "openid onecta:basic.integration"
    auth_link = (
        f"{AUTH_URL}?response_type=code"
        f"&client_id={client_id}"
        f"&redirect_uri={redirect_uri}"
        f"&scope={requests.utils.quote(scope)}"
    )
    
    print("1. Ouvre ce lien dans ton navigateur pour autoriser l'application :")
    print(f"\n👉 {auth_link}\n")
    print("2. Connecte-toi avec ton compte Daikin Onecta.")
    print("3. Après redirection, copie soit l'URL complète, soit la valeur du paramètre 'code' (ex: ?code=XYZ123).\n")
    
    user_input = input("Colle ici l'URL de redirection ou le code : ").strip()
    
    if "code=" in user_input:
        code = user_input.split("code=")[1].split("&")[0]
    else:
        code = user_input

    if not code:
        print("❌ Code d'autorisation invalide.")
        sys.exit(1)

    print("\n⏳ Échange du code d'autorisation auprès de l'IdP Onecta...")
    
    payload = {
        "grant_type": "authorization_code",
        "client_id": client_id,
        "client_secret": client_secret,
        "code": code,
        "redirect_uri": redirect_uri
    }
    headers = {"Content-Type": "application/x-www-form-urlencoded"}

    try:
        resp = requests.post(TOKEN_URL, data=payload, headers=headers, timeout=15)
        if resp.status_code == 200:
            tokens = resp.json()
            os.makedirs(os.path.dirname(TOKEN_FILE), exist_ok=True)
            with open(TOKEN_FILE, 'w', encoding='utf-8') as f:
                json.dump(tokens, f, indent=4)
            print(f"✅ Succès ! Les jetons ont été enregistrés dans : {TOKEN_FILE}")
            print("👉 Tu peux maintenant démarrer le démon (scripts/daikin_daemon.py).\n")
        else:
            print(f"❌ Erreur échange de token (HTTP {resp.status_code}) : {resp.text}")
    except Exception as e:
        print(f"💥 Erreur réseau : {e}")

if __name__ == "__main__":
    main()
