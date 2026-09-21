#!/usr/bin/env bash
#
# Construit l'archive distribuable du plugin.
#
# N'embarque que ce qui est nécessaire à l'exécution : ni tests, ni outillage,
# ni dépendances de développement.
#
set -euo pipefail

SLUG="rcp-stripe-sepa"
BUILD_DIR="${BUILD_DIR:-build}"
TARGET="$BUILD_DIR/$SLUG"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

version="$( grep -m1 "^ \* Version:" "$SLUG.php" | sed -E 's/.*Version:[[:space:]]*//' )"
[ -n "$version" ] || { echo "Version introuvable dans $SLUG.php" >&2; exit 1; }

log "Construction de $SLUG $version"

# Aucun secret ni fausse complétion ne doit partir dans une archive.
bash bin/scan-secrets.sh > /dev/null
bash bin/scan-placeholders.sh > /dev/null

rm -rf "$TARGET"
mkdir -p "$TARGET"

for item in "$SLUG.php" uninstall.php src assets languages readme.txt LICENSE; do
  [ -e "$item" ] && cp -R "$item" "$TARGET/"
done

log "Dépendances d'exécution"
composer install --no-dev --optimize-autoloader --quiet
[ -d vendor ] && cp -R vendor "$TARGET/"

# Restaure l'outillage de développement.
composer install --quiet

archive="$BUILD_DIR/$SLUG-$version.zip"
rm -f "$archive"

log "Archive"
( cd "$BUILD_DIR" && zip -qr "$SLUG-$version.zip" "$SLUG" )

log "Terminé : $archive ($( du -h "$archive" | cut -f1 ))"
