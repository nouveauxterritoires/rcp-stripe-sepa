#!/usr/bin/env bash
#
# CDC T-SEC-12 / SEC-01 : aucun secret ni IBAN en dur dans le code livré.
# Les IBAN de test sont tolérés dans tests/ et docs/ uniquement.
#
set -uo pipefail

status=0
scan_paths=(src rcp-stripe-sepa.php uninstall.php assets bin)
existing=()
for p in "${scan_paths[@]}"; do [ -e "$p" ] && existing+=("$p"); done
[ ${#existing[@]} -eq 0 ] && { echo "Rien à analyser."; exit 0; }

fail() { printf '\033[0;31mÉCHEC\033[0m %s\n' "$1"; status=1; }
pass() { printf '\033[0;32mOK\033[0m    %s\n' "$1"; }

check() { # libellé, motif
  local label="$1" pattern="$2"
  if grep -rInE --binary-files=without-match "$pattern" "${existing[@]}" 2>/dev/null; then
    fail "$label"
  else
    pass "$label"
  fi
}

check "Aucune clé secrète Stripe"        '(sk|rk)_(live|test)_[A-Za-z0-9]{16,}'
check "Aucun secret de webhook"          'whsec_[A-Za-z0-9]{16,}'
check "Aucune clé publiable en dur"      'pk_(live|test)_[A-Za-z0-9]{16,}'
check "Aucun IBAN en dur"                '[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}'
check "Aucun IBAN reçu côté serveur"     '\$_(POST|GET|REQUEST)\[[^]]*(iban|IBAN)'
check "Aucun appel à setApiVersion"      'setApiVersion\('

exit $status
