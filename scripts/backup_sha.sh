#!/bin/bash

# Configuration
SOURCE="/var/www/html/sha"
DEST="/var/www/html/sha/backup"
DATE_DAY=$(date +%Y-%m-%d)
DATE_MONTH=$(date +%Y-%m)
DATE_YEAR=$(date +%Y)

# Créer le dossier backup s'il n'existe pas
mkdir -p "$DEST"

# 1. SAUVEGARDE QUOTIDIENNE
# On compresse tout en excluant le dossier backup lui-même
FILE_DAILY="sha_daily_$DATE_DAY.tar.gz"
tar -czf "$DEST/$FILE_DAILY" -C "$SOURCE" --exclude="backup" .

# 2. SAUVEGARDE MENSUELLE (si on est le 1er du mois)
if [ "$(date +%d)" == "01" ]; then
    cp "$DEST/$FILE_DAILY" "$DEST/sha_monthly_$DATE_MONTH.tar.gz"
fi

# 3. SAUVEGARDE ANNUELLE (si on est le 1er janvier)
if [ "$(date +%d%m)" == "0101" ]; then
    cp "$DEST/$FILE_DAILY" "$DEST/sha_yearly_$DATE_YEAR.tar.gz"
fi

# --- ROTATION (NETTOYAGE) ---

# Supprimer les backups quotidiens de plus de 15 jours
find "$DEST" -name "sha_daily_*" -type f -mtime +15 -delete

# Note: Pour le mensuel et l'annuel, on garde généralement 
# le dernier de chaque pour avoir un historique long.
# Si tu veux limiter les mensuels à 12 (1 an de recul) :
find "$DEST" -name "sha_monthly_*" -type f -mtime +365 -delete

echo "Backup terminé le $(date)" >> "$SOURCE/logs/backup.log"
