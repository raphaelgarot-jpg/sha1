#!/bin/bash
# SHA 2026 - Correction Globale des Droits (Chemin Dynamique)

echo "🔧 Correction des droits pour l'architecture SHA..."

# Détection automatique de la racine du projet
SCRIPT_DIR="$(dirname "$(realpath "$0")")"
SHA_ROOT="$(dirname "$SCRIPT_DIR")"

echo "📂 Racine détectée : $SHA_ROOT"

# 1. Propriétaire et Groupe (L'utilisateur actuel et le serveur Web)
sudo chown -R $USER:www-data "$SHA_ROOT"

# 2. Réinitialisation standard : Dossiers (755) et Fichiers (644)
find "$SHA_ROOT" -type d -exec chmod 755 {} +
find "$SHA_ROOT" -type f -exec chmod 644 {} +

# 3. Droits d'écriture pour les dossiers de données et logs
[ -d "$SHA_ROOT/data" ] && chmod -R 775 "$SHA_ROOT/data"
[ -d "$SHA_ROOT/logs" ] && chmod -R 775 "$SHA_ROOT/logs"

# 4. Rendre les fichiers de configuration modifiables par PHP
chmod 664 "$SHA_ROOT"/config/*.json 2>/dev/null || true
chmod 664 "$SHA_ROOT"/config/*.conf 2>/dev/null || true

# 5. Rendre les SCRIPTS exécutables
chmod +x "$SHA_ROOT"/scripts/*.sh 2>/dev/null || true
chmod +x "$SHA_ROOT"/scripts/*.py 2>/dev/null || true

# 6. Cas particuliers
[ -f "$SHA_ROOT/data/version.txt" ] && chmod 664 "$SHA_ROOT/data/version.txt"
[ -f "$SHA_ROOT/data/tasks.json" ] && chmod 664 "$SHA_ROOT/data/tasks.json"

echo "✅ Terminé. L'arborescence est maintenant propre et sécurisée."