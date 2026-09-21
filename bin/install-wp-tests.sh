#!/usr/bin/env bash
#
# Installe la bibliothèque de tests de WordPress (wordpress-tests-lib)
# utilisée par la suite d'intégration PHPUnit.
#
set -euo pipefail

DB_NAME="${WP_TESTS_DB_NAME:-wordpress_test}"
DB_USER="${WP_TESTS_DB_USER:-root}"
DB_PASS="${WP_TESTS_DB_PASSWORD:-root}"
DB_HOST="${WP_TESTS_DB_HOST:-db-tests}"
WP_VERSION="${WP_VERSION:-latest}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

if [ "$WP_VERSION" = "latest" ]; then
  WP_TESTS_TAG="trunk"
else
  WP_TESTS_TAG="tags/$WP_VERSION"
fi

if [ ! -d "$WP_CORE_DIR" ]; then
  log "Téléchargement du cœur WordPress ($WP_VERSION)"
  mkdir -p "$WP_CORE_DIR"
  if [ "$WP_VERSION" = "latest" ]; then
    url="https://wordpress.org/latest.tar.gz"
  else
    url="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
  fi
  curl -sSL "$url" | tar --strip-components=1 -zx -C "$WP_CORE_DIR"
fi

if [ ! -d "$WP_TESTS_DIR" ]; then
  log "Récupération de la bibliothèque de tests ($WP_TESTS_TAG)"
  mkdir -p "$WP_TESTS_DIR"
  svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
  svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/"     "$WP_TESTS_DIR/data"
fi

if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
  log "Écriture de wp-tests-config.php"
  curl -sSL "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" \
    -o "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/youremptytestdbnamehere/${DB_NAME}/"              "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/yourusernamehere/${DB_USER}/"                     "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/yourpasswordhere/${DB_PASS}/"                     "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s|localhost|${DB_HOST}|"                            "$WP_TESTS_DIR/wp-tests-config.php"
fi

log "Création de la base de tests"
mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null \
  || log "Base de tests déjà présente"

# Restrict Content doit être présent dans le répertoire de plugins de test,
# la suite d'intégration en dépend.
PLUGIN_DIR="${WP_CORE_DIR}/wp-content/plugins"
mkdir -p "$PLUGIN_DIR"
if [ ! -d "$PLUGIN_DIR/restrict-content" ]; then
  log "Téléchargement de Restrict Content pour les tests d'intégration"
  curl -sSL "https://downloads.wordpress.org/plugin/restrict-content.zip" -o /tmp/rc.zip
  unzip -q -o /tmp/rc.zip -d "$PLUGIN_DIR"
fi

log "Environnement de tests prêt"
