# rcp-stripe-sepa

**Prélèvement SEPA (Stripe) pour Restrict Content Pro.**

Plugin WordPress ajoutant le prélèvement automatique SEPA comme moyen de paiement dans Restrict
Content Pro, qui n'intègre nativement que la carte bancaire via Stripe.

> **État du projet : jalon J1 — socle technique livré.**
> L'environnement Docker démarre, le plugin s'active, la détection de compatibilité RCP est
> implémentée et testée (54 tests, 90 % de couverture). La passerelle SEPA elle-même arrive au
> jalon J3 ; la suite de tests « webhooks » au jalon J4.

## Documentation

| Document | Contenu |
|---|---|
| [Cahier des charges](docs/cahier-des-charges.md) | Périmètre, contraintes, spécifications, sécurité, tests, jalons |
| [ADR-0001](docs/adr/0001-strategie-integration-rcp.md) | Pourquoi étendre la passerelle Stripe de RCP par héritage |
| [Compatibilité RCP](docs/compatibilite-rcp.md) | Variante libre / variante commerciale, détection, tests de contrat |
| [Webhooks en local](docs/webhooks-en-local.md) | Rejouer des événements signés, hors ligne ou via la CLI Stripe |
| [Environnement Stripe de test](docs/environnement-stripe-test.md) | Ce qui est nécessaire, ce qui ne l'est pas, et les pièges constatés |
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
make webhook-secret
make up
make stripe-doctor      # vérifie que l'environnement de test est exploitable
```

- Site : http://localhost:8080 — administration `admin` / `admin`
- E-mails capturés : http://localhost:8025

Si l'un de ces ports est déjà pris sur votre poste, changez `WP_PORT` ou `MAILPIT_PORT` dans `.env`
et relancez `make up` : le provisionnement réaligne les URL du site.

Pour les webhooks, deux modes — voir [docs/webhooks-en-local.md](docs/webhooks-en-local.md) :

```bash
# Hors ligne : rejeu de fixtures signées localement, sans réseau ni tunnel
make webhook-secret && make setup
make webhook-send FIXTURE=payment-intent-succeeded
make webhook-attack        # signature invalide, absente, antidatée

# En ligne : vrais événements relayés par la CLI Stripe
make stripe-listen         # reporter le whsec_ affiché dans .env, puis make setup
make stripe-trigger EVENT=payment_intent.succeeded
```

Pour alimenter le compte Stripe de test avec des parcours SEPA réels — voir
[docs/environnement-stripe-test.md](docs/environnement-stripe-test.md) :

```bash
make stripe-seed SCENARIO=success ARGS=--subscription
make webhook-capture EVENT_ID=evt_xxx NAME=mon-cas
```

## Tests

```bash
make test              # unitaires + intégration + contrat
make test-unit         # rapide, WordPress mocké, sans base
make test-integration  # WordPress et RCP réels
make test-contract     # contrat RCP et SDK Stripe
make test-webhooks     # à partir du jalon J4
make coverage          # couverture fusionnée, rapport HTML
make lint              # PHPCS + PHPStan
make matrix            # rejoue la suite sur la matrice PHP × WP × RCP
```

`make help` liste toutes les commandes.

Les suites **ne peuvent pas** être lancées dans une même invocation de PHPUnit : la suite `unit`
remplace les fonctions de WordPress via Brain Monkey, les autres les chargent réellement. Un
`vendor/bin/phpunit` sans `--testsuite` s'arrête avec un message explicite.

## Restrict Content Pro

L'environnement installe automatiquement **Restrict Content** (le socle libre publié sur
WordPress.org), qui contient la passerelle Stripe et suffit à l'essentiel des tests.

Pour tester contre **Restrict Content Pro**, déposez l'archive dans `vendor-plugins/` puis :

```bash
RCP_VARIANT=pro make prepare-tests && make test
```

Ce répertoire est ignoré par Git : l'archive est un produit commercial et ne doit jamais être
versionnée. Le plugin ne distingue jamais les deux variantes pour décider de son comportement — il
vérifie les capacités réellement présentes. Voir [docs/compatibilite-rcp.md](docs/compatibilite-rcp.md).

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
