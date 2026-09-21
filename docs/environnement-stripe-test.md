# Configurer l'environnement Stripe de test

## 1. Ce qui est réellement nécessaire

| | Mode test (développement) | Mode production |
|---|---|---|
| Compte Stripe activé (`charges_enabled`) | **Non** | Oui |
| Capacité `sepa_debit_payments` active | **Non** | Oui |
| Identifiant Créancier SEPA (ICS) | **Non** | Oui |
| Point de terminaison webhook déclaré chez Stripe | **Non** | Oui |
| Clés `sk_test_` / `pk_test_` | Oui | — |
| Devise EUR | Oui | Oui |

**Le point contre-intuitif :** un compte dont toutes les capacités sont
`inactive` permet malgré tout de créer et de confirmer des PaymentIntents SEPA
en mode test. Les capacités reflètent l'activation **en production** ; elles ne
bloquent pas le développement. C'est pourquoi `make stripe-doctor` ne se fie pas
au champ `capabilities` mais crée réellement un PaymentIntent SEPA, puis
l'annule.

De même, aucun point de terminaison webhook n'a besoin d'être déclaré chez
Stripe pour développer : les événements sont rejoués localement, signés avec un
secret local (voir `docs/webhooks-en-local.md`).

## 2. Mise en route

```bash
# .env : STRIPE_TEST_SECRET_KEY et STRIPE_TEST_PUBLISHABLE_KEY
make webhook-secret     # secret de webhook local
make up                 # pile Docker + provisionnement
make stripe-doctor      # vérification de bout en bout
```

`stripe-doctor` contrôle la clé (et refuse toute clé de production), la devise,
le pays, la capacité effective à créer un PaymentIntent SEPA, la présence du
secret de webhook et la joignabilité du site.

## 3. Alimenter le compte de test

```bash
make stripe-seed SCENARIO=success                      # paiement unique
make stripe-seed SCENARIO=success ARGS=--subscription  # abonnement récurrent
make stripe-seed SCENARIO=failed  ARGS=--subscription  # renouvellement en échec
```

| Scénario | IBAN de test | Comportement |
|---|---|---|
| `success` | `FR1420041010050500013M02606` | `processing` puis `succeeded` |
| `failed` | `FR8420041010050500013M02607` | `processing` puis `requires_payment_method` |
| `disputed` | `FR5720041010050500013M02608` | `succeeded` puis litige immédiat |
| `insufficient` | `FR9720041010050000002222227` | échec pour fonds insuffisants |

Les objets Stripe de test portent la métadonnée `rcp_stripe_sepa_seed`.
`make stripe-clean` annule les PaymentIntents restés inachevés ; pour repartir
de zéro, utilisez « Delete all test data » dans le Dashboard.

## 4. Enseignements du compte de test

Constats vérifiés sur le compte, qui orientent l'implémentation.

### 4.1 Les charges SEPA portent un identifiant `py_`, pas `ch_`

`payment_intent.latest_charge` vaut par exemple `py_3UI9c1...`. Tout code
supposant le préfixe `ch_` pour reconnaître une transaction se tromperait.

### 4.2 Les webhooks arrivent dans la version d'API du compte

Les charges utiles reçues portent `api_version: 2020-08-27` — la version par
défaut du compte, **pas** celle que le plugin transmet dans ses requêtes
(`RCP_SEPA_STRIPE_API_VERSION`). Elles contiennent donc à la fois
`latest_charge` et `charges`. Les gestionnaires doivent lire défensivement et
accepter les deux formes.

### 4.3 Un abonnement SEPA naît en `requires_confirmation`

Contrairement à la carte, la première facture d'un abonnement SEPA n'est pas
réglée automatiquement : le mandat doit être accepté explicitement, côté
navigateur par `stripe.confirmSepaDebitPayment()`. Sans cette étape,
l'abonnement reste `incomplete`.

### 4.4 `invoice.payment_failed` est émis dès la création de l'abonnement

Avant même toute tentative de prélèvement, Stripe émet un
`invoice.payment_failed` parce que la facture n'a pas pu être réglée sans
confirmation. **Ce n'est pas un échec de paiement.** Le gestionnaire de cet
événement doit vérifier l'état réel du PaymentIntent avant de conclure à un
impayé, sous peine de résilier des adhésions valides à l'inscription.

### 4.5 L'abonnement passe `active` pendant que le prélèvement est `processing`

Constat central : après confirmation du mandat, l'abonnement est `active` alors
que le PaymentIntent reste `processing` pendant plusieurs jours. Le statut
d'abonnement ne peut donc **jamais** suffire à ouvrir l'accès au contenu — il
faut attendre `invoice.paid` ou `payment_intent.succeeded`.

C'est la justification empirique de la règle RG-01 du cahier des charges et de
la politique d'accès `strict` par défaut.

## 5. Plus tard : la production

Avant toute mise en production, il faudra, dans le Dashboard :

1. compléter l'activation du compte (`charges_enabled`) ;
2. activer le prélèvement SEPA dans **Réglages → Moyens de paiement** et obtenir
   l'ICS ;
3. vérifier que la pré-notification du débiteur est activée ;
4. déclarer un point de terminaison webhook de production et reporter son
   secret.

La liste complète figure en annexe C du cahier des charges.
