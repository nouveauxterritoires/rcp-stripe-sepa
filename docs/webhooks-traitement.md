# Traitement des webhooks

## 1. Chemin d'une requête

```
POST /wp-json/rcp-stripe-sepa/v1/webhook
        │
        ├─ limitation de débit ──────────────────── dépassée ──▶ 429
        │
        ├─ vérification de signature (HMAC) ─────── invalide ──▶ 400   (rien n'est enregistré)
        │   • en-tête absent, mauvais secret, charge utile altérée
        │   • horodatage hors tolérance de 300 s
        │
        ├─ contrôle du mode (livemode) ─────────── incohérent ──▶ 202   (rien n'est enregistré)
        │
        ├─ réservation dans le journal d'événements
        │   • déjà traité ──────────────────────────────────────▶ 200
        │   • trop de tentatives ───────────────────────────────▶ 200 + abandon
        │
        ├─ résolution de l'adhésion
        │   • aucune correspondance ──────────────────────────▶ 200 « ignoré »
        │
        ├─ machine à états ──▶ transition ou « ignoré »
        │
        ├─ application : statut d'adhésion, statut de paiement, note
        │
        └─ 200   (erreur non rattrapée ──▶ 500, Stripe rejouera)
```

## 2. Pourquoi un point de terminaison distinct de celui de RCP

RCP expose `?listener=stripe`, qui **ne vérifie pas** l'en-tête
`Stripe-Signature` : il relit l'événement via l'API à partir de son
identifiant. Le plugin authentifie lui-même, par HMAC, sur la charge utile
brute. Un événement forgé ou rejoué hors fenêtre est rejeté avant toute
lecture métier.

## 3. Codes de réponse

| Code | Situation | Conséquence côté Stripe |
|---|---|---|
| `200` | Traité, ignoré, doublon ou abandonné | Livraison acquittée, aucun rejeu |
| `202` | Mode incohérent (`livemode`) | Acquitté, aucun rejeu |
| `400` | Signature absente, invalide ou hors tolérance | Rejeu selon la politique de Stripe |
| `429` | Limite de débit atteinte | Rejeu ultérieur |
| `500` | Erreur non rattrapée pendant le traitement | Rejeu ultérieur |

Les corps de réponse sont minimaux (`{"received":true|false}`) : un appelant
non authentifié ne doit rien apprendre de l'état du site. Le détail part dans
le journal de RCP, expurgé de tout secret et de tout IBAN.

## 4. Idempotence

Le verrou est l'unicité de `event_id` dans
`{prefix}rcp_sepa_webhook_events`. La réservation utilise `INSERT IGNORE` :
deux requêtes concurrentes portant le même événement ne peuvent pas le
réclamer toutes les deux.

| Issue de la réservation | Condition | Suite |
|---|---|---|
| `granted` | Première réception | Traitement |
| `duplicate` | Déjà `processed` ou `skipped` | 200 immédiat |
| `retry` | `received` ou `failed` | Nouvelle tentative, compteur incrémenté |

Au-delà de cinq tentatives, l'événement passe `failed`, l'action
`rcp_stripe_sepa_webhook_abandoned` se déclenche et le point de terminaison
répond 200 pour interrompre le cycle de rejeu.

Seule l'**empreinte SHA-256** de la charge utile est conservée : les
événements contiennent des données personnelles, et le journal doit pouvoir
être consulté sans les exposer. Les lignes `processed` et `skipped` de plus de
90 jours sont purgées quotidiennement ; les lignes `failed` sont conservées,
car elles restent à diagnostiquer.

## 5. Machine à états

| Événement | Adhésion | Paiement |
|---|---|---|
| `payment_intent.processing` | `pending` (ou `active` en politique optimiste) — jamais de retour en arrière depuis `active` | `pending` |
| `payment_intent.succeeded`, `invoice.paid` | `active` | `complete` |
| `payment_intent.payment_failed` | `expired` au premier paiement, inchangée sur renouvellement | `failed` |
| `invoice.payment_failed` | voir §6 | voir §6 |
| `setup_intent.succeeded` | inchangée | inchangé |
| `setup_intent.setup_failed` | `expired` | `failed` |
| `charge.dispute.created` | `expired`, ou inchangée si la politique est « notifier » | inchangé |
| `charge.refunded` | `expired` si remboursement total | `refunded` |
| `customer.subscription.deleted` | `cancelled` | inchangé |
| tout autre | ignoré | ignoré |

**Anti-régression.** Une adhésion `active` n'est jamais ramenée à `pending` :
les événements arrivent dans le désordre, et un `processing` livré après un
`succeeded` ne doit pas refermer un accès déjà accordé.

**Réactivation.** Un `succeeded` tardif réactive une adhésion résiliée : le
prélèvement a fini par aboutir.

## 6. Le faux échec à la création d'abonnement

À la création d'un abonnement SEPA, Stripe émet `invoice.payment_failed`
**avant toute tentative de prélèvement**, parce que la facture attend la
confirmation du mandat. Le traiter comme un impayé résilierait des adhésions
valides dès l'inscription.

Le discriminateur, vérifié sur des charges utiles réelles :

| | Faux échec | Impayé réel |
|---|---|---|
| `attempt_count` | `0` | `≥ 1` |
| `charge` | `null` | `py_…` |
| `billing_reason` | `subscription_create` | `subscription_create` |

Le `billing_reason` ne distingue donc rien : seuls `attempt_count` et `charge`
font foi. Les deux charges utiles sont conservées en fixtures
(`invoice-payment-failed-at-creation` et `invoice-payment-failed-real`) et
rejouées à chaque exécution de la suite.

## 7. Résolution de l'adhésion

Trois pistes, de la plus fiable à la plus indirecte :

1. métadonnée `rcp_membership_id`, sur l'objet ou sur les lignes de facture ;
2. identifiant d'abonnement Stripe → `gateway_subscription_id` ;
3. identifiant client Stripe → `gateway_customer_id`.

Un événement sans adhésion correspondante est **acquitté**, pas rejeté : le
compte Stripe peut servir à d'autres usages, et répondre en erreur le ferait
rejouer indéfiniment.

## 8. Configuration du secret

Une constante de `wp-config.php` prévaut sur l'option en base :

```php
define( 'RCP_SEPA_WEBHOOK_SECRET_TEST', 'whsec_…' );
define( 'RCP_SEPA_WEBHOOK_SECRET_LIVE', 'whsec_…' );
```

Un secret hors base ne fuite ni dans un export, ni dans une sauvegarde
partagée, ni dans l'interface d'administration. Les deux modes ont des secrets
distincts, et le mode courant est celui de RCP.

## 9. Points d'extension

| Hook | Type | Usage |
|---|---|---|
| `rcp_stripe_sepa_webhook_rate_limit` | filtre | Ajuster ou désactiver la limitation de débit |
| `rcp_stripe_sepa_webhook_processed` | action | Réagir à un événement traité |
| `rcp_stripe_sepa_webhook_abandoned` | action | Alerter sur un événement abandonné |
| `rcp_stripe_sepa_transition_applied` | action | Réagir à un changement de statut |

## 10. Éprouver le point de terminaison

```bash
make webhook-send FIXTURE=payment-intent-succeeded   # 200
make webhook-send FIXTURE=payment-intent-succeeded   # 200, non retraité
make webhook-attack                                  # 400, 400, 400
```

Voir [webhooks-en-local.md](webhooks-en-local.md).
