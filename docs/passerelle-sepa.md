# La passerelle SEPA

## 1. Ce qui est réutilisé de RCP, et ce qui ne l'est pas

`RCP_Stripe_Sepa\Gateway\Gateway` hérite de `RCP_Payment_Gateway_Stripe`.
L'héritage apporte la gestion des clés, la création des clients Stripe, la
fabrication des plans, l'idempotence et le journal. Quatre méthodes sont
surchargées, et chacune pour une raison précise.

| Méthode | Pourquoi elle est surchargée |
|---|---|
| `init()` | Remplace les capacités déclarées : `card-updates` afficherait à l'adhérent un formulaire de mise à jour de carte, sans objet pour un mandat |
| `process_ajax_signup()` | RCP n'expose aucun filtre sur les arguments de SetupIntent ; l'intention doit par ailleurs être restreinte à `sepa_debit` |
| `process_signup()` | Voir §2 : deux incompatibilités de fond |
| `fields()`, `scripts()` | Le formulaire et le script de RCP sont câblés sur le Card Element et sur `confirmCardPayment` |

## 2. Pourquoi `process_signup()` ne peut pas être réutilisée

Deux raisons, l'une de correction, l'autre de sûreté.

**La passerelle carte conclut trop tôt.** Elle marque le paiement `complete`
dès que la charge est `succeeded`, ce qui active l'adhésion. Un prélèvement
SEPA n'est jamais `succeeded` au moment de l'inscription : il reste
`processing` pendant deux à quatorze jours ouvrés.

**Sa déduplication des moyens de paiement lit `card->fingerprint`.** Cette
propriété n'existe pas sur un moyen de paiement SEPA : l'appel émet un
avertissement PHP à chaque inscription sur un site où l'adhérent possède déjà
une carte enregistrée.

## 3. Le parcours

```
navigateur                          serveur                         Stripe
    │
    │  sélection « Prélèvement SEPA »
    ├───────────────────────────────▶ fields()
    │  ◀─ formulaire + Stripe Element (iframe Stripe, l'IBAN ne transite pas ici)
    │
    │  soumission
    ├───────────────────────────────▶ process_ajax_signup()
    │                                      ├─ client Stripe (hérité de RCP)
    │                                      └─ PaymentIntent ou SetupIntent ──▶
    │  ◀─ client_secret                                                    ◀──
    │
    ├─ confirmSepaDebitPayment() ───────────────────────────────────────────▶
    │     acceptation du mandat par le débiteur — vaut signature
    │  ◀────────────────────────────────────────────────────────────────────
    │
    ├───────────────────────────────▶ process_signup()
    │                                      ├─ rattache le moyen de paiement
    │                                      ├─ persiste le mandat + preuve
    │                                      ├─ paiement → en attente
    │                                      ├─ adhésion → reste en attente
    │                                      └─ abonnement, échéance différée
    │  ◀─ page de confirmation
    │
    ⋮  deux à quatorze jours ouvrés
    │
    │                                 webhook ◀── payment_intent.succeeded ──
    │                                      └─ adhésion → active
```

## 4. Quelle intention pour quelle adhésion

| Situation | Intention | `setup_future_usage` |
|---|---|---|
| Adhésion reconductible avec premier versement | PaymentIntent | `off_session` |
| Adhésion à vie, paiement unique | PaymentIntent | *absent* |
| Adhésion gratuite ou remise de 100 % | SetupIntent | `usage: off_session` |

Un paiement unique ne laisse pas de mandat réutilisable : le débiteur n'a
autorisé qu'un seul prélèvement.

## 5. L'abonnement démarre plus tard

Le premier versement est encaissé comme paiement ponctuel, et l'abonnement ne
facture qu'à l'expiration de la période ainsi réglée. Ce décalage n'est pas un
détail d'implémentation : il évite qu'un abonnement SEPA naisse en
`requires_confirmation`, faute de mandat confirmé pour sa facture initiale —
comportement vérifié sur un compte réel et documenté en
[environnement-stripe-test.md §4.3](environnement-stripe-test.md).

Le report passe par `billing_cycle_anchor`, qui préserve le calcul du revenu
récurrent chez Stripe, ou par `trial_end` lorsque l'échéance dépasse un cycle de
facturation — Stripe refusant alors l'ancrage. Les deux ne sont jamais combinés.

## 6. Écarts assumés avec la passerelle carte

| Point | Passerelle carte de RCP | Passerelle SEPA |
|---|---|---|
| Paramètre d'abonnement | `plan` | `items[].price` — `plan` est déprécié et refusé par la spécification actuelle |
| Déduplication des moyens de paiement | Par empreinte de carte | Aucune : deux mandats sur le même IBAN restent deux autorisations distinctes |
| Propriétés dynamiques | Créées par la classe abstraite | Déclarées, PHP 8.2 dépréciant leur création |
| Version d'API | `2020-08-27`, imposée globalement | Transmise par requête, sans toucher au réglage global |
| Activation de l'adhésion | À l'inscription | Sur webhook d'encaissement |

## 7. Le mandat

Ce qui est conservé, en métadonnées d'adhésion préfixées `rcp_sepa_` :
identifiants opaques du moyen de paiement et du mandat, référence (RUM), URL du
mandat, statut, quatre derniers caractères de l'IBAN, pays, codes banque et
guichet, nom du titulaire, date et adresse d'acceptation.

Ce qui ne l'est jamais : l'IBAN complet, qui n'atteint pas le serveur, et
l'empreinte bancaire, identifiant durable sans utilité locale.

L'adresse d'acceptation est purgée après treize mois — durée de la fenêtre de
contestation d'un prélèvement non autorisé, au-delà de laquelle cette donnée
personnelle n'a plus de valeur probatoire.

## 8. Points d'extension

| Hook | Type | Usage |
|---|---|---|
| `rcp_stripe_sepa_mandate_text` | filtre | Personnaliser le texte du mandat, en conservant les mentions obligatoires |
| `rcp_stripe_sepa_mandate_saved` | action | Réagir à l'enregistrement d'un mandat |
| `rcp_stripe_sepa_signup` | action | Réagir à une inscription — l'adhésion est encore en attente |
| `rcp_stripe_create_subscription_args` | filtre | Filtre de RCP, appliqué aussi aux abonnements SEPA |
