#!/bin/bash

# Détermination 100% dynamique des dossiers par rapport à l'emplacement réel du script
SCRIPT_DIR="$(dirname "$(realpath "$0")")"
SHA_ROOT="$(dirname "$SCRIPT_DIR")"
DATA_DIR="$SHA_ROOT/data"

# Cible par défaut : la racine automatiquement détectée (hôte ou conteneur)
TARGET_DIR="${1:-$SHA_ROOT}"
OUTPUT_FILE="$DATA_DIR/rapport_structure.txt"

if [ ! -d "$TARGET_DIR" ]; then
    echo "Erreur: Le dossier $TARGET_DIR n'existe pas."
    exit 1
fi

# S'assurer que le dossier data existe localement
mkdir -p "$DATA_DIR"

echo "⏳ Analyse et génération du rapport pour : $(realpath "$TARGET_DIR")..."

# Bloc d'exécution principale : tout le flux est redirigé vers l'OUTPUT_FILE dynamique
{
echo "==============================================================================="
echo " ARBORESCENCE DU DOSSIER : $(realpath "$TARGET_DIR")"
echo "==============================================================================="
echo ""

# 1. Génération de l'arborescence (tree utilise des exclusions relatives natives)
if command -v tree &> /dev/null; then
    tree -a -I '.git|config|backup|img|logs' "$TARGET_DIR"
else
    echo "(Rendu alternatif via find, exclusions appliquées :)"
    # 🟩 CORRECTION : Exclusions basées dynamiquement sur la cible courante
    find "$TARGET_DIR" \( -path "*/.git*" -o -path "$TARGET_DIR/config*" -o -path "$TARGET_DIR/backup*" -o -path "$TARGET_DIR/assets/img*" -o -path "$TARGET_DIR/img*" -o -path "$TARGET_DIR/logs*" \) -prune -o -print
fi

echo ""
echo "==============================================================================="
echo " CONTENU DES FICHIERS"
echo "==============================================================================="
echo ""

# 2. Parcourir et afficher le contenu des fichiers non exclus
# 🟩 CORRECTION : Même logique dynamique appliquée ici pour le parcours profond
find "$TARGET_DIR" \( -path "*/.git*" -o -path "$TARGET_DIR/config*" -o -path "$TARGET_DIR/backup*" -o -path "$TARGET_DIR/assets/img*" -o -path "$TARGET_DIR/img*" -o -path "$TARGET_DIR/logs*" \) -prune -o -type f -print | while read -r file; do

    # Sécurité pour éviter que le script ne tente d'analyser le rapport qu'il est en train d'écrire
    if [ "$(realpath "$file")" = "$(realpath "$OUTPUT_FILE")" ]; then
        continue
    fi

    filepath=$(realpath "$file")
    mod_date=$(stat -c '%y' "$file" | cut -d'.' -f1)

    echo "-------------------------------------------------------------------------------"
    echo "FICHIER : $filepath"
    echo "MODIFIÉ LE : $mod_date"
    echo "-------------------------------------------------------------------------------"

    if file "$file" | grep -qE 'text|empty'; then
        cat "$file"
    else
        echo "[Fichier binaire ou non-textuel - Contenu masqué]"
    fi
    echo ""
done
} > "$OUTPUT_FILE"

echo "✅ Rapport généré avec succès dans : $OUTPUT_FILE"
