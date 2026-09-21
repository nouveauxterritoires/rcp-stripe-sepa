# Rejouer les webhooks Stripe en local

Le site de développement n'est pas joignable depuis Internet. Deux modes
complémentaires permettent malgré tout d'exercer le traitement des webhooks.

| | Hors ligne | En ligne |
|---|---|---|
| Outil | `bin/webhook.php` (`make webhook-*`) | CLI Stripe (`make stripe-*`) |
| Réseau requis | Non (sauf `replay` et `capture`) | Oui |
| Compte Stripe activé requis | Non | Oui |
| Charges utiles | Fixtures du dépôt | Événements réels |
| Reproductible | Oui, à l'identique | Non |
| Usage | Développement quotidien, tests de non-régression, cas d'erreur | Validation finale, capture de fixtures |

Dans les deux cas la **signature est vérifiée réellement** : le rejeu hors ligne
signe la charge utile avec le même secret que celui configuré dans WordPress,
selon le schéma exact de Stripe (HMAC-SHA256 de `timestamp.payload`). Une suite
de tests de contrat confronte la signature produite au vérificateur du SDK
Stripe, ce qui garantit que le rejeu local n'est pas une simulation complaisante.

## 1. Mode hors ligne

### Préparation, une seule fois

```bash
make webhook-secret   # génère un secret local et l'écrit dans .env
make setup            # le pose dans wp-config.php (constante RCP_SEPA_WEBHOOK_SECRET_TEST)
```

Ce secret n'a de valeur qu'en local : il ne correspond à aucun point de
terminaison déclaré chez Stripe.

### Rejouer un événement

```bash
make webhook-list
make webhook-send FIXTURE=payment-intent-succeeded
```

La commande affiche l'URL visée, le type d'événement, le code HTTP et le corps
de la réponse, et sort en erreur si le code n'est pas 2xx — elle est donc
utilisable directement dans un script.

### Éprouver les rejets

```bash
make webhook-attack
```

Enchaîne trois envois qui doivent tous produire un `400` une fois le point de
terminaison livré :

| Cas | Option | Exigence |
|---|---|---|
| Signature calculée avec un mauvais secret | `--bad-signature` | SEC-06 |
| Aucun en-tête `Stripe-Signature` | `--no-signature` | SEC-06 |
| Signature antidatée de 10 minutes | `--age=600` | SEC-07 |

Ces trois cas sont également couverts par la suite `webhooks`, livrée au
jalon J4 ; la commande sert au diagnostic manuel.

### Viser une autre URL

```bash
WEBHOOK_ENDPOINT=http://localhost:18080/wp-json/rcp-stripe-sepa/v1/webhook \
  php bin/webhook.php send synthetic-payment-intent-processing
```

## 2. Mode en ligne

### Relayer de vrais événements

```bash
make stripe-listen
```

La CLI Stripe ouvre un tunnel et affiche un secret `whsec_…` propre à la
session. Reportez-le dans `.env` (`STRIPE_WEBHOOK_SECRET`) puis `make setup`.
Tant que ce processus tourne, les événements du compte de test sont relayés vers
`http://wordpress/wp-json/rcp-stripe-sepa/v1/webhook`.

### Déclencher un événement

```bash
make stripe-trigger EVENT=payment_intent.succeeded
```

### Capturer une fixture

```bash
make webhook-events ARGS="--type=payment_intent.succeeded --limit=10"
make webhook-capture EVENT_ID=evt_xxx NAME=payment-intent-succeeded
```

Une fixture capturée porte la forme exacte produite par Stripe et doit remplacer
son équivalent synthétique. Voir `tests/fixtures/webhooks/README.md`.

## 3. Garde-fous

- **Mode test uniquement.** Toute clé `sk_live_` ou `rk_live_` fait échouer
  l'outil, et tout événement `livemode: true` est refusé au rejeu comme à la
  capture. Un test de contrat vérifie qu'aucune fixture du dépôt n'est en mode
  production.
- **Aucun secret affiché.** Les valeurs sensibles sont masquées à l'affichage.
- **Cohérence avec le plugin.** Le rejeu emprunte le même chemin HTTP que Stripe
  (même URL, même en-tête, même corps brut) : la vérification de signature, le
  contrôle `livemode` et l'idempotence s'exercent tels qu'en production.

## 4. Version d'API des charges utiles

Le champ `api_version` d'un événement est fixé par **le point de terminaison
Stripe ou le compte**, et non par la version que le plugin transmet dans ses
propres requêtes (`RCP_SEPA_STRIPE_API_VERSION`, voir cahier des charges §3.3).

Les gestionnaires doivent donc lire défensivement, sans supposer une version —
en particulier pour la charge associée à un PaymentIntent, exposée selon les
versions comme `latest_charge` ou comme `charges.data[0]`.
