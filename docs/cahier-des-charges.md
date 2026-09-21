# Cahier des charges — `rcp-stripe-sepa`

**Plugin WordPress : prélèvement SEPA (Stripe) pour Restrict Content Pro**

| | |
|---|---|
| Version du document | 1.0 |
| Date | 21 septembre 2026 |
| Statut | Pour validation |
| Nom de code | `rcp-stripe-sepa` |
| Licence cible | GPL-2.0-or-later (imposée par WordPress / RCP) |

---

## 1. Contexte et objectifs

### 1.1 Contexte

Restrict Content Pro (RCP, aujourd'hui maintenu par StellarWP / Kadence Memberships) fournit une
passerelle Stripe native (`RCP_Payment_Gateway_Stripe`) qui gère **exclusivement le paiement par
carte bancaire**. Elle s'appuie sur les PaymentIntents et SetupIntents, mais son formulaire, son
JavaScript et sa logique d'activation d'adhésion supposent un moyen de paiement à **confirmation
synchrone** — ce qui est le cas de la carte, et ne l'est pas du prélèvement SEPA.

Le marché européen (et français en particulier) réclame le prélèvement SEPA pour les abonnements :
coût de transaction très inférieur à la carte, pas d'expiration de moyen de paiement, taux d'échec
de renouvellement plus faible, et attente forte des adhérents pour les cotisations annuelles.

### 1.2 Objectif

Développer un plugin WordPress **additionnel** (extension de RCP, pas un fork) qui ajoute le
prélèvement SEPA Stripe comme moyen de paiement disponible dans RCP, pour :

- les **adhésions récurrentes** (mandat SEPA + abonnement Stripe Billing) ;
- les **adhésions à vie / paiements uniques** (PaymentIntent SEPA ponctuel) ;
- la **migration d'un moyen de paiement existant** (carte → SEPA) pour un membre déjà actif.

### 1.3 Principes directeurs

1. **S'appuyer sur RCP au maximum.** Réutiliser l'infrastructure existante (clés API, client Stripe,
   objets `Customer`, plans Stripe, cycle de vie des adhésions, enregistrement des paiements,
   journalisation `rcp_log()`). Ne réécrire que ce que le comportement asynchrone de SEPA impose.
2. **Aucune modification du code de RCP.** Extension par héritage et par hooks uniquement, afin que
   les mises à jour de RCP restent applicables.
3. **Sécurité par défaut.** Voir le chapitre 9 : c'est l'exigence prioritaire du projet.
4. **Non-régression prouvée.** Toute évolution est couverte par une suite de tests automatisés
   exécutable en une commande, sur un environnement Docker reproductible.

### 1.4 Objectifs mesurables

| Objectif | Indicateur | Cible |
|---|---|---|
| Couverture de tests | Lignes couvertes (PHPUnit) sur le code du plugin | ≥ 80 % |
| Conformité standards | Violations `WordPress-Extra` + `WordPress-Docs` (PHPCS) | 0 erreur, 0 warning |
| Analyse statique | PHPStan / WPCS niveau 5 minimum | 0 erreur |
| Robustesse webhook | Événements Stripe rejoués sans double-facturation | 100 % (idempotence) |
| Compatibilité | Matrice PHP × WP × RCP (§3.2) | Toute la matrice au vert en CI |

---

## 2. Périmètre

### 2.1 Dans le périmètre (v1.0)

| Réf. | Fonctionnalité |
|---|---|
| F-01 | Nouvelle passerelle de paiement RCP `stripe_sepa`, activable dans les réglages RCP |
| F-02 | Inscription à une adhésion **récurrente** payée par prélèvement SEPA (mandat + abonnement Stripe) |
| F-03 | Inscription à une adhésion **à vie / paiement unique** par PaymentIntent SEPA |
| F-04 | Gestion de l'état intermédiaire « paiement en cours de traitement » propre à SEPA |
| F-05 | Migration du moyen de paiement d'une adhésion existante : carte → SEPA (et SEPA → SEPA) |
| F-06 | Traitement des webhooks Stripe dédiés, avec vérification de signature et idempotence |
| F-07 | Gestion des échecs et des impayés (R-transactions), rejets, litiges |
| F-08 | Affichage du mandat (référence, ICS créancier, IBAN masqué) côté membre et côté administration |
| F-09 | Mode bac à sable (test) et mode production, strictement cloisonnés |
| F-10 | E-mails transactionnels spécifiques SEPA (mandat accepté, prélèvement en cours, rejet) |
| F-11 | Internationalisation complète (FR / EN fournis) |
| F-12 | Exportateur / effaceur de données personnelles (RGPD) |
| F-13 | Écran d'état et de diagnostic (santé de la configuration, derniers webhooks reçus) |
| F-14 | Environnement Docker de développement et de test, et suite de tests automatisés |
| F-15 | Documentation développeur et documentation administrateur |

### 2.2 Hors périmètre (v1.0)

- Autres moyens de paiement à débit direct (BACS, BECS AU, ACH US) — l'architecture les prévoit mais
  ils ne sont pas implémentés.
- Stripe Checkout hébergé et Stripe Connect (comptes connectés) : hors v1, à documenter comme limite.
- Migration SEPA → carte (le parcours carte natif de RCP reste utilisé tel quel).
- Reprise de mandats SEPA existants émis hors Stripe (import de mandats).
- Passage à un plugin distribué sur WordPress.org (le projet reste privé/interne en v1).

### 2.3 Hypothèses et dépendances

- Un compte Stripe avec le prélèvement SEPA activé (capacité `sepa_debit_payments`), doté d'un
  Identifiant Créancier SEPA (ICS) validé.
- Devise **EUR** exclusivement : le prélèvement SEPA Stripe ne supporte pas d'autre devise.
- RCP (version Pro ou le socle libre « Restrict Content ») installé et actif, passerelle Stripe
  configurée avec des clés API valides.
- Le site est servi en **HTTPS** (obligatoire : Stripe.js et la collecte de mandat l'exigent).

---

## 3. Contraintes techniques

### 3.1 Versions de référence (constat au 21/09/2026)

| Composant | Version constatée | Remarque |
|---|---|---|
| Restrict Content / RCP (socle StellarWP) | `4.0.4`, affiché « Kadence Memberships » | Dépôt `stellarwp/restrict-content`. Le noyau embarqué déclare `RCP_PLUGIN_VERSION = 4.0.7` : les numéros de version des deux variantes ne sont pas comparables (§4.1) |
| SDK Stripe PHP embarqué dans RCP | `10.3.0` | `core/includes/libraries/stripe/` |
| Version d'API par défaut du SDK embarqué | `2022-11-15` | `\Stripe\Util\ApiVersion::CURRENT` |
| Version d'API **forcée globalement** par RCP | `2020-08-27` | `\Stripe\Stripe::setApiVersion()` dans `init()` |
| Dernière version d'API Stripe publiée | `2026-08-26.dahlia` | Modèle de versionnement « Dahlia » |

### 3.2 Matrice de compatibilité à couvrir en CI

| Axe | Valeurs |
|---|---|
| PHP | 7.4, 8.0, 8.1, 8.2, 8.3 |
| WordPress | dernière version stable, version stable − 1, `trunk` (nightly, non bloquant) |
| RCP / Restrict Content | dernière version stable, version stable − 1 |
| MySQL | 8.0 |

### 3.3 Contrainte majeure n°1 — la version d'API Stripe est globale

`RCP_Payment_Gateway_Stripe::init()` exécute `\Stripe\Stripe::setApiVersion( '2020-08-27' )`, un
réglage **statique et global** au processus PHP. Le modifier casserait la passerelle carte de RCP
(qui lit notamment `$payment_intent->charges`, champ supprimé depuis l'API `2022-11-15`).

**Règle d'implémentation (R-API-1).** Le plugin ne doit **jamais** appeler `setApiVersion()`. Chaque
appel à l'API Stripe passe une version explicite dans le tableau d'options de la requête :

```php
$options = [
    'stripe_version'  => RCP_SEPA_STRIPE_API_VERSION, // ex. '2022-11-15'
    'idempotency_key' => $idempotency_key,
];
$intent = \Stripe\PaymentIntent::create( $args, $options );
```

**Règle d'implémentation (R-API-2).** `RCP_SEPA_STRIPE_API_VERSION` est figée à une version
**compatible avec le SDK embarqué** (`2022-11-15`), surchargeable par constante `wp-config.php`. Le
code du plugin utilise donc `$payment_intent->latest_charge` (et non `->charges`) et
`latest_invoice.payment_intent.client_secret` (et non le champ `confirmation_secret` introduit dans
les versions Basil et ultérieures).

**Règle d'implémentation (R-API-3).** Au chargement, le plugin vérifie que `\Stripe\Stripe::VERSION`
est ≥ `10.0.0`. En deçà, la passerelle ne s'enregistre pas et un avis d'administration explique la
cause. Une évolution ultérieure (§16.3) pourra embarquer un SDK propre, préfixé via PHP-Scoper /
Strauss, pour s'affranchir de cette dépendance.

### 3.4 Contrainte majeure n°2 — SEPA est asynchrone

| Propriété | Carte | Prélèvement SEPA |
|---|---|---|
| Confirmation du paiement | Immédiate (secondes) | Différée : `processing` → `succeeded` en **2 à 14 jours ouvrés** |
| Statut initial du PaymentIntent | `succeeded` | `processing` |
| Rejet possible après succès | Rare | Oui (R-transactions, fonds insuffisants, compte clos) |
| Fenêtre de contestation | 120 jours (réseaux cartes) | **8 semaines** (prélèvement autorisé), **13 mois** (non autorisé) |
| Devise | Multiple | **EUR uniquement** |
| Pré-notification du débiteur | — | Obligatoire (assurée par Stripe si activée dans le Dashboard) |

**Conséquence structurante.** La méthode `process_signup()` de RCP marque le paiement `complete` (et
donc active l'adhésion) uniquement si la charge est `succeeded`. Avec SEPA, ce ne sera jamais le cas
au moment de l'inscription. Le plugin doit donc introduire un **état intermédiaire explicite** et
piloter l'activation par webhook (§8).

### 3.5 Contrainte majeure n°3 — le formulaire et le JS de RCP sont spécifiques carte

- `RCP_Payment_Gateway_Stripe::fields()` rend un élément `#rcp-card-element` (Stripe Card Element) et
  une liste de moyens de paiement enregistrés qui n'affiche de libellé que pour `type === 'card'`.
- `core/includes/gateways/stripe/js/register.js` appelle en dur
  `confirmCardPayment` / `confirmCardSetup` selon `stripe_intent_type`.

Le plugin fournit donc son **propre** rendu de champs (Payment Element ou IBAN Element) et son propre
script de confirmation (`confirmSepaDebitPayment` / `confirmSepaDebitSetup`).

### 3.6 Contrainte majeure n°4 — extensibilité incomplète côté RCP

| Point d'extension | Disponible ? | Conséquence |
|---|---|---|
| `rcp_payment_gateways` (filtre d'enregistrement) | Oui | Permet d'ajouter la passerelle sans patcher RCP |
| `rcp_stripe_create_payment_intent_args` | Oui | Permet d'injecter `payment_method_types` sur un PaymentIntent |
| Arguments de **SetupIntent** | **Non** | Aucun filtre : il faut surcharger `process_ajax_signup()` |
| `rcp_stripe_create_subscription_args` | Oui | Permet d'ajuster `default_payment_method`, `payment_settings` |
| `rcp_stripe_customer_create_args` | Oui | — |
| Vérification de signature des webhooks | **Non** | RCP ne vérifie pas l'en-tête `Stripe-Signature` : il relit l'événement via l'API. Le plugin implémente sa propre vérification (§9.3) |

**Conclusion architecturale :** la surcharge par héritage (§5.1) est nécessaire ; les filtres seuls
ne suffisent pas.

### 3.7 Contrainte majeure n°5 — deux variantes du produit

Deux plugins distincts exposent le même noyau : la variante libre publiée sur WordPress.org et la
variante commerciale. Le plugin doit fonctionner avec les deux sans branche conditionnelle.

**Règle d'implémentation (R-VAR-1).** Le démarrage n'est jamais décidé sur un nom de plugin ni sur
un numéro de version, mais sur la **présence effective des capacités utilisées**.

**Règle d'implémentation (R-VAR-2).** La variante libre peut tourner en « mode legacy »
(option `restrict_content_chosen_version`), sans aucun noyau RCP ni passerelle de paiement. Ce cas
doit produire un message dédié, et non une énumération de classes absentes.

**Règle d'implémentation (R-VAR-3).** RCP ne charge son SDK Stripe qu'au premier appel de
`RCP_Payment_Gateway_Stripe::init()`. La présence du **fichier** suffit au démarrage ; la version
est contrôlée à l'initialisation de la passerelle.

Le détail de la stratégie et les points restant à vérifier sur la variante commerciale sont
consignés dans `docs/compatibilite-rcp.md`.

---

## 4. Analyse de l'existant RCP (synthèse du code lu)

| Élément | Emplacement | Observation exploitée |
|---|---|---|
| Registre des passerelles | `core/includes/gateways/class-rcp-payment-gateways.php:74` | `apply_filters( 'rcp_payment_gateways', $gateways )` |
| Classe abstraite | `core/includes/gateways/class-rcp-payment-gateway.php` | Méthodes à surcharger : `init()`, `process_ajax_signup()`, `process_signup()`, `process_webhooks()`, `fields()`, `scripts()`, `validate_fields()`, `update_card_fields()` |
| Déclaration de capacités | `class-rcp-payment-gateway-stripe.php:33-45` | Tableau `$this->supports[]` : `one-time`, `recurring`, `fees`, `gateway-submits-form`, `trial`, `price-changes`, `renewal-date-changes`, `subscription-creation`, `ajax-payment`, `card-updates`, `off-site-subscription-creation`, `expiration-extension-on-renewals` |
| Clés API | `class-rcp-payment-gateway-stripe.php:46-57` | Options `stripe_test_secret` / `stripe_test_publishable` / `stripe_live_secret` / `stripe_live_publishable`, sélectionnées par `$this->test_mode` |
| Création d'intention | `class-rcp-payment-gateway-stripe.php:77-233` | PaymentIntent si `initial_amount > 0`, sinon SetupIntent ; `client_secret` et `stripe_intent_type` renvoyés en AJAX ; ID stocké en meta de paiement `stripe_payment_intent_id` |
| Idempotence | `rcp_stripe_generate_idempotency_key()` | À réutiliser tel quel |
| Création d'abonnement | `class-rcp-payment-gateway-stripe.php:526-605` | `default_payment_method`, `plan`, `billing_cycle_anchor` ou `trial_end`, métadonnées `rcp_membership_id` etc. |
| Gestion des plans | `maybe_create_plan()`, `create_plan()`, `plan_exists()` | Réutilisables sans modification |
| Webhooks | `class-rcp-payment-gateway-stripe.php:880+` | Point d'entrée `?listener=stripe` ; **pas de vérification de signature** ; l'événement est relu via `\Stripe\Event::retrieve()` ; filtrage par une liste blanche `validate_stripe_webhook()` |

---

## 5. Architecture cible

### 5.1 Décision d'architecture

> **ADR-0001 — Étendre `RCP_Payment_Gateway_Stripe` par héritage.**
> La passerelle SEPA est une sous-classe de la passerelle Stripe de RCP. Voir `docs/adr/0001-strategie-integration-rcp.md`.

```
RCP_Payment_Gateway  (abstrait, RCP)
        └── RCP_Payment_Gateway_Stripe  (RCP, carte)
                    └── RCP_Stripe_Sepa\Gateway  (ce plugin, id « stripe_sepa »)
```

**Bénéfices :** réutilisation de la gestion des clés, des clients Stripe, des plans, des
métadonnées, de l'idempotence et du journal.
**Risque assumé :** couplage aux internes de RCP. Mitigation → §17 (tests de contrat sur les
signatures de méthodes et les propriétés utilisées, exécutés en CI contre plusieurs versions de RCP).

### 5.2 Arborescence du plugin

```
rcp-stripe-sepa/
├── rcp-stripe-sepa.php            # En-tête du plugin, bootstrap, garde-fous de compatibilité
├── uninstall.php
├── composer.json
├── src/
│   ├── Plugin.php                 # Conteneur, chargement, hooks
│   ├── Gateway/
│   │   ├── Gateway.php            # RCP_Stripe_Sepa\Gateway extends RCP_Payment_Gateway_Stripe
│   │   ├── IntentFactory.php      # Construction PaymentIntent / SetupIntent SEPA
│   │   ├── SubscriptionFactory.php
│   │   └── Registrar.php          # Filtre rcp_payment_gateways
│   ├── Webhook/
│   │   ├── Endpoint.php           # Route REST dédiée + vérification de signature
│   │   ├── SignatureVerifier.php
│   │   ├── EventStore.php         # Idempotence (table des événements traités)
│   │   └── Handler/               # Un handler par type d'événement
│   ├── Mandate/
│   │   ├── MandateRepository.php  # Persistance des métadonnées de mandat
│   │   └── MandatePresenter.php
│   ├── Membership/
│   │   └── StateMachine.php       # Traduction état Stripe → état RCP
│   ├── Admin/
│   │   ├── Settings.php
│   │   ├── MembershipMetaBox.php
│   │   └── HealthCheck.php        # Site Health + écran de diagnostic
│   ├── Frontend/
│   │   ├── Fields.php             # Rendu du formulaire SEPA + texte de mandat
│   │   └── PaymentMethodUpdate.php# Migration CB → SEPA
│   ├── Email/
│   ├── Privacy/                   # Exportateur / effaceur RGPD
│   ├── Logging/
│   │   └── Redactor.php           # Masquage IBAN / secrets dans les journaux
│   └── Support/
│       ├── StripeClient.php       # Enveloppe : version d'API, options, retries
│       └── Iban.php               # Validation IBAN côté serveur (format + MOD-97)
├── assets/
│   ├── js/src/{register,update-payment-method}.js
│   ├── js/dist/
│   └── css/
├── languages/rcp-stripe-sepa.pot
├── docs/
├── docker/
├── tests/
└── bin/
```

### 5.3 Nommage et conventions

| Élément | Convention | Exemple |
|---|---|---|
| Identifiant de passerelle | `stripe_sepa` | `rcp_payment_gateways['stripe_sepa']` |
| Préfixe des fonctions globales | `rcp_stripe_sepa_` | `rcp_stripe_sepa_get_mandate()` |
| Préfixe des hooks | `rcp_stripe_sepa_` | `rcp_stripe_sepa_mandate_accepted` |
| Namespace PHP | `RCP_Stripe_Sepa\` | `RCP_Stripe_Sepa\Gateway\Gateway` |
| Text domain | `rcp-stripe-sepa` | — |
| Préfixe des options | `rcp_stripe_sepa_` | `rcp_stripe_sepa_webhook_secret_test` |
| Constantes | `RCP_SEPA_` | `RCP_SEPA_STRIPE_API_VERSION` |
| Table personnalisée | `{$wpdb->prefix}rcp_sepa_webhook_events` | — |

---

## 6. Spécifications fonctionnelles

### 6.1 US-01 — Inscription à une adhésion récurrente par prélèvement SEPA

**En tant que** visiteur, **je veux** m'abonner en autorisant un prélèvement SEPA, **afin de** ne pas
utiliser de carte bancaire.

**Parcours nominal**

1. Le visiteur choisit un niveau d'adhésion récurrent et sélectionne « Prélèvement SEPA » parmi les
   moyens de paiement.
2. Le formulaire affiche : IBAN, titulaire du compte, e-mail, et le **texte de mandat** obligatoire
   fourni par Stripe (§10.1).
3. À la soumission, RCP déclenche `process_ajax_signup()` (capacité `ajax-payment`). La passerelle :
   - récupère ou crée le `Customer` Stripe (via `get_or_create_customer()`, hérité) ;
   - crée un **PaymentIntent** si `initial_amount > 0`, sinon un **SetupIntent**, avec
     `payment_method_types: ['sepa_debit']` et `setup_future_usage: 'off_session'` ;
   - stocke l'ID d'intention dans la meta de paiement `stripe_payment_intent_id` ;
   - renvoie `client_secret` + `stripe_intent_type`.
4. Le JS appelle `stripe.confirmSepaDebitPayment()` ou `stripe.confirmSepaDebitSetup()` avec
   `billing_details.name` et `billing_details.email`. Stripe crée le `PaymentMethod` et le `Mandate`.
5. Le formulaire est soumis ; `process_signup()` :
   - relit l'intention, **n'active pas** l'adhésion (le PaymentIntent est `processing`) ;
   - enregistre le paiement RCP au statut `pending` ;
   - place l'adhésion au statut `pending` ;
   - crée l'abonnement Stripe (`\Stripe\Subscription::create`) avec `default_payment_method` =
     le `pm_*` SEPA et `payment_settings.payment_method_types = ['sepa_debit']` ;
   - persiste les métadonnées de mandat (§7.2) ;
   - redirige vers une page de confirmation expliquant le délai de traitement.
6. À réception de `payment_intent.succeeded` ou `invoice.paid` (2 à 14 jours), le webhook passe le
   paiement en `complete` et l'adhésion en `active`, puis déclenche l'e-mail de bienvenue RCP.

**Règles de gestion**

- **RG-01** : l'adhésion n'accorde **aucun accès au contenu** tant que le premier prélèvement n'est
  pas `succeeded`, sauf si l'option « accès optimiste » (§6.6) est activée.
- **RG-02** : si la devise du niveau d'adhésion n'est pas EUR, la passerelle SEPA n'est pas proposée.
- **RG-03** : en cas de remise de 100 %, un SetupIntent est utilisé et l'adhésion est activée dès
  `setup_intent.succeeded` (pas de débit initial à attendre).

**Critères d'acceptation**

- [ ] Avec l'IBAN de test `FR1420041010050500013M02606`, l'adhésion est créée en `pending`, puis
      passe en `active` après réception du webhook `payment_intent.succeeded`.
- [ ] Avec `FR8420041010050500013M02607`, l'adhésion reste inaccessible et le membre reçoit l'e-mail
      d'échec.
- [ ] Aucun accès au contenu protégé pendant la phase `pending` (test E2E).
- [ ] Un rejeu du webhook ne crée pas de second enregistrement de paiement.

### 6.2 US-02 — Adhésion à vie / paiement unique par prélèvement SEPA

**En tant que** visiteur, **je veux** régler une adhésion à vie par prélèvement SEPA.

- Niveau d'adhésion non récurrent (durée illimitée ou `auto_renew` désactivé).
- Un **PaymentIntent** unique est créé : `payment_method_types: ['sepa_debit']`, `confirm: false`,
  `setup_future_usage` **omis** (aucun usage futur — évite de créer un mandat récurrent inutile).
- Aucun objet `Subscription` Stripe n'est créé.
- L'adhésion reste `pending` jusqu'à `payment_intent.succeeded`, puis passe `active` sans date
  d'expiration.
- **RG-04** : si le PaymentIntent échoue (`payment_intent.payment_failed`), le paiement RCP passe
  `failed` et l'adhésion `expired` ; un lien de nouvelle tentative est envoyé au membre.
- **RG-05** : un litige (`charge.dispute.created`) sur un paiement à vie révoque l'adhésion et
  notifie l'administrateur.

**Critères d'acceptation**

- [ ] Le PaymentIntent créé contient bien `payment_method_types: ['sepa_debit']` et aucun
      `setup_future_usage` (test unitaire sur `IntentFactory`).
- [ ] Aucune `Subscription` Stripe n'est créée (test d'intégration avec Stripe en mode test).
- [ ] L'adhésion activée n'a pas de date d'expiration.

### 6.3 US-03 — Migration carte bancaire → prélèvement SEPA

**En tant que** membre actif payant par carte, **je veux** basculer sur le prélèvement SEPA.

1. Depuis la page « Mon compte » RCP, le membre choisit « Passer au prélèvement SEPA ».
2. Un **SetupIntent** `payment_method_types: ['sepa_debit']`, `usage: 'off_session'` est créé pour le
   `Customer` Stripe existant.
3. Après `confirmSepaDebitSetup()` côté client, le webhook `setup_intent.succeeded` fournit le
   `payment_method` généré.
4. Le plugin met à jour l'abonnement Stripe :
   `Subscription::update( $sub_id, [ 'default_payment_method' => $pm_id, 'payment_settings' => [ 'payment_method_types' => ['sepa_debit'] ] ] )`.
5. La passerelle de l'adhésion RCP est basculée de `stripe` à `stripe_sepa`.
6. L'ancien moyen de paiement carte est **détaché** du client Stripe uniquement si l'option
   « supprimer l'ancien moyen de paiement » est cochée ; par défaut il est conservé en secours.

**Règles de gestion**

- **RG-06** : la migration ne modifie **ni le prix, ni la date de prochaine échéance** de l'adhésion.
- **RG-07** : la migration est bloquée si l'adhésion est en `pending`, `expired` ou `cancelled`.
- **RG-08** : une facture déjà ouverte (`open`) au moment de la migration continue d'être réglée par
  l'ancien moyen de paiement, sauf action explicite de l'administrateur.

**Critères d'acceptation**

- [ ] Après migration, `Subscription.default_payment_method` pointe sur le `pm_*` SEPA.
- [ ] `current_period_end` de l'abonnement Stripe est inchangé (assertion avant/après).
- [ ] L'adhésion RCP reste `active` sans interruption d'accès.

### 6.4 US-04 — Suivi du mandat côté membre

- La page « Mon compte » affiche : IBAN masqué (`FR** **** **** **** **** **13`), nom du titulaire,
  référence de mandat (RUM), ICS du créancier, date de signature, statut du mandat.
- Un lien permet de télécharger / afficher l'URL du mandat fournie par Stripe (`Mandate.payment_method_details.sepa_debit.url`).

### 6.5 US-05 — Suivi côté administration

- Métabox sur la fiche d'adhésion : statut du dernier prélèvement, statut du mandat, lien direct vers
  le client, l'abonnement et le PaymentIntent dans le Dashboard Stripe (URL adaptée au mode
  test/production).
- Écran « Diagnostic SEPA » : configuration détectée (mode, clés présentes, capacité
  `sepa_debit_payments` du compte, secret de webhook), 20 derniers événements reçus avec leur
  résultat de traitement, bouton de rejeu manuel d'un événement.
- Intégration à **Site Health** de WordPress : tests « Webhook SEPA joignable », « Secret de webhook
  configuré », « Compte Stripe habilité au prélèvement SEPA », « HTTPS actif ».

### 6.6 Réglages du plugin

| Réglage | Type | Défaut | Description |
|---|---|---|---|
| Activer le prélèvement SEPA | Case à cocher | Désactivé | Active la passerelle |
| Secret de webhook (test) | Mot de passe | — | `whsec_…` du point de terminaison de test |
| Secret de webhook (production) | Mot de passe | — | `whsec_…` du point de terminaison de production |
| Politique d'accès | Radio | `strict` | `strict` : accès au contenu seulement après `succeeded` — `optimiste` : accès dès le mandat validé, révoqué en cas d'échec |
| Délai d'alerte « en attente » | Entier (jours) | 14 | Au-delà, alerte administrateur sur les paiements restés `processing` |
| Sur litige (`dispute`) | Radio | `révoquer` | `révoquer` / `notifier seulement` |
| Descripteur de relevé | Texte | — | Repris de RCP si vide |
| Texte de mandat personnalisé | Zone de texte | Texte Stripe par défaut | Doit conserver les mentions légales obligatoires |
| Journalisation détaillée | Case à cocher | Désactivé | Journalise les charges utiles Stripe expurgées |

**Le mode test/production n'est pas un réglage du plugin** : il est hérité du réglage global de RCP
(`$this->test_mode`), garantissant qu'un seul mode est actif sur le site (§9.6).

---

## 7. Modèle de données

### 7.1 Réutilisation des structures RCP

Aucune donnée d'adhésion ou de paiement n'est dupliquée. Le plugin utilise :

| Donnée | Stockage |
|---|---|
| ID client Stripe | `membership->set_gateway_customer_id()` |
| ID abonnement Stripe | `membership->set_gateway_subscription_id()` |
| ID d'intention | Meta de paiement `stripe_payment_intent_id` (déjà utilisée par RCP) |
| Passerelle utilisée | Champ `gateway` de l'adhésion = `stripe_sepa` |
| Journal | `rcp_log()` et notes d'adhésion `$membership->add_note()` |

### 7.2 Métadonnées de mandat (meta d'adhésion, préfixe `rcp_sepa_`)

| Clé | Type | Exemple | Sensibilité |
|---|---|---|---|
| `rcp_sepa_payment_method_id` | chaîne | `pm_1Q…` | Identifiant opaque |
| `rcp_sepa_mandate_id` | chaîne | `mandate_1Q…` | Identifiant opaque |
| `rcp_sepa_mandate_reference` | chaîne | `3F7X9K2L` | RUM |
| `rcp_sepa_mandate_url` | URL | `https://…` | Lien Stripe |
| `rcp_sepa_mandate_status` | énum | `active`, `inactive`, `pending` | — |
| `rcp_sepa_iban_last4` | chaîne (4) | `3000` | **Donnée personnelle** |
| `rcp_sepa_bank_code` / `branch_code` / `country` | chaîne | `FR` | Donnée personnelle |
| `rcp_sepa_account_holder_name` | chaîne | — | **Donnée personnelle** |
| `rcp_sepa_mandate_accepted_at` | datetime UTC | — | Preuve de consentement |
| `rcp_sepa_mandate_accepted_ip` | chaîne | — | **Donnée personnelle**, purge à 13 mois |

> **Interdit absolu :** l'IBAN complet n'est **jamais** stocké, journalisé, transmis par e-mail ni
> exposé dans une réponse AJAX. Seuls les 4 derniers caractères le sont. L'IBAN transite uniquement
> du navigateur vers Stripe, via Stripe.js.

### 7.3 Table d'idempotence des webhooks

`{$wpdb->prefix}rcp_sepa_webhook_events`

| Colonne | Type | Rôle |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Clé primaire |
| `event_id` | VARCHAR(255) **UNIQUE** | `evt_…` — verrou d'idempotence |
| `event_type` | VARCHAR(100) | `payment_intent.succeeded` |
| `livemode` | TINYINT(1) | Cloisonnement test / production |
| `status` | ENUM | `received`, `processed`, `skipped`, `failed` |
| `attempts` | SMALLINT | Nombre de tentatives de traitement |
| `payload_digest` | CHAR(64) | SHA-256 de la charge utile (audit, sans données personnelles) |
| `error` | TEXT NULL | Dernière erreur |
| `created_at` / `processed_at` | DATETIME | — |

Index : `UNIQUE(event_id)`, `KEY(event_type, created_at)`, `KEY(status)`.
Purge automatique (tâche planifiée quotidienne) des lignes `processed` de plus de 90 jours.

---

## 8. Webhooks et machine à états

### 8.1 Point de terminaison

- Route REST dédiée : `POST /wp-json/rcp-stripe-sepa/v1/webhook`
  (et non le listener `?listener=stripe` de RCP, qui ne vérifie pas la signature).
- `permission_callback` : `__return_true` — l'authentification est faite par la **signature Stripe**,
  pas par WordPress. C'est délibéré et documenté.
- Réponse `200` immédiate dès que l'événement est enregistré ; le traitement métier est exécuté dans
  la même requête si court, sinon via une tâche Action Scheduler / cron.
- Deux points de terminaison distincts sont configurés dans Stripe (test et production), avec des
  secrets distincts.

### 8.2 Événements traités

| Événement Stripe | Traitement |
|---|---|
| `payment_intent.processing` | Paiement RCP → `pending` ; note d'adhésion « prélèvement en cours » ; e-mail « prélèvement initié » |
| `payment_intent.succeeded` | Paiement → `complete` ; adhésion → `active` ; e-mail de bienvenue |
| `payment_intent.payment_failed` | Paiement → `failed` ; adhésion → `expired` (1re facture) ou `past_due` (renouvellement) ; e-mail d'échec avec motif localisé |
| `setup_intent.succeeded` | Récupération du `pm_*` généré ; persistance du mandat ; rattachement à l'abonnement |
| `setup_intent.setup_failed` | Inscription en échec ; adhésion → `expired` ; message d'erreur localisé |
| `invoice.paid` | Renouvellement réussi : création du paiement RCP `complete`, prolongation de l'adhésion |
| `invoice.payment_failed` | Renouvellement échoué : adhésion `past_due`, relance selon la politique Stripe Smart Retries |
| `invoice.payment_action_required` | Journalisation + alerte administrateur (cas rare en SEPA) |
| `charge.dispute.created` | Litige / R-transaction : révocation ou notification selon le réglage ; alerte administrateur systématique |
| `charge.refunded` | Remboursement : paiement RCP → `refunded`, adhésion révoquée si remboursement total |
| `mandate.updated` | Mise à jour du statut de mandat ; si `inactive`, alerte et blocage des prélèvements futurs |
| `customer.subscription.deleted` | Adhésion → `cancelled` |
| `customer.subscription.updated` | Synchronisation de la date de prochaine échéance |

Tout autre événement est enregistré au statut `skipped` et renvoie `200`.

### 8.3 Machine à états de l'adhésion

```
                 [inscription soumise]
                          │
                          ▼
                     ┌─────────┐   setup_intent.setup_failed
                     │ pending │────────────────────────────┐
                     └────┬────┘   payment_intent.payment_failed
                          │                                 │
     payment_intent.succeeded │ invoice.paid                ▼
                          │                             ┌─────────┐
                          ▼                             │ expired │
                     ┌────────┐                         └─────────┘
        ┌───────────▶│ active │◀──────────┐                  ▲
        │            └───┬────┘           │                  │
        │  invoice.paid  │  invoice.payment_failed           │
        │                ▼                │                  │
        │          ┌──────────┐           │                  │
        └──────────│ past_due │───────────┘                  │
                   └────┬─────┘                              │
                        │  échecs de relance épuisés         │
                        ▼                                    │
                  ┌───────────┐                              │
                  │ cancelled │   charge.dispute.created     │
                  └───────────┘   charge.refunded (total) ───┘
                   customer.subscription.deleted

  Révoquer l'accès, c'est `expired`. Dans RCP, une adhésion `cancelled` dont
  l'échéance n'est pas passée reste active : elle a été réglée, et son titulaire
  en garde le bénéfice jusqu'au terme. `cancelled` ne convient donc qu'au
  désabonnement — jamais à un encaissement qui n'a pas eu lieu ou repris.
```

### 8.4 Idempotence et robustesse

- **I-1** : avant tout traitement, insertion de `event_id` dans la table d'idempotence avec
  `INSERT ... ON DUPLICATE KEY UPDATE attempts = attempts + 1`. Si la ligne existe déjà au statut
  `processed`, l'événement est ignoré et `200` est renvoyé.
- **I-2** : toute création d'objet Stripe utilise une clé d'idempotence déterministe
  (`rcp_stripe_generate_idempotency_key()`).
- **I-3** : les événements peuvent arriver **dans le désordre**. Chaque handler vérifie l'état courant
  avant transition et refuse les transitions régressives (ex. `active` → `pending`).
- **I-4** : le champ `livemode` de l'événement doit correspondre au mode configuré ; sinon l'événement
  est rejeté (`skipped`) et journalisé. Cela empêche qu'un webhook de test n'active une adhésion en
  production.
- **I-5** : un échec de traitement renvoie `500` afin que Stripe rejoue l'événement ; au-delà de
  5 tentatives, l'événement passe `failed` et une alerte administrateur est émise.

---

## 9. Sécurité

> Chapitre prioritaire. Chaque exigence est testable et rattachée à un test automatisé.

### 9.1 Modèle de menace (synthèse)

| Menace | Vecteur | Contre-mesure | Réf. |
|---|---|---|---|
| Activation frauduleuse d'adhésion | Webhook forgé | Vérification de signature HMAC Stripe | S-01 |
| Rejeu d'un webhook légitime | Capture réseau / rejeu Stripe | Idempotence par `event_id` + tolérance temporelle | S-02 |
| Fuite d'IBAN | Journaux, e-mails, AJAX, base | IBAN jamais persisté côté serveur ; expurgation des journaux | S-03 |
| Fuite de clés API | Base de données, journaux, export | Constantes `wp-config.php`, masquage, exclusion des exports | S-04 |
| Élévation de privilèges | Actions AJAX/REST d'administration | Vérification de capacité + nonce systématique | S-05 |
| CSRF sur migration de moyen de paiement | Formulaire compte membre | Nonce + vérification de propriété de l'adhésion | S-06 |
| Injection SQL | Requêtes sur la table d'événements | `$wpdb->prepare()` exclusivement | S-07 |
| XSS stocké | Nom du titulaire, référence de mandat | Échappement à la sortie systématique | S-08 |
| Énumération / abus d'inscription | Formulaire public | Limitation de débit, contrôle IBAN serveur, honeypot | S-09 |
| Confusion test / production | Clés ou webhooks croisés | Contrôle `livemode` + cloisonnement des secrets | S-10 |
| Dépendance vulnérable | SDK Stripe, dépendances JS | Audit automatisé en CI | S-11 |

### 9.2 Exigences — Secrets et configuration

- **SEC-01** : les clés secrètes Stripe ne sont jamais écrites dans le code, un fichier versionné, un
  message de journal ou une réponse HTTP.
- **SEC-02** : les secrets de webhook peuvent être définis par constante dans `wp-config.php`
  (`RCP_SEPA_WEBHOOK_SECRET_TEST` / `_LIVE`) ; la constante prévaut sur l'option en base. Si elle est
  définie, le champ de réglage est affiché en lecture seule.
- **SEC-03** : tout champ de réglage sensible utilise `type="password"` et `autocomplete="off"`, et
  n'est jamais pré-rempli avec la valeur réelle (affichage masqué + champ de remplacement).
- **SEC-04** : les secrets sont exclus de l'exportateur de données personnelles et de toute
  exportation de réglages.
- **SEC-05** : `uninstall.php` supprime les options et métadonnées créées par le plugin, sauf si la
  constante `RCP_SEPA_KEEP_DATA_ON_UNINSTALL` est définie.

### 9.3 Exigences — Webhooks

- **SEC-06** : la signature est vérifiée avec `\Stripe\Webhook::constructEvent( $payload, $sig_header, $secret, $tolerance )`,
  sur la charge utile **brute** (`php://input`), avant toute désérialisation métier. Toute erreur
  `SignatureVerificationException` → `400` + journal, sans détail à l'appelant.
- **SEC-07** : tolérance temporelle de **300 secondes** ; les événements trop anciens sont rejetés.
- **SEC-08** : le plugin n'utilise **pas** le listener `?listener=stripe` de RCP. Si les deux points
  de terminaison sont configurés dans Stripe, les événements de la passerelle SEPA sont ignorés par
  RCP (filtrage sur la passerelle de l'adhésion) — un test de non-régression le vérifie.
- **SEC-09** : contrôle de cohérence `livemode` ↔ mode configuré (I-4).
- **SEC-10** : la réponse au webhook ne divulgue aucune information métier (pas d'ID d'adhésion, pas
  de message d'erreur détaillé). Corps de réponse minimal, détails en journal serveur uniquement.
- **SEC-11** : limitation de débit du point de terminaison (ex. 120 requêtes/minute par IP) avec
  réponse `429`, contournable par filtre pour les infrastructures à IP unique.

### 9.4 Exigences — Données bancaires et personnelles

- **SEC-12** : l'IBAN est saisi exclusivement dans un **Stripe Element** (iframe hébergée par Stripe).
  Aucun champ IBAN natif, aucune soumission d'IBAN au serveur WordPress. Un test statique en CI
  échoue si une chaîne ressemblant à un IBAN est trouvée dans le code PHP.
- **SEC-13** : `Logging\Redactor` masque, dans tout message journalisé : IBAN, `client_secret`,
  `sk_live_*`, `sk_test_*`, `whsec_*`, adresses e-mail (optionnel selon réglage).
- **SEC-14** : les données de mandat sont considérées comme des **données personnelles** au sens du
  RGPD et traitées par l'exportateur / effaceur (§10.2).
- **SEC-15** : l'IP de signature du mandat est purgée automatiquement après 13 mois (durée de la
  fenêtre de contestation « prélèvement non autorisé »).

### 9.5 Exigences — Contrôles d'accès et entrées

- **SEC-16** : toute action d'administration vérifie `current_user_can( 'rcp_manage_settings' )` (ou
  la capacité RCP appropriée) **et** un nonce dédié.
- **SEC-17** : toute action côté membre (migration de moyen de paiement) vérifie
  `is_user_logged_in()`, la propriété de l'adhésion (`$membership->get_user_id() === get_current_user_id()`)
  **et** un nonce. La vérification de propriété est un test de sécurité à part entière (IDOR).
- **SEC-18** : toutes les entrées sont assainies (`sanitize_text_field`, `absint`, `sanitize_email`,
  listes blanches pour les énumérations) ; toutes les sorties sont échappées
  (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- **SEC-19** : aucune requête SQL concaténée ; `$wpdb->prepare()` obligatoire, vérifié par PHPCS
  (`WordPress.DB.PreparedSQL`).
- **SEC-20** : validation IBAN côté serveur **uniquement** sur le pays et le format lorsqu'un IBAN
  masqué est manipulé — jamais sur un IBAN complet (qui n'atteint pas le serveur).

### 9.6 Exigences — Cloisonnement test / production

- **SEC-21** : le mode est unique et hérité de RCP. Le plugin n'introduit pas son propre sélecteur.
- **SEC-22** : les secrets de webhook, les événements stockés et les liens Dashboard sont cloisonnés
  par mode.
- **SEC-23** : en mode test, un bandeau permanent et non masquable est affiché sur le formulaire
  d'inscription et dans l'administration : « Mode test Stripe — aucun prélèvement réel ».
- **SEC-24** : un garde-fou refuse le démarrage si une clé `sk_live_*` est détectée alors que le mode
  test est actif (et inversement), avec un avis d'administration explicite.

### 9.7 Exigences — Chaîne d'approvisionnement

- **SEC-25** : `composer audit` et `npm audit` exécutés en CI ; échec du build sur vulnérabilité
  haute ou critique.
- **SEC-26** : versions des dépendances verrouillées (`composer.lock`, `package-lock.json` versionnés).
- **SEC-27** : aucune ressource tierce chargée depuis un CDN, à l'exception de `js.stripe.com`
  (imposé par Stripe et interdit de copie locale).
- **SEC-28** : revue de sécurité obligatoire avant chaque version (checklist en annexe D).

---

## 10. Conformité

### 10.1 Conformité SEPA / DSP2

- **CNF-01** : le texte de mandat affiché doit comporter les mentions obligatoires : identité du
  créancier, ICS, caractère récurrent ou ponctuel, droit au remboursement dans les 8 semaines, et
  référence au contrat. Le texte par défaut est celui fourni par Stripe via le Payment Element ; toute
  personnalisation doit conserver ces mentions (contrôle au moment de l'enregistrement du réglage).
- **CNF-02** : le consentement doit être **explicite et actif** — la soumission du formulaire vaut
  signature électronique. La date, l'heure UTC et l'IP sont horodatées et conservées comme preuve.
- **CNF-03** : la pré-notification du débiteur (montant et date du prélèvement, au moins 2 jours
  ouvrés avant, ou 1 jour si convenu) est assurée par Stripe. Le plugin vérifie dans l'écran de
  diagnostic que l'option correspondante est activée sur le compte Stripe et alerte si elle ne l'est
  pas.
- **CNF-04** : le prélèvement SEPA n'est pas soumis à l'authentification forte DSP2 (SCA) — le mandat
  fait foi. Aucun flux 3-D Secure n'est à implémenter.

### 10.2 RGPD

- **CNF-05** : enregistrement d'un exportateur (`wp_privacy_personal_data_exporters`) restituant les
  métadonnées de mandat (hors secrets) et la liste des prélèvements.
- **CNF-06** : enregistrement d'un effaceur (`wp_privacy_personal_data_erasers`) supprimant les
  métadonnées de mandat locales. La suppression côté Stripe n'est pas automatisée (obligations
  comptables) : l'effaceur signale ce point à l'administrateur.
- **CNF-07** : mention, dans la documentation administrateur, du transfert de données vers Stripe
  (sous-traitant) et des bases légales.
- **CNF-08** : durée de conservation documentée pour chaque métadonnée (§7.2).

---

## 11. Internationalisation et accessibilité

- **I18N-01** : toutes les chaînes visibles passent par les fonctions de traduction avec le text
  domain `rcp-stripe-sepa` ; aucune chaîne concaténée.
- **I18N-02** : fichier `languages/rcp-stripe-sepa.pot` généré par `wp i18n make-pot` en CI ; traductions
  FR et EN fournies.
- **I18N-03** : chaînes JavaScript traduites via `@wordpress/i18n` + `wp_set_script_translations()`.
- **I18N-04** : les messages d'erreur Stripe sont traduits via une table de correspondance de codes
  d'erreur (`insufficient_funds`, `debit_not_authorized`, `account_closed`, `invalid_iban`…), avec
  repli sur le message Stripe si le code est inconnu.
- **I18N-05** : montants et dates formatés selon la locale du site (`date_i18n`, `rcp_currency_filter`).
- **A11Y-01** : formulaire conforme WCAG 2.1 AA — libellés associés, erreurs annoncées via
  `aria-live`, navigation clavier complète, contrastes conformes.

---

## 12. Standards WordPress et qualité de code

- **STD-01** : WordPress Coding Standards (PHP, JS, CSS) via PHPCS avec les jeux de règles
  `WordPress`, `WordPress-Extra`, `WordPress-Docs`, `WordPress-VIP-Go` (indicatif).
- **STD-02** : toutes les fonctions, classes et méthodes documentées en DocBlock (paramètres, retour,
  `@since`).
- **STD-03** : PHPStan niveau 5 minimum avec `szepeviktor/phpstan-wordpress`.
- **STD-04** : préfixage systématique (§5.3) pour éviter toute collision.
- **STD-05** : chargement conditionnel — le plugin ne s'initialise que si RCP est actif et compatible ;
  sinon, avis d'administration et arrêt propre (pas d'erreur fatale).
- **STD-06** : compatibilité multisite (activation par site).
- **STD-07** : en-tête de plugin complet : `Requires at least`, `Requires PHP`, `Requires Plugins`,
  `License`, `Text Domain`, `Domain Path`.
- **STD-08** : aucune sortie avant `plugins_loaded` ; aucun appel HTTP au chargement de l'admin.
- **STD-09** : respect des principes de style convenus : immuabilité (aucune mutation d'objet passé en
  argument), fichiers de 200 à 400 lignes (800 maximum), fonctions de moins de 50 lignes, sorties
  anticipées plutôt qu'imbrications profondes, constantes nommées plutôt que valeurs magiques.

---

## 13. Stratégie de test et non-régression

### 13.1 Pyramide de tests

| Niveau | Outil | Portée | Cible |
|---|---|---|---|
| **Unitaire** | PHPUnit + Brain Monkey (WP mocké) | Construction des arguments d'intention, machine à états, expurgation des journaux, validation IBAN, correspondance des codes d'erreur | ≥ 80 % de lignes, exécution < 10 s |
| **Intégration WP** | PHPUnit + `wordpress-tests-lib`, WP réel + RCP réel, API Stripe **bouchonnée** | Enregistrement de la passerelle, cycle de vie d'adhésion, persistance des métadonnées, idempotence des webhooks, RGPD | Tous les parcours §6 |
| **Contrat Stripe** | PHPUnit + `stripe-mock` (serveur officiel) | Conformité des charges utiles envoyées au schéma OpenAPI de l'API | Toutes les requêtes du plugin |
| **Contrat RCP** | PHPUnit | Existence et signature des méthodes/propriétés de RCP utilisées par héritage ; classe non finale ; capacités déclarées ; compatibilité de la version d'API avec le SDK embarqué | Exécuté sur chaque version **et chaque variante** de RCP de la matrice |
| **Webhook** | PHPUnit + charges utiles enregistrées (fixtures) | Chaque événement §8.2 : nominal, rejeu, désordre, signature invalide, `livemode` incohérent | 100 % des événements |
| **E2E** | Playwright + WordPress Docker + Stripe en mode test réel | Parcours §6.1 à §6.3 de bout en bout avec les IBAN de test | 3 parcours critiques |
| **Sécurité** | Tests dédiés + analyse statique | Chaque exigence SEC-xx testable | 100 % des SEC-xx testables |

### 13.2 Tests de sécurité automatisés (extrait)

| Réf. | Test |
|---|---|
| T-SEC-06 | Un POST sur le webhook sans en-tête `Stripe-Signature` renvoie 400 et ne modifie aucune adhésion |
| T-SEC-06b | Un POST avec une signature calculée avec un mauvais secret renvoie 400 |
| T-SEC-07 | Un événement horodaté à −10 minutes est rejeté |
| T-SEC-09 | Un événement `livemode: true` reçu en mode test est ignoré sans effet de bord |
| T-SEC-12 | Analyse statique : aucun motif IBAN et aucun `$_POST['iban']` dans le code PHP |
| T-SEC-13 | Un message contenant `sk_live_…` ou un IBAN ressort expurgé du `Redactor` |
| T-SEC-17 | Le membre A ne peut pas migrer le moyen de paiement de l'adhésion du membre B (IDOR) |
| T-SEC-16 | Une action d'administration sans nonce valide renvoie 403 |
| T-SEC-24 | Une clé `sk_live_` en mode test bloque l'initialisation de la passerelle |
| T-I-1 | Le même `event_id` rejoué 5 fois ne produit qu'un seul enregistrement de paiement |

### 13.3 Fixtures d'IBAN de test Stripe (mode test)

| IBAN | Comportement |
|---|---|
| `FR1420041010050500013M02606` | `processing` → `succeeded` |
| `FR3020041010050500013M02609` | `processing` → `succeeded` après ≥ 3 minutes |
| `FR8420041010050500013M02607` | `processing` → `requires_payment_method` (échec) |
| `FR7920041010050500013M02600` | Échec différé (≥ 3 minutes) |
| `FR5720041010050500013M02608` | `succeeded` puis litige créé immédiatement |
| `FR9720041010050000002222227` | Échec `insufficient_funds` |
| `DE89370400440532013000` | Succès (cas non français) |

### 13.4 Politique de non-régression

- **NR-01** : aucune fusion sans CI verte sur l'intégralité de la matrice §3.2.
- **NR-02** : toute correction de bogue est précédée d'un test qui échoue (TDD : rouge → vert → refactor).
- **NR-03** : la couverture ne peut pas diminuer d'une version à l'autre (contrôle par seuil en CI).
- **NR-04** : les tests de contrat RCP sont exécutés **quotidiennement** sur la dernière version de
  RCP afin de détecter une rupture amont avant les utilisateurs.
- **NR-05** : `test.skip` / `test.only`, tests fictifs et branches non implémentées sont des motifs de
  blocage, détectés par un contrôle automatisé en CI.
- **NR-06** : les tests E2E enregistrent capture d'écran, vidéo et trace en cas d'échec, publiées
  comme artefacts de CI.
- **NR-07** : les suites ne partagent pas de processus. La suite unitaire remplace les fonctions de
  WordPress (Brain Monkey / Patchwork), les autres les chargent réellement : les exécuter ensemble
  fait échouer Patchwork. Le fichier d'amorçage refuse explicitement une invocation combinée, et la
  couverture est fusionnée a posteriori par `phpcov` (`bin/coverage.sh`).

---

## 14. Environnement Docker de développement et de test

### 14.1 Objectif

Fournir un environnement **reproductible en une commande**, contenant WordPress, MySQL, RCP, le
plugin monté en volume, WP-CLI, l'outillage de test et le relais de webhooks Stripe — de sorte que
les tests automatisés soient exécutables à l'identique en local et en CI.

### 14.2 Services

| Service | Image | Rôle |
|---|---|---|
| `db` | `mysql:8.0` | Base WordPress |
| `db-tests` | `mysql:8.0` (tmpfs) | Base dédiée à la suite d'intégration, réinitialisée à chaque exécution |
| volume `test-data` | — | Cœur WordPress et bibliothèque de tests, persistés entre deux conteneurs éphémères |
| `wordpress` | `wordpress:php8.2-apache` (image dérivée avec Xdebug, WP-CLI, Composer) | Site de développement, `http://localhost:8080` |
| `wpcli` | même image dérivée | Installation, configuration, exécution des tests |
| `mailpit` | `axllent/mailpit` | Capture des e-mails transactionnels, `http://localhost:8025` |
| `stripe-cli` | `stripe/stripe-cli` | `stripe listen --forward-to` vers le point de terminaison du plugin |
| `bin/webhook.php` | — | Rejeu hors ligne d'événements signés localement, sans réseau ni tunnel (`docs/webhooks-en-local.md`) |
| `stripe-mock` | `stripe/stripe-mock` | API Stripe bouchonnée pour les tests de contrat hors ligne |
| `playwright` | `mcr.microsoft.com/playwright` | Exécution des tests E2E |

### 14.3 Provisionnement automatique

Le script `bin/setup.sh` (idempotent) exécute :

1. attente de la disponibilité de MySQL ;
2. `wp core install` avec des identifiants de développement ;
3. installation et activation de **Restrict Content** (socle libre, contenant la passerelle Stripe)
   depuis le dépôt WordPress.org — version épinglée par variable d'environnement ;
3 bis. déclenchement du hook `admin_init`, que RCP utilise pour créer ses tables : sans lui, aucun
   niveau d'adhésion ne peut être créé en ligne de commande ;
4. installation de **Restrict Content Pro** si une archive est déposée dans `vendor-plugins/`
   (répertoire ignoré par Git — voir §14.5) ;
5. activation du plugin `rcp-stripe-sepa` (monté en volume depuis le dépôt) ;
6. configuration des réglages RCP : mode test, clés API lues depuis `.env`, devise EUR ;
7. création des niveaux d'adhésion de test (mensuel, annuel, à vie, gratuit) ;
8. création de pages de contenu protégé et d'utilisateurs de test ;
9. configuration du secret de webhook à partir de la sortie de `stripe listen`.

### 14.4 Commandes (Makefile)

| Commande | Effet |
|---|---|
| `make up` | Démarre la pile et provisionne le site |
| `make down` / `make clean` | Arrête / détruit volumes et données |
| `make shell` | Ouvre un shell dans le conteneur WordPress |
| `make wp CMD="..."` | Exécute une commande WP-CLI |
| `make test` | Exécute l'intégralité de la suite (unit + intégration + contrat) |
| `make test-unit` / `test-integration` / `test-contract` / `test-webhooks` | Suites ciblées |
| `make test-e2e` | Exécute Playwright |
| `make coverage` | Génère le rapport de couverture HTML |
| `make lint` | PHPCS + ESLint + PHPStan |
| `make fix` | PHPCBF + ESLint --fix |
| `make stripe-listen` | Relaie les webhooks Stripe vers le site local |
| `make stripe-trigger EVENT=payment_intent.succeeded` | Déclenche un événement de test |
| `make webhook-secret` | Génère un secret de webhook pour le développement local |
| `make webhook-send FIXTURE=...` | Signe une fixture et la rejoue vers le point de terminaison |
| `make webhook-attack` | Rejoue avec signature invalide, absente et antidatée |
| `make webhook-capture EVENT_ID=... NAME=...` | Enregistre un événement Stripe réel en fixture |
| `make logs` | Suit les journaux WordPress et PHP |
| `make matrix` | Rejoue la suite sur toute la matrice PHP × WP × RCP |

### 14.5 Licences et archives propriétaires

RCP Pro est un produit commercial : **son archive ne doit jamais être versionnée**. Le répertoire
`vendor-plugins/` est listé dans `.gitignore` ; l'administrateur y dépose l'archive et renseigne sa
clé de licence dans `.env` (également ignoré). Le socle libre « Restrict Content », publié sur
WordPress.org sous GPL, contient la passerelle Stripe et suffit à l'essentiel des tests ; la CI
publique s'appuie sur lui, et un job optionnel, déclenché manuellement avec des secrets de dépôt,
exécute la suite contre RCP Pro.

---

## 15. Intégration et déploiement continus

**Déclencheurs :** `push`, `pull_request`, `schedule` (quotidien).

| Job | Contenu | Bloquant |
|---|---|---|
| `lint` | PHPCS, PHPStan, ESLint, Stylelint | Oui |
| `security` | `composer audit`, `npm audit`, analyse de motifs interdits (§13.2 T-SEC-12) | Oui (haute/critique) |
| `unit` | PHPUnit unitaire, matrice PHP 7.4 → 8.3 | Oui |
| `integration` | PHPUnit intégration, matrice WP × RCP | Oui |
| `contract` | `stripe-mock` + tests de contrat RCP | Oui |
| `e2e` | Playwright sur la pile Docker, clés Stripe de test en secrets | Oui sur `main` |
| `coverage` | Publication + contrôle du seuil 80 % | Oui |
| `i18n` | `wp i18n make-pot` et vérification que le `.pot` est à jour | Oui |
| `build` | Construction de l'archive distribuable (sans fichiers de développement) | Sur étiquette de version |

Gestion de versions : SemVer. Étiquettes `vX.Y.Z`. `CHANGELOG.md` au format Keep a Changelog.
Messages de commit conventionnels (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`, `perf:`, `ci:`).

---

## 16. Documentation

### 16.1 Documentation développeur (`docs/`)

- `architecture.md` — schémas de composants et de séquence (inscription, webhook, migration).
- `hooks.md` — référence exhaustive des actions et filtres exposés, avec exemples.
- `stripe-integration.md` — objets Stripe utilisés, version d'API, contraintes, événements.
- `testing.md` — comment exécuter, écrire et déboguer chaque niveau de test.
- `adr/` — décisions d'architecture.
- `CONTRIBUTING.md` — flux de travail, normes, revue.
- `SECURITY.md` — politique de divulgation, checklist de revue.

### 16.2 Documentation administrateur

- Guide d'installation et de configuration pas à pas (compte Stripe, ICS, webhooks, mode test).
- Guide de passage en production (checklist annexe C).
- Guide d'exploitation : lire l'écran de diagnostic, interpréter les statuts, rejouer un événement,
  traiter un impayé, traiter un litige.
- FAQ : délais SEPA, remboursements, changement d'IBAN, résiliation.

### 16.3 Documentation de code

DocBlocks complets, plus un fichier `readme.txt` au format WordPress (utile même en distribution
privée, pour la cohérence d'outillage et le mécanisme de mise à jour).

---

## 17. Risques et mitigations

| # | Risque | Prob. | Impact | Mitigation |
|---|---|---|---|---|
| R1 | Rupture d'API interne lors d'une mise à jour de RCP (héritage) | Moyenne | Élevé | Tests de contrat RCP quotidiens (NR-04) ; garde-fou de version ; désactivation propre de la passerelle si incompatible |
| R2 | Conflit de version d'API Stripe avec la passerelle carte | Moyenne | Élevé | R-API-1/2/3 ; test de non-régression du parcours carte dans la suite E2E |
| R3 | Collision de SDK Stripe si un autre plugin charge `\Stripe\` | Faible | Élevé | Détection de version au chargement ; option d'évolution : SDK préfixé (PHP-Scoper/Strauss) |
| R4 | Litiges / impayés SEPA mal traités → perte de revenus ou accès indu | Moyenne | Élevé | Machine à états explicite, politique d'accès `strict` par défaut, alertes administrateur |
| R5 | Attente de 2 à 14 jours perçue comme un dysfonctionnement par les membres | Élevée | Moyen | E-mails et pages d'attente explicites ; affichage du statut dans « Mon compte » |
| R6 | RCP Pro non versionnable → CI publique partielle | Certaine | Faible | Socle libre en CI publique + job protégé avec RCP Pro (§14.5) |
| R7 | Webhooks non reçus (pare-feu, cache, `?listener` bloqué) | Moyenne | Élevé | Test Site Health de joignabilité ; alerte sur paiements restés `processing` au-delà du délai configuré |
| R8 | Double traitement par le listener RCP et le point de terminaison du plugin | Moyenne | Moyen | SEC-08 + test de non-régression dédié |

---

## 18. Livrables et jalons

| Jalon | Livrables | Sortie attendue |
|---|---|---|
| **J0 — Cadrage** | Ce cahier des charges, ADR-0001, dépôt Git initialisé | Document validé |
| **J1 — Socle technique** | Pile Docker, provisionnement, outillage qualité, CI squelette, suite de tests vide qui s'exécute | `make up && make test` fonctionnel |
| **J2 — Spike de validation** | Vérification des contraintes §3.3 à §3.6 sur l'environnement réel (version d'API par requête, coexistence avec la passerelle carte) | Rapport de spike ; confirmation ou révision de l'ADR-0001 |
| **J3 — Passerelle et inscription récurrente** | F-01, F-02, F-04, F-09 + tests unitaires et d'intégration | US-01 validée |
| **J4 — Webhooks et états** | F-06, F-07 + suite webhook complète | Tous les événements §8.2 couverts |
| **J5 — Paiement à vie** | F-03 + tests | US-02 validée |
| **J6 — Migration CB → SEPA** | F-05 + tests, dont T-SEC-17 | US-03 validée |
| **J7 — Interfaces et e-mails** | F-08, F-10, F-13 | Écrans membre et administration livrés |
| **J8 — Sécurité et conformité** | F-12, chapitres 9 et 10, tests SEC-xx, revue de sécurité | Checklist annexe D complète |
| **J9 — i18n, documentation, E2E** | F-11, F-15, suite Playwright | Documentation complète, E2E verts |
| **J10 — Recette et v1.0.0** | Archive distribuable, `CHANGELOG.md`, guide de mise en production | Version étiquetée |

---

## 19. Définition de « terminé » (DoD)

Une fonctionnalité est terminée lorsque :

- [ ] Le code respecte les standards (§12) — PHPCS et PHPStan au vert.
- [ ] Les tests unitaires et d'intégration correspondants existent et passent ; la couverture globale
      reste ≥ 80 %.
- [ ] Les exigences de sécurité applicables sont couvertes par un test automatisé.
- [ ] Les chaînes sont internationalisées et le `.pot` est à jour.
- [ ] La documentation développeur et, le cas échéant, administrateur est mise à jour.
- [ ] Aucun marqueur `TODO` de substitution, aucun test ignoré, aucune branche non implémentée.
- [ ] Le parcours carte natif de RCP est vérifié non régressé.
- [ ] Revue de code effectuée par une passe distincte de la passe d'écriture.
- [ ] La CI est verte sur toute la matrice.

---

## Annexe A — Glossaire

| Terme | Définition |
|---|---|
| **SEPA** | Single Euro Payments Area — espace unique de paiement en euros |
| **SDD** | SEPA Direct Debit — prélèvement automatique SEPA |
| **Mandat** | Autorisation donnée par le débiteur au créancier de prélever son compte |
| **RUM** | Référence Unique de Mandat (`mandate_reference` chez Stripe) |
| **ICS** | Identifiant Créancier SEPA (Creditor Identifier) |
| **R-transaction** | Rejet, retour ou remboursement d'un prélèvement (refund, return, reject, reversal) |
| **PaymentIntent** | Objet Stripe suivant le cycle de vie d'un encaissement |
| **SetupIntent** | Objet Stripe suivant l'enregistrement d'un moyen de paiement sans encaissement |
| **Passerelle (gateway)** | Implémentation RCP d'un moyen de paiement |

## Annexe B — Correspondance exigences / tests

Tableau maintenu dans `docs/traceability.md`, généré à partir des annotations `@covers` et des
identifiants d'exigence (`F-xx`, `RG-xx`, `SEC-xx`, `CNF-xx`, `I-x`) présents dans les noms de tests.

## Annexe C — Mise en production

Le prélèvement SEPA n'est pas une carte : un encaissement met deux à quatorze
jours ouvrés, un rejet peut survenir huit semaines après coup, et un mandat
contesté treize mois après. Une bascule ratée ne se voit donc pas le jour même.
D'où une recette qui s'étale, et un retour arrière qui reste possible pendant
toute cette période.

Les cases se cochent dans l'ordre. Chacune indique **comment le vérifier** :
une case cochée sur une intention, sans preuve, ne vaut rien.

### C.1 Sept jours avant — habilitations et conformité

Ces points dépendent de tiers (Stripe, banque, juriste) et ne se rattrapent pas
la veille.

- [ ] **Compte Stripe habilité au prélèvement SEPA.** Dashboard → *Settings →
      Payment methods* : « SEPA Direct Debit » au statut *Active*, et non
      *Pending*. L'habilitation peut demander plusieurs jours.
- [ ] **Identifiant Créancier SEPA (ICS) attribué et affiché** dans les
      paramètres du compte. Sans ICS, aucun mandat n'est émissible.
- [ ] **Pré-notification activée** (CNF-03). Dashboard → *Settings → Customer
      emails* : l'avis de prélèvement doit partir au moins deux jours ouvrés
      avant le débit. C'est une obligation, pas un confort.
- [ ] **Texte du mandat relu par le référent juridique**, dans chaque langue
      publiée. Comparer l'écran d'inscription réel, pas le fichier `.pot`.
- [ ] **Mentions légales et politique de confidentialité à jour** : transfert de
      données vers Stripe, durées de conservation, droits d'accès et
      d'effacement (§ RGPD).
- [ ] **Devise EUR** sur le site *et* sur chaque niveau d'adhésion concerné. Le
      SEPA n'accepte rien d'autre ; un niveau en devise étrangère ne proposera
      pas la passerelle.
- [ ] **Adresse de supervision définie** et relevée par quelqu'un : elle
      recevra les alertes de litige et de mandat révoqué.

### C.2 La veille — préparation technique

- [ ] **Sauvegarde de la base effectuée et restaurée ailleurs pour épreuve.**
      Une sauvegarde qu'on n'a jamais restaurée n'est pas une sauvegarde.
- [ ] **Version figée et construite** : `make build`, archive obtenue depuis un
      dépôt propre (`git status` vide, étiquette posée).
- [ ] **Suites vertes sur la version livrée** : `make test` et `make lint`.
- [ ] **Matrice de compatibilité rejouée** : `make matrix` (PHP × WordPress ×
      RCP), sur la variante — libre ou Pro — réellement installée en production.
- [ ] **Revue de sécurité passée** : annexe D, intégralement.
- [ ] **Fenêtre de bascule choisie hors jour d'échéance** d'un renouvellement
      existant, pour ne pas mêler migration et prélèvement.

### C.3 Le jour J — bascule

L'ordre importe : le point de terminaison doit exister et être joignable *avant*
qu'une inscription puisse produire un événement.

- [ ] **HTTPS valide**, certificat non expiré, chaîne complète. Stripe refuse de
      livrer un webhook à un certificat invalide et n'alerte que par e-mail.
- [ ] **Clés de production renseignées** dans RCP (*Restrict → Settings →
      Payments*), mode bac à sable désactivé. Les clés de test ne doivent plus
      figurer nulle part, y compris dans `wp-config.php`.
- [ ] **Point de terminaison de webhook de production créé** dans le Dashboard
      Stripe, pointant sur `https://<site>/wp-json/rcp-stripe-sepa/v1/webhook`.
      L'adresse exacte est rappelée sur l'écran *Restrict → SEPA Direct Debit*.
- [ ] **Les treize événements du §8.2 souscrits**, ni plus ni moins. Un
      événement manquant laisse une adhésion figée ; un événement superflu
      encombre le journal sans effet.
- [ ] **Secret du point de terminaison reporté** dans la constante
      `RCP_SEPA_WEBHOOK_SECRET_LIVE` de `wp-config.php` — de préférence à
      l'option en base, qu'une sauvegarde exportée exposerait.
- [ ] **Plugin activé**, puis **écran de diagnostic entièrement au vert** :
      *Restrict → SEPA Direct Debit*. Les cinq contrôles — passerelle, devise,
      HTTPS, secret de webhook, paiements en souffrance — doivent tous être
      satisfaits. Un contrôle en alerte se traite avant d'ouvrir les
      inscriptions.
- [ ] **Politiques de traitement décidées et posées** : conduite en cas de
      litige (révoquer l'accès ou seulement notifier) et politique d'accès
      pendant l'encaissement (`strict` par défaut : pas d'accès tant que les
      fonds ne sont pas confirmés). Ce sont des choix commerciaux, pas des
      réglages techniques.
- [ ] **Joignabilité du webhook éprouvée depuis Stripe** : *Send test webhook*
      depuis le Dashboard, puis vérifier que l'événement apparaît bien dans le
      journal du plugin. Un `200` côté Stripe ne prouve rien si le plugin l'a
      ignoré.

### C.4 Recette en production

Tant que ces points ne sont pas obtenus, considérer la bascule comme non
terminée — même si tout paraît fonctionner.

- [ ] **Un prélèvement réel de faible montant** effectué sur un compte
      bancaire maîtrisé, avec un IBAN réel : les IBAN de test ne franchissent
      pas le mode production.
- [ ] **Suivi jusqu'à `succeeded`**, c'est-à-dire deux à quatorze jours plus
      tard. Vérifier alors : adhésion `active`, paiement RCP `complete`, accès
      au contenu ouvert, e-mail de bienvenue reçu.
- [ ] **Un prélèvement refusé éprouvé** (IBAN de compte clos, ou refus
      provoqué) : adhésion `expired`, contenu refermé, e-mail d'échec reçu.
      C'est le scénario que les tests de bout en bout ont pris en défaut ;
      il mérite d'être revu sur le site réel.
- [ ] **Une bascule carte → SEPA effectuée** sur une adhésion existante : prix
      et date de prochaine échéance inchangés (RG-06).
- [ ] **Aucun paiement en souffrance anormal** après quinze jours : le contrôle
      « paiements en souffrance » de l'écran de diagnostic reste vert.

### C.5 Retour arrière

À préparer avant d'en avoir besoin. Le retour arrière n'est pas symétrique :
désactiver le plugin n'annule pas les mandats déjà signés ni les prélèvements
en cours.

- [ ] **Procédure écrite et à portée de main** : désactiver la passerelle SEPA
      dans RCP — ce qui la retire du formulaire d'inscription sans toucher aux
      adhésions existantes — plutôt que désactiver le plugin, ce qui laisserait
      les webhooks sans destinataire.
- [ ] **Ne jamais supprimer le point de terminaison de webhook** tant qu'un
      prélèvement est en cours : les événements perdus ne sont pas rejoués
      indéfiniment par Stripe.
- [ ] **Les adhésions déjà migrées restent en SEPA** : prévoir leur traitement
      (retour à la carte, ou maintien) avant d'annoncer un retour arrière.

### C.6 Surveillance des premières semaines

Le SEPA rend la supervision plus longue qu'un déploiement ordinaire.

- [ ] **Huit semaines** : fenêtre pendant laquelle un débiteur peut faire
      rejeter un prélèvement autorisé, sans motif. Surveiller
      `charge.dispute.created` et les remboursements.
- [ ] **Treize mois** : fenêtre de contestation d'un prélèvement *non* autorisé.
      Conserver la preuve du mandat — date, RUM, IBAN tronqué — sur toute cette
      durée, et la purger ensuite.
- [ ] **Journal des événements relu chaque semaine** le premier mois : aucun
      événement au statut `failed` ni abandonné après épuisement des tentatives.
- [ ] **Alertes reçues et traitées**, pas seulement émises : vérifier que la
      boîte de supervision reçoit bien les litiges et mandats révoqués.

## Annexe D — Checklist de revue de sécurité (avant chaque version)

- [ ] Aucun secret dans le code, les journaux, les tests ou les fixtures.
- [ ] Toutes les entrées assainies, toutes les sorties échappées.
- [ ] Toutes les requêtes SQL préparées.
- [ ] Nonce + capacité sur chaque action d'administration ; nonce + propriété sur chaque action membre.
- [ ] Signature de webhook vérifiée avant tout traitement ; tolérance temporelle appliquée.
- [ ] Idempotence vérifiée par test de rejeu.
- [ ] Cloisonnement test/production vérifié (`livemode`).
- [ ] Aucun IBAN complet manipulé côté serveur.
- [ ] Expurgation des journaux vérifiée.
- [ ] `composer audit` et `npm audit` sans vulnérabilité haute ou critique.
- [ ] Messages d'erreur sans divulgation d'information interne.

## Annexe E — Références

- Stripe — [Abonnement avec prélèvement SEPA](https://docs.stripe.com/billing/subscriptions/sepa-debit)
- Stripe — [Accepter un paiement SEPA](https://docs.stripe.com/payments/sepa-debit/accept-a-payment)
- Stripe — [Enregistrer un moyen de paiement SEPA](https://docs.stripe.com/payments/sepa-debit/set-up-payment)
- Stripe — [Versionnement de l'API](https://docs.stripe.com/api/versioning)
- Stripe — [Signature des webhooks](https://docs.stripe.com/webhooks/signature)
- Restrict Content Pro — [Base de connaissances Stripe](https://restrictcontentpro.com/knowledgebase/stripe/)
- Code source — [`stellarwp/restrict-content`](https://github.com/stellarwp/restrict-content)
- WordPress — [Coding Standards](https://developer.wordpress.org/coding-standards/)
- WordPress — [Plugin Security](https://developer.wordpress.org/apis/security/)
