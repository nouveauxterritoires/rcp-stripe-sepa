#!/usr/bin/env bash
#
# Contrôle du seuil de couverture à partir d'un rapport Clover.
# Usage : check-coverage.sh <clover.xml> <seuil en %>
#
set -euo pipefail

file="${1:?rapport clover attendu}"
threshold="${2:-80}"

[ -f "$file" ] || { echo "Rapport introuvable : $file"; exit 1; }

read -r covered total < <(
  php -r '
    $xml = simplexml_load_file($argv[1]);
    $m = $xml->project->metrics;
    echo (int) $m["coveredstatements"], " ", (int) $m["statements"];
  ' "$file"
)

[ "$total" -gt 0 ] || { echo "Aucune instruction mesurée."; exit 1; }

percent=$(php -r 'printf("%.2f", $argv[1] / $argv[2] * 100);' "$covered" "$total")

echo "Couverture : ${percent}% (${covered}/${total}) — seuil ${threshold}%"

php -r 'exit((float) $argv[1] >= (float) $argv[2] ? 0 : 1);' "$percent" "$threshold" \
  || { echo "Couverture insuffisante."; exit 1; }
