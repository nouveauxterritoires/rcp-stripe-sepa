# Compatibilité avec les variantes de Restrict Content Pro

Le plugin s'appuie sur des API internes de RCP (héritage de
`RCP_Payment_Gateway_Stripe`). Deux produits distincts exposent ces API, et le
plugin doit fonctionner avec les deux sans branche conditionnelle.

## 1. Les deux variantes

| | Variante libre | Variante commerciale |
|---|---|---|
| Nom affiché | Kadence Memberships (anciennement Restrict Content) | Restrict Content Pro |
| Slug / fichier principal | `restrict-content/restrictcontent.php` | `restrict-content-pro/restrict-content-pro.php` |
| Distribution | WordPress.org, GPL | Licence commerciale StellarWP |
| Noyau RCP | Embarqué dans `core/` | À la racine |
| Passerelle Stripe | **Présente** | Présente |
| Text domain | `rcp` | `rcp` |

Les deux définissent la même classe `Restrict_Content_Pro` et les mêmes
constantes (`RCP_PLUGIN_VERSION`, `RCP_PLUGIN_DIR`, `RCP_PLUGIN_URL`). La
variante libre y ajoute `RCF_VERSION`, `RCP_ROOT` et `RCP_WEB_ROOT`.

## 2. Principe retenu : détecter les capacités, pas les versions

`RCP_Stripe_Sepa\Compat\RcpEnvironment` n'autorise jamais le démarrage sur la
foi d'un nom de plugin ou d'un numéro de version. Trois constats l'imposent.

### 2.1 Les numéros de version ne sont pas comparables

La variante libre 4.0.4 déclare `RCP_PLUGIN_VERSION = 4.0.7`, alors que la
variante commerciale est en 3.5.x. Un même noyau porte donc deux numérotations
disjointes. La version n'est utilisée que pour écarter un noyau manifestement
antérieur à l'ère des PaymentIntents (< 3.5), et son absence n'est jamais
bloquante.

### 2.2 La variante libre peut tourner sans noyau RCP

L'option `restrict_content_chosen_version` peut valoir `legacy` : le plugin
charge alors l'ancien Restrict Content 2.x, dépourvu de toute passerelle de
paiement. Le plugin détecte ce mode et affiche un message dédié plutôt que
d'énumérer des dizaines de classes absentes.

### 2.3 Le SDK Stripe est chargé paresseusement

RCP n'inclut son SDK Stripe qu'au premier appel de
`RCP_Payment_Gateway_Stripe::init()`. Sur `plugins_loaded`, la classe
`\Stripe\Stripe` n'existe donc pas encore. Exiger sa présence au démarrage
rendrait le plugin définitivement inactif.

Le plugin distingue en conséquence trois états :

| État | Détection | Conséquence |
|---|---|---|
| SDK chargé | `\Stripe\Stripe::VERSION` lisible | Version contrôlée immédiatement |
| SDK présent mais différé | fichier `…/libraries/stripe/init.php` trouvé | Démarrage autorisé, version contrôlée à l'initialisation de la passerelle |
| SDK absent | ni l'un ni l'autre | Démarrage refusé, message d'administration |

Le chemin du SDK est cherché aux deux emplacements possibles :
`core/includes/libraries/stripe/init.php` (variante libre) et
`includes/libraries/stripe/init.php` (variante commerciale).

## 3. Capacités exigées

Listées dans `RcpEnvironment::REQUIRED_CLASSES`, `REQUIRED_METHODS` et
`REQUIRED_FUNCTIONS`. Toute absence empêche l'enregistrement de la passerelle et
produit un message nommant précisément l'élément manquant — ce qui transforme
une rupture d'API amont en incident diagnostiquable plutôt qu'en erreur fatale.

## 4. Le filet de sécurité : les tests de contrat

`tests/contract/RcpContractTest.php` s'exécute contre l'installation réelle et
vérifie, indépendamment de la variante :

- que `RCP_Payment_Gateway_Stripe` n'est ni finale ni détachée de sa classe
  abstraite — l'héritage est la pierre angulaire de l'ADR-0001 ;
- que chaque méthode surchargée existe, reste non finale, non statique et
  surchargeable ;
- que les capacités `ajax-payment`, `recurring`, `one-time`,
  `gateway-submits-form` et `card-updates` sont toujours déclarées ;
- que le filtre `rcp_payment_gateways` permet toujours d'enregistrer une
  passerelle ;
- que `rcp_stripe_generate_idempotency_key()` est disponible ;
- que le SDK expose `PaymentIntent`, `SetupIntent`, `PaymentMethod`, `Mandate`,
  `Subscription` et `Webhook` ;
- que `RCP_SEPA_STRIPE_API_VERSION` ne dépasse pas la version d'API que le SDK
  embarqué sait décoder.

Ces tests tournent **quotidiennement** en intégration continue, ce qui détecte
une rupture amont avant les utilisateurs.

## 5. Exécuter la suite contre chaque variante

```bash
# Variante libre (par défaut) — utilisée par la CI publique
RCP_VARIANT=free make prepare-tests && make test

# Variante commerciale — archive déposée dans vendor-plugins/
RCP_VARIANT=pro  make prepare-tests && make test
```

L'archive de RCP Pro est un produit commercial : elle n'est jamais versionnée.
`vendor-plugins/` est ignoré par Git, et le job de CI `integration-pro` ne
s'exécute que si le secret de dépôt `RCP_PRO_ARCHIVE_URL` est configuré. Il est
déclaré `continue-on-error` afin qu'une contribution externe, dépourvue d'accès
au secret, ne soit jamais bloquée par son absence.

## 6. Ce qui reste à vérifier sur la variante commerciale

Ces points n'ont pas pu être vérifiés faute d'accès à l'archive commerciale ; le
job `integration-pro` les tranchera dès qu'elle sera fournie.

- [ ] Emplacement réel du SDK Stripe (`includes/` supposé, à confirmer).
- [ ] Présence des mêmes réglages de clés (`stripe_test_secret`, etc.).
- [ ] Comportement des modules complémentaires officiels susceptibles de
      filtrer `rcp_payment_gateways` ou les arguments Stripe.
- [ ] Impact éventuel du système de licence sur le chargement du noyau.
