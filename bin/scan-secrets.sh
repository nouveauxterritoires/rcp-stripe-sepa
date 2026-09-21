#!/usr/bin/env bash
#
# Contrôles de sécurité statiques (cahier des charges SEC-01, SEC-12, R-API-1).
#
# Deux périmètres :
#   - « livré »  : le code du plugin distribué. Aucun IBAN, jamais.
#   - « dépôt »  : tout le dépôt, outillage compris. Aucun secret, et tout IBAN
#                  présent doit être un IBAN de test documenté par Stripe.
#
set -uo pipefail

status=0

fail() { printf '\033[0;31mÉCHEC\033[0m %s\n' "$1"; status=1; }
pass() { printf '\033[0;32mOK\033[0m    %s\n' "$1"; }

# IBAN de test publiés par Stripe. Seuls ceux-ci sont tolérés dans le dépôt.
# https://docs.stripe.com/payments/sepa-debit/accept-a-payment#test-integration
TEST_IBANS='FR1420041010050500013M02606|FR3020041010050500013M02609|FR8420041010050500013M02607|FR7920041010050500013M02600|FR5720041010050500013M02608|FR9720041010050000000343434|FR5920041010050000000121212|FR9720041010050000002222227|DE89370400440532013000|DE08370400440532013003|DE62370400440532013001|DE78370400440532013004|DE35370400440532013002'

# bash 3.2 (macOS) ne connaît pas les références nommées : les deux listes sont
# construites explicitement.
shipped=()
for path in src rcp-stripe-sepa.php uninstall.php assets; do
  [ -e "$path" ] && shipped+=("$path")
done

repository=()
for path in src rcp-stripe-sepa.php uninstall.php assets bin tests docker; do
  [ -e "$path" ] && repository+=("$path")
done

# Les commentaires citent légitimement les motifs recherchés : la documentation
# du code explique par exemple pourquoi setApiVersion() ne doit jamais servir.
strip_comments() {
  grep -vE '^[^:]+:[0-9]+:[[:space:]]*(\*|//|#|;)'
}

check() { # libellé, motif, chemins…
  local label="$1" pattern="$2"; shift 2
  local hits

  if [ $# -eq 0 ]; then pass "$label (rien à analyser)"; return; fi

  hits="$( grep -rInE --binary-files=without-match "$pattern" "$@" 2>/dev/null | strip_comments )"

  if [ -n "$hits" ]; then
    printf '%s\n' "$hits"
    fail "$label"
  else
    pass "$label"
  fi
}

echo "Périmètre : dépôt complet"
check "  Aucune clé secrète Stripe"    '(sk|rk)_(live|test)_[A-Za-z0-9]{16,}' "${repository[@]}"
check "  Aucun secret de webhook"      'whsec_[A-Za-z0-9]{16,}'               "${repository[@]}"
check "  Aucune clé publiable en dur"  'pk_(live|test)_[A-Za-z0-9]{16,}'      "${repository[@]}"
check "  Aucun appel à setApiVersion"  'setApiVersion\('                      "${repository[@]}"

echo "Périmètre : code livré"
check "  Aucun IBAN"                   '[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}'      "${shipped[@]}"
check "  Aucun IBAN reçu côté serveur" '\$_(POST|GET|REQUEST)\[[^]]*(iban|IBAN)' "${shipped[@]}"

# Tout IBAN présent ailleurs dans le dépôt doit figurer dans la liste de test.
echo "Périmètre : IBAN de l'outillage et des tests"
unknown="$(
  grep -rIohE --binary-files=without-match '[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}' "${repository[@]}" 2>/dev/null \
    | sort -u \
    | grep -vE "^($TEST_IBANS)$" || true
)"

if [ -n "$unknown" ]; then
  printf '%s\n' "$unknown"
  fail "  Tous les IBAN sont des IBAN de test Stripe"
else
  pass "  Tous les IBAN sont des IBAN de test Stripe"
fi

exit $status
