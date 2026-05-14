#!/bin/bash
# SHA 2026 - Correction Globale des Droits v2.1

echo "🔧 Correction des droits pour l'architecture SHA..."

# 1. Propriétaire et Groupe (L'utilisateur actuel et le serveur Web)
sudo chown -R $USER:www-data /var/www/html/sha

# 2. Réinitialisation standard : Dossiers (755) et Fichiers (644)
find /var/www/html/sha -type d -exec chmod 755 {} +
find /var/www/html/sha -type f -exec chmod 644 {} +

# 3. Droits d'écriture pour les dossiers de données et logs
# On vérifie si les dossiers existent avant pour éviter les messages d'erreur
[ -d "/var/www/html/sha/data" ] && chmod -R 775 /var/www/html/sha/data
[ -d "/var/www/html/sha/logs" ] && chmod -R 775 /var/www/html/sha/logs

# 4. Rendre les fichiers de configuration modifiables par PHP
chmod 664 /var/www/html/sha/config/*.json 2>/dev/null
chmod 664 /var/www/html/sha/config/*.conf 2>/dev/null

# 5. Rendre les SCRIPTS exécutables (C'est ici que ça bloquait)
# On cible le dossier scripts/ spécifiquement
chmod +x /var/www/html/sha/scripts/*.sh 2>/dev/null
chmod +x /var/www/html/sha/scripts/*.py 2>/dev/null

# Cas particulier pour le fichier de version
[ -f "/var/www/html/sha/version.txt" ] && chmod 664 /var/www/html/sha/version.txt

echo "✅ Terminé. Ton arborescence est maintenant propre et sécurisée."