#!/usr/bin/env bash
#
# CDC NR-05 : pas de fausse complétion.
# Tests ignorés, marqueurs de substitution et branches non implémentées bloquent.
#
set -uo pipefail

status=0
fail() { printf '\033[0;31mÉCHEC\033[0m %s\n' "$1"; status=1; }
pass() { printf '\033[0;32mOK\033[0m    %s\n' "$1"; }

paths=()
for p in src tests rcp-stripe-sepa.php; do [ -e "$p" ] && paths+=("$p"); done
[ ${#paths[@]} -eq 0 ] && { echo "Rien à analyser."; exit 0; }

check() {
  if grep -rInE --binary-files=without-match "$2" "${paths[@]}" 2>/dev/null; then
    fail "$1"
  else
    pass "$1"
  fi
}

check "Aucun test marqué skipped/incomplete" '(markTestSkipped|markTestIncomplete|@group\s+skip)'
check "Aucun test isolé (only)"              '\.only\('
check "Aucun marqueur de substitution"       '(TODO:|FIXME:|XXX:|@todo\s+implement|not implemented)'

exit $status
