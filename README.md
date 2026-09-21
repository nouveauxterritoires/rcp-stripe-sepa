# rcp-stripe-sepa

**Prélèvement SEPA (Stripe) pour Restrict Content Pro.**

Plugin WordPress ajoutant le prélèvement automatique SEPA comme moyen de paiement dans Restrict
Content Pro, qui n'intègre nativement que la carte bancaire via Stripe.

> **État du projet : jalon J0 — cadrage.**
> Le cahier des charges, les décisions d'architecture, l'environnement de développement et
> l'outillage de test sont en place. Le code du plugin n'est pas encore écrit : les suites de tests
> sont volontairement vides jusqu'au jalon J1.

## Documentation

| Document | Contenu |
|---|---|
| [Cahier des charges](docs/cahier-des-charges.md) | Périmètre, contraintes, spécifications, sécurité, tests, jalons |
| [ADR-0001](docs/adr/0001-strategie-integration-rcp.md) | Pourquoi étendre la passerelle Stripe de RCP par héritage |
| [SECURITY.md](SECURITY.md) | Politique de sécurité et checklist de revue |

## Fonctionnalités visées

- Adhésions **récurrentes** payées par mandat SEPA (Stripe Billing).
- Adhésions **à vie / paiements uniques** par PaymentIntent SEPA.
- **Migration** du moyen de paiement d'un membre : carte → SEPA, sans changer le prix ni l'échéance.
- Gestion complète de l'asynchronisme SEPA : état « prélèvement en cours », webhooks, impayés, litiges.
- Modes **bac à sable** et **production** strictement cloisonnés.

## Démarrage rapide

Prérequis : Docker, Docker Compose v2, et un compte Stripe en mode test.

```bash
cp .env.example .env
# Renseigner STRIPE_TEST_SECRET_KEY et STRIPE_TEST_PUBLISHABLE_KEY dans .env
make up
```

- Site : http://localhost:8080 — administration `admin` / `admin`
- E-mails capturés : http://localhost:8025

Pour recevoir les webhooks Stripe en local, dans un second terminal :

```bash
make stripe-listen
```

Reporter le secret `whsec_…` affiché dans `.env` (`STRIPE_WEBHOOK_SECRET`), puis `make setup`.

## Tests

```bash
make test              # unitaires + intégration + contrat + webhooks
make test-unit         # rapide, WordPress mocké, sans base
make test-integration  # WordPress et RCP réels
make test-contract     # schéma Stripe (stripe-mock) et contrat RCP
make test-webhooks     # signature, idempotence, désordre, livemode
make test-e2e          # Playwright, parcours de bout en bout
make coverage          # rapport HTML, seuil 80 %
make lint              # PHPCS + PHPStan
make matrix            # rejoue la suite sur la matrice PHP × WP × RCP
```

`make help` liste toutes les commandes.

## Restrict Content Pro

L'environnement installe automatiquement **Restrict Content** (le socle libre publié sur
WordPress.org), qui contient la passerelle Stripe et suffit à l'essentiel des tests.

Pour tester contre **Restrict Content Pro**, déposez l'archive dans `vendor-plugins/` puis relancez
`make setup`. Ce répertoire est ignoré par Git : l'archive est un produit commercial et ne doit
jamais être versionnée.

## Sécurité

La sécurité est l'exigence prioritaire du projet. Points structurants :

- l'IBAN n'atteint **jamais** le serveur WordPress — il est saisi dans un Stripe Element ;
- les webhooks sont authentifiés par **vérification de signature HMAC**, contrairement au listener
  natif de RCP ;
- chaque événement est traité de façon **idempotente** ; le rejeu ne peut pas doubler une facturation ;
- le cloisonnement test / production est vérifié par le champ `livemode` de chaque événement ;
- les analyses `bin/scan-secrets.sh` et `bin/scan-placeholders.sh` bloquent la CI en cas de secret,
  d'IBAN en dur ou de fausse complétion.

Voir le chapitre 9 du cahier des charges et [SECURITY.md](SECURITY.md).

## Licence

GPL-2.0-or-later, conformément à WordPress et à Restrict Content Pro.
