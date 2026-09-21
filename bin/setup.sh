#!/usr/bin/env bash
#
# Provisionne le site WordPress de développement.
# Idempotent : peut être relancé sans détruire l'existant.
#
set -euo pipefail

WP_PATH="${WP_PATH:-/var/www/html}"
WP_URL="${WP_URL:-http://localhost:8080}"
WP_TITLE="${WP_TITLE:-RCP Stripe SEPA — Dev}"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-admin}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.test}"
RCP_VERSION="${RCP_VERSION:-latest}"

wp() { command wp --path="$WP_PATH" --allow-root "$@"; }

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

# Le client MariaDB embarqué dans l'image WordPress vérifie par défaut le
# certificat TLS du serveur ; MySQL 8 en présente un auto-signé, ce qui fait
# échouer la connexion. La communication reste interne au réseau Docker de
# développement : la vérification est désactivée pour le client en ligne de
# commande uniquement (PHP/mysqli n'est pas concerné).
MYSQL_CLIENT_OPTS="${MYSQL_CLIENT_OPTS:---skip-ssl}"

log "Attente de la base de données"
for _ in $(seq 1 60); do
  mysqladmin ping $MYSQL_CLIENT_OPTS \
    -h"${WORDPRESS_DB_HOST:-db}" \
    -u"${WORDPRESS_DB_USER:-wordpress}" \
    -p"${WORDPRESS_DB_PASSWORD:-wordpress}" --silent 2>/dev/null && break
  sleep 2
done

mysqladmin ping $MYSQL_CLIENT_OPTS \
  -h"${WORDPRESS_DB_HOST:-db}" \
  -u"${WORDPRESS_DB_USER:-wordpress}" \
  -p"${WORDPRESS_DB_PASSWORD:-wordpress}" --silent 2>/dev/null \
  || { echo "Base de données injoignable après 120 s." >&2; exit 1; }

log "Attente des fichiers du cœur WordPress"
for _ in $(seq 1 60); do
  [ -f "$WP_PATH/wp-settings.php" ] && break
  sleep 2
done
[ -f "$WP_PATH/wp-settings.php" ] || { echo "Cœur WordPress absent de $WP_PATH"; exit 1; }

if ! wp core is-installed 2>/dev/null; then
  log "Installation de WordPress"
  wp core install \
    --url="$WP_URL" \
    --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --skip-email
else
  log "WordPress déjà installé"
fi

# Le port publié peut changer d'un poste à l'autre : on réaligne les URL.
wp option update siteurl "$WP_URL"
wp option update home "$WP_URL"
wp option update timezone_string 'Europe/Paris'
wp rewrite structure '/%postname%/' --hard

# --- Restrict Content (socle libre, contient la passerelle Stripe) -------------
if ! wp plugin is-installed restrict-content; then
  log "Installation de Restrict Content ($RCP_VERSION)"
  if [ "$RCP_VERSION" = "latest" ]; then
    wp plugin install restrict-content --activate
  else
    wp plugin install restrict-content --version="$RCP_VERSION" --activate
  fi
else
  wp plugin activate restrict-content || true
fi

# --- Restrict Content Pro (archive propriétaire, non versionnée) --------------
# Déposer l'archive dans ./vendor-plugins/ pour tester contre la version Pro.
shopt -s nullglob
for zip in /vendor-plugins/restrict-content-pro*.zip; do
  log "Installation de Restrict Content Pro depuis $(basename "$zip")"
  wp plugin install "$zip" --force --activate
done
shopt -u nullglob

# --- Le plugin en cours de développement --------------------------------------
log "Activation de rcp-stripe-sepa"
wp plugin activate rcp-stripe-sepa 2>/dev/null \
  || log "rcp-stripe-sepa pas encore implémenté — activation ignorée"

# --- Secret de webhook --------------------------------------------------------
# Le secret vit dans wp-config.php, jamais en base (cahier des charges SEC-02).
# `wp config set` est idempotent et fonctionne même quand l'entrypoint de
# l'image n'a pas régénéré wp-config.php.
if [ -n "${STRIPE_WEBHOOK_SECRET:-}" ]; then
  log "Configuration du secret de webhook"
  wp config set RCP_SEPA_WEBHOOK_SECRET_TEST "$STRIPE_WEBHOOK_SECRET" --type=constant
else
  log "Aucun secret de webhook — générez-en un : make webhook-secret"
fi

# --- Tables de RCP ------------------------------------------------------------
# RCP crée ses tables sur le hook `admin_init` (priorité -99999). WP-CLI ne
# passant pas par l'administration, on déclenche le hook explicitement,
# faute de quoi tout appel à rcp_add_membership_level() échoue.
log "Création des tables de RCP"
wp eval '
if ( function_exists( "rcp_setup_components" ) ) {
    rcp_setup_components();
}
do_action( "admin_init" );
echo "tables RCP initialisees\n";
' --allow-root

# --- Configuration RCP --------------------------------------------------------
log "Configuration de RCP en mode test"
wp eval '
$o = get_option( "rcp_settings", array() );
$o["sandbox"]                   = 1;
$o["currency"]                  = "EUR";
$o["gateways"]["stripe"]        = 1;
$o["gateways"]["stripe_sepa"]   = 1;
$o["stripe_test_secret"]        = getenv( "STRIPE_TEST_SECRET_KEY" ) ?: "";
$o["stripe_test_publishable"]   = getenv( "STRIPE_TEST_PUBLISHABLE_KEY" ) ?: "";
update_option( "rcp_settings", $o );
echo "reglages RCP mis a jour\n";
' --allow-root

# --- Jeux de données de test --------------------------------------------------
log "Création des niveaux d'adhésion de test"
wp eval '
if ( ! function_exists( "rcp_add_membership_level" ) || ! function_exists( "rcp_get_membership_levels" ) ) {
    echo "RCP indisponible — niveaux non crees\n";
    return;
}

$existing = wp_list_pluck( rcp_get_membership_levels( array( "number" => 100 ) ), "name" );

$levels = array(
    array( "name" => "Mensuel", "price" => 10,  "duration" => 1, "duration_unit" => "month" ),
    array( "name" => "Annuel",  "price" => 99,  "duration" => 1, "duration_unit" => "year"  ),
    array( "name" => "A vie",   "price" => 299, "duration" => 0, "duration_unit" => "day"   ),
    array( "name" => "Gratuit", "price" => 0,   "duration" => 1, "duration_unit" => "month" ),
);

$created = 0;
foreach ( $levels as $level ) {
    if ( in_array( $level["name"], $existing, true ) ) {
        continue;
    }
    if ( rcp_add_membership_level( wp_parse_args( $level, array( "status" => "active" ) ) ) ) {
        $created++;
    }
}

printf( "%d niveau(x) cree(s), %d deja present(s)\n", $created, count( $existing ) );
' --allow-root || log "Création des niveaux ignorée"

log "Création des utilisateurs de test"
wp user create membre membre@example.test --role=subscriber --user_pass=membre 2>/dev/null || true
wp user create membre2 membre2@example.test --role=subscriber --user_pass=membre 2>/dev/null || true

log "Création d'une page de contenu protégé"
wp post create --post_type=page --post_title="Contenu réservé" \
  --post_content="Contenu réservé aux adhérents." --post_status=publish 2>/dev/null || true

log "Provisionnement terminé — $WP_URL/wp-admin ($WP_ADMIN_USER / $WP_ADMIN_PASSWORD)"
