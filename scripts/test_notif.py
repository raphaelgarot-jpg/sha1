import json
import os
from pywebpush import webpush, WebPushException

# --- CONFIGURATION ---
SUB_FILE = "/var/www/html/sha/config/devices.json" #
PEM_FILE = "/var/www/html/sha/config/private_key.pem" #

VAPID_CLAIMS = {
    "sub": "mailto:admin@rgsv.fr" #
}

def send_to_devices(title, message, count=1):
    """
    Envoie une notification push native à TOUS les appareils enregistrés.
    """
    try:
        # 1. Vérification des fichiers indispensables
        if not os.path.exists(SUB_FILE):
            print(f"❌ Erreur : Le fichier {SUB_FILE} est introuvable.")
            return
        
        if not os.path.exists(PEM_FILE):
            print(f"❌ Erreur : Le fichier {PEM_FILE} est introuvable.")
            return

        # 2. Charger les abonnements
        with open(SUB_FILE, "r") as f:
            subs = json.load(f)

        # Gestion de la migration : si c'est encore un objet seul, on le met en liste
        if isinstance(subs, dict):
            subs = [subs]

        # 3. Préparer les données (Payload)
        payload = {
            "title": title,
            "body": message,
            "badge": count
        }

        print(f"🚀 Envoi vers {len(subs)} appareil(s)...")

        # 4. Boucle d'envoi via pywebpush
        for i, sub_info in enumerate(subs):
            try:
                webpush(
                    subscription_info=sub_info,
                    data=json.dumps(payload),
                    vapid_private_key=PEM_FILE,
                    vapid_claims=VAPID_CLAIMS
                )
                print(f"✅ Appareil {i+1} : Notification envoyée !")
            except WebPushException as ex:
                print(f"⚠️ Appareil {i+1} : Échec (Abonnement peut-être expiré)")
                if ex.response and ex.response.json():
                    print(f"   Détails : {ex.response.json()}")

    except Exception as e:
        print(f"💥 Erreur système : {e}")

# --- ZONE DE TEST ---
if __name__ == "__main__":
    send_to_devices(
        title="S.H.A. 2026", 
        message="Test multi-devices réussi ! 📱📱", 
        count=1
    )
