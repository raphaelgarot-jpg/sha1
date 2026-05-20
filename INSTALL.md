Markdown

# 🛠️ S.H.A. - Smart Home Architecture (System Setup)

Ce document liste toutes les configurations système nécessaires au bon fonctionnement de S.H.A. qui **ne sont pas** incluses dans le dépôt Git (car externes au dossier `/var/www/html/sha` ou liées à l'OS).

---

## 1. 🚀 Service Worker (Cache RAM Tasmota)

Pour garantir un affichage instantané du dashboard, un script Python (`scripts/sha_cache_builder.py`) tourne en boucle pour interroger les appareils et écrire les résultats en RAM (`/dev/shm/`).

### Création du service système (Daemon)
Créer le fichier de service :
```bash
sudo nano /etc/systemd/system/sha-worker.service

Contenu du fichier :
Ini, TOML

[Unit]
Description=S.H.A. 2026 - Tasmota Cache Worker
After=network.target

[Service]
ExecStart=/usr/bin/python3 /var/www/html/sha/scripts/sha_cache_builder.py
WorkingDirectory=/var/www/html/sha/
StandardOutput=null
StandardError=journal
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target

Activation du service
Bash

sudo systemctl daemon-reload
sudo systemctl enable sha-worker.service
sudo systemctl start sha-worker.service

2. 📁 Lien Symbolique RAM -> Data

Le script Python écrit les données en direct dans la mémoire vive (/dev/shm/) pour préserver la carte SD. PHP lit ces données via un lien symbolique dans le dossier data/.

Commande pour recréer le lien (à faire après un formatage) :
Bash

ln -s /dev/shm/sha_live.json /var/www/html/sha/data/sha_live.json

(Note : Ce lien symbolique est ignoré par Git via le .gitignore car il pointe vers l'extérieur du projet).
3. 🛡️ Fichiers Ignorés (.gitignore)

Les fichiers suivants sont intentionnellement exclus du dépôt GitHub pour des raisons de sécurité ou de volatilité. Ils doivent être recréés manuellement lors d'une nouvelle installation :

    config/app.conf : Contient les tokens API sensibles (Netatmo, etc.).

    data/sha_live.json : Lien symbolique vers la RAM (voir point 2).

    data/version.txt : Géré automatiquement par les hooks Git locaux.

4. 🪝 Hooks Git (Versionnage automatique)

Pour que la version s'incrémente automatiquement en allemand lors d'un commit local, le hook pre-commit doit être initialisé.
(Les hooks ne sont pas poussés sur GitHub, ils restent en local).

Créer le fichier :
Bash

nano /var/www/html/sha/.git/hooks/pre-commit
chmod +x /var/www/html/sha/.git/hooks/pre-commit
