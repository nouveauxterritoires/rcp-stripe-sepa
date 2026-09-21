# rcp-stripe-sepa — commandes de développement et de test.
#
# Prérequis : Docker + Docker Compose v2.
# Copier .env.example vers .env avant le premier `make up`.

SHELL := /bin/bash
DC    := docker compose
CLI   := $(DC) run --rm wpcli

.DEFAULT_GOAL := help
.PHONY: help up down clean shell wp setup logs \
        test test-unit test-integration test-contract test-webhooks test-e2e \
        coverage lint fix matrix build \
        webhook-secret webhook-list webhook-send webhook-replay webhook-capture \
        webhook-events webhook-attack stripe-listen stripe-trigger

help: ## Affiche cette aide
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'

# --- Cycle de vie de la pile --------------------------------------------------

up: ## Démarre la pile et provisionne le site
	$(DC) up -d db db-tests wordpress mailpit stripe-mock
	$(CLI) "bash /var/www/html/wp-content/plugins/rcp-stripe-sepa/bin/setup.sh"
	@echo "Site      : http://localhost:$${WP_PORT:-8080}"
	@echo "E-mails   : http://localhost:$${MAILPIT_PORT:-8025}"

down: ## Arrête la pile (conserve les données)
	$(DC) down

clean: ## Détruit la pile, les volumes et les données
	$(DC) down -v --remove-orphans

setup: ## Rejoue le provisionnement
	$(CLI) "bash /var/www/html/wp-content/plugins/rcp-stripe-sepa/bin/setup.sh"

shell: ## Ouvre un shell dans le conteneur WordPress
	$(DC) exec wordpress bash

wp: ## Exécute une commande WP-CLI — make wp CMD="plugin list"
	$(CLI) "wp --path=/var/www/html --allow-root $(CMD)"

logs: ## Suit les journaux
	$(DC) logs -f wordpress

# --- Tests --------------------------------------------------------------------

prepare-tests: ## Installe la bibliothèque de tests WordPress
	$(CLI) "bash /var/www/html/wp-content/plugins/rcp-stripe-sepa/bin/install-wp-tests.sh"

# La suite « webhooks » est livrée au jalon J4 ; elle est exclue de la cible
# par défaut tant qu'elle est vide, une suite sans test faisant échouer PHPUnit.
test: test-unit test-integration test-contract ## Exécute les suites PHP disponibles

test-unit: ## Tests unitaires (WordPress mocké, sans base)
	$(CLI) "cd /var/www/html/wp-content/plugins/rcp-stripe-sepa && composer install --no-interaction && vendor/bin/phpunit --testsuite unit"

test-integration: prepare-tests ## Tests d'intégration (WordPress + RCP réels)
	$(CLI) "cd /var/www/html/wp-content/plugins/rcp-stripe-sepa && vendor/bin/phpunit --testsuite integration"

test-contract: ## Tests de contrat (stripe-mock + contrat RCP)
	$(CLI) "cd /var/www/html/wp-content/plugins/rcp-stripe-sepa && vendor/bin/phpunit --testsuite contract"

test-webhooks: ## Tests des webhooks (signature, idempotence, désordre)
	$(CLI) "cd /var/www/html/wp-content/plugins/rcp-stripe-sepa && vendor/bin/phpunit --testsuite webhooks"

test-e2e: ## Tests de bout en bout (Playwright)
	npx playwright test

coverage: prepare-tests ## Couverture fusionnée de toutes les suites (tests/coverage/)
	$(CLI) "cd /var/www/html/wp-content/plugins/rcp-stripe-sepa && bash bin/coverage.sh"

# --- Qualité ------------------------------------------------------------------

lint: ## PHPCS + PHPStan (ESLint ajouté avec le JavaScript, au jalon J3)
	$(CLI) "cd /var/www/html/wp-content/plugins/rcp-stripe-sepa && vendor/bin/phpcs && vendor/bin/phpstan analyse --memory-limit=1G"

fix: ## Corrige automatiquement les écarts de style PHP
	$(CLI) "cd /var/www/html/wp-content/plugins/rcp-stripe-sepa && vendor/bin/phpcbf || true"

# --- Webhooks -----------------------------------------------------------------
# Deux modes complémentaires :
#   - hors ligne : les fixtures sont signées localement et rejouées (webhook-*) ;
#   - en ligne   : la CLI Stripe relaie de vrais événements (stripe-*).

webhook-secret: ## Génère un secret de webhook pour le développement local
	php bin/webhook.php secret

webhook-list: ## Liste les fixtures de webhooks disponibles
	php bin/webhook.php list

webhook-send: ## Rejoue une fixture — make webhook-send FIXTURE=synthetic-payment-intent-succeeded
	php bin/webhook.php send $(FIXTURE) $(ARGS)

webhook-replay: ## Rejoue un événement Stripe réel — make webhook-replay EVENT_ID=evt_xxx
	php bin/webhook.php replay $(EVENT_ID) $(ARGS)

webhook-capture: ## Enregistre un événement en fixture — make webhook-capture EVENT_ID=evt_xxx NAME=mon-cas
	php bin/webhook.php capture $(EVENT_ID) $(NAME)

webhook-events: ## Liste les derniers événements du compte Stripe de test
	php bin/webhook.php events $(ARGS)

webhook-attack: ## Rejoue une fixture avec une signature invalide, absente et antidatée
	@echo "--- signature invalide (attendu : 400)"
	-php bin/webhook.php send $(or $(FIXTURE),synthetic-payment-intent-succeeded) --bad-signature
	@echo "--- signature absente (attendu : 400)"
	-php bin/webhook.php send $(or $(FIXTURE),synthetic-payment-intent-succeeded) --no-signature
	@echo "--- signature antidatée de 10 minutes (attendu : 400)"
	-php bin/webhook.php send $(or $(FIXTURE),synthetic-payment-intent-succeeded) --age=600

# --- Stripe -------------------------------------------------------------------

stripe-listen: ## Relaie de vrais webhooks Stripe vers le site local (affiche le whsec_ à reporter dans .env)
	$(DC) --profile stripe up stripe-cli

stripe-trigger: ## Déclenche un événement chez Stripe — make stripe-trigger EVENT=payment_intent.succeeded
	$(DC) --profile stripe run --rm stripe-cli trigger $(EVENT)

# --- Matrice ------------------------------------------------------------------

matrix: ## Rejoue la suite sur la matrice PHP × WP × RCP
	@for php in 7.4 8.0 8.1 8.2 8.3; do \
	  for wp in latest 6.7; do \
	    echo "=== PHP $$php / WP $$wp ==="; \
	    PHP_VERSION=$$php WP_VERSION=$$wp $(MAKE) --no-print-directory clean up test || exit 1; \
	  done; \
	done

build: ## Construit l'archive distribuable
	bash bin/build.sh
