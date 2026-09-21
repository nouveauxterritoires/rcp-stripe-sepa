# ADR-0001 — Étendre la passerelle Stripe de RCP par héritage

- **Statut** : Accepté
- **Date** : 2026-09-21
- **Décideurs** : Mathieu (porteur du projet)

## Contexte

RCP embarque une passerelle Stripe (`RCP_Payment_Gateway_Stripe`) limitée à la carte bancaire. Il
faut ajouter le prélèvement SEPA sans forker RCP ni empêcher ses mises à jour.

Trois options ont été considérées :

1. **Filtres uniquement** — utiliser `rcp_stripe_create_payment_intent_args` et
   `rcp_stripe_create_subscription_args` pour transformer la passerelle carte en passerelle SEPA.
2. **Passerelle autonome** — hériter directement de `RCP_Payment_Gateway` et réécrire toute
   l'intégration Stripe.
3. **Héritage de la passerelle Stripe** — `Gateway extends RCP_Payment_Gateway_Stripe`.

## Décision

Option 3 : héritage de `RCP_Payment_Gateway_Stripe`, sous l'identifiant de passerelle `stripe_sepa`.

## Justification

L'option 1 est impraticable :

- aucun filtre n'existe sur les arguments de **SetupIntent** (`class-rcp-payment-gateway-stripe.php`,
  branche `else` de `process_ajax_signup()`) ; or le SetupIntent est requis pour les inscriptions
  sans débit immédiat et pour la migration carte → SEPA ;
- le formulaire (`fields()`) et le JavaScript (`stripe/js/register.js`) sont câblés en dur sur le Card
  Element et sur `confirmCardPayment` / `confirmCardSetup` ;
- `process_signup()` n'active l'adhésion que si la charge est `succeeded`, ce qui n'arrive jamais
  avec SEPA au moment de l'inscription ;
- transformer la passerelle carte reviendrait à casser le paiement par carte sur le même site.

L'option 2 impose de réécrire la gestion des clés, des clients Stripe, des plans, des métadonnées,
de l'idempotence et du cycle de vie des adhésions — soit une duplication importante d'un code déjà
éprouvé, et une dette de maintenance permanente.

L'option 3 permet de réutiliser tout ce qui est indépendant du moyen de paiement et de ne surcharger
que les points réellement spécifiques à SEPA : construction des intentions, formulaire, script de
confirmation, activation différée et webhooks.

## Conséquences

**Positives**
- Les deux passerelles (carte et SEPA) coexistent sur le même site, avec les mêmes clés API.
- Réutilisation de `get_or_create_customer()`, `maybe_create_plan()`, `rcp_stripe_generate_idempotency_key()`,
  du journal `rcp_log()` et des réglages de clés.
- Surface de code réduite, donc surface d'audit de sécurité réduite.

**Négatives et mitigations**
- *Couplage aux internes de RCP* → suite de tests de contrat vérifiant l'existence et la signature de
  chaque méthode et propriété utilisée, exécutée quotidiennement contre la dernière version de RCP.
- *Version d'API Stripe globale figée par RCP à `2020-08-27`* → le plugin ne modifie jamais la version
  globale et passe `stripe_version` dans les options de chaque requête (règles R-API-1 à R-API-3 du
  cahier des charges).
- *SDK Stripe partagé (`\Stripe\`, version 10.3.0)* → garde-fou de version au chargement ; option
  d'évolution documentée (SDK propre préfixé via PHP-Scoper / Strauss) si la contrainte devient
  bloquante.

## Révision

Cette décision est réévaluée au jalon J2 (spike de validation), après vérification empirique de la
coexistence des deux passerelles et du passage de `stripe_version` par requête.
