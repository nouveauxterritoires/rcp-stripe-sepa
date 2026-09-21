#!/usr/bin/env bash
#
# Couverture de code fusionnée.
#
# Les suites ne peuvent pas partager un processus (voir tests/bootstrap.php) :
# chacune est mesurée séparément, puis les rapports sont fusionnés par phpcov.
#
set -euo pipefail

SUITES="${SUITES:-unit integration contract webhooks}"
OUT_DIR="${OUT_DIR:-tests/coverage}"
PARTS_DIR="$OUT_DIR/parts"

rm -rf "$PARTS_DIR"
mkdir -p "$PARTS_DIR"

for suite in $SUITES; do
  printf '\033[1;34m==>\033[0m Couverture — suite %s\n' "$suite"
  XDEBUG_MODE=coverage vendor/bin/phpunit \
    --testsuite "$suite" \
    --coverage-php "$PARTS_DIR/$suite.cov" \
    > "$PARTS_DIR/$suite.log" 2>&1 \
    || { echo "Échec de la suite $suite :"; tail -30 "$PARTS_DIR/$suite.log"; exit 1; }
done

printf '\033[1;34m==>\033[0m Fusion des rapports\n'
vendor/bin/phpcov merge "$PARTS_DIR" \
  --clover "$OUT_DIR/clover.xml" \
  --html "$OUT_DIR/html" \
  --text php://stdout
