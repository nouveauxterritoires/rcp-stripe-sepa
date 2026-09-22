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
| Version vérifiée | 4.0.4 (`RCP_PLUGIN_VERSION` = 4.0.7) | 3.5.51 (`RCP_PLUGIN_VERSION` = 3.5.51) |
| Noyau RCP | `core/` | `core/` — identique |
| SDK Stripe | `core/includes/libraries/stripe/` (10.3.0) | même chemin, même version |
| Passerelle Stripe | `core/includes/gateways/` | même chemin |
| Version d'API forcée | `2020-08-27` | `2020-08-27` — identique |
| Text domain | `rcp` | `rcp` |

Les deux définissent la même classe `Restrict_Content_Pro` et les mêmes
constantes (`RCP_PLUGIN_VERSION`, `RCP_PLUGIN_DIR`, `RCP_PLUGIN_URL`). La
variante libre y ajoute `RCF_VERSION`, `RCP_ROOT` et `RCP_WEB_ROOT`.

## 2. Principe retenu : détecter les capacités, pas les versions

`RCP_Stripe_Sepa\Compat\RcpEnvironment` n'autorise jamais le démarrage sur la
foi d'un nom de plugin ou d'un numéro de version. Trois constats l'imposent.

### 2.0 La variante se déduit du répertoire chargé

`RCP_PLUGIN_DIR` est défini par RCP à partir du fichier réellement chargé :
c'est le seul signal toujours disponible. `active_plugins` ne l'est pas —
RCP peut être chargé par un must-use plugin, un harnais de tests ou un
bootstrap applicatif — et une entrée résiduelle y survit à une bascule d'une
variante à l'autre.

L'ordre est donc : répertoire de `RCP_PLUGIN_DIR`, puis `active_plugins`, puis
la constante `RCF_VERSION`, que seule la variante libre définit — la variante
commerciale la **lit** (dans son diagnostic système et sa télémétrie) sans
jamais la définir.

RCP expose bien une méthode `Restrict_Content_Pro::is_pro()`, mais elle déduit
la variante de la présence d'une action sur `admin_menu` : elle dépend du
moment de l'appel et du contexte d'administration. Le répertoire est plus sûr.

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

Le chemin du SDK est cherché à deux emplacements. Les deux variantes utilisent
en réalité `core/includes/libraries/stripe/init.php` — vérifié sur RCP Pro
3.5.51 — mais `includes/libraries/stripe/init.php` reste interrogé au cas où
une version antérieure ou ultérieure déplacerait le noyau.

## 3. Capacités exigées

Listées dans `RcpEnvironment::REQUIRED_CLASSES`, `REQUIRED_METHODS` et
`REQUIRED_FUNCTIONS`. Toute absence empêche l'enregistrement de la passerelle et
produit un message nommant précisément l'élément manquant — ce qui transforme
une rupture d'API amont en incident diagnostiquable plutôt qu'en erreur fatale.

## 4. Le filet de sécurité : les tests de contrat

`tests/Contract/RcpContractTest.php` s'exécute contre l'installation réelle et
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

# Variante commerciale, depuis une archive déposée dans vendor-plugins/
RCP_VARIANT=pro make prepare-tests && make test

# Variante commerciale, depuis des sources déjà décompressées
RCP_VARIANT=pro RCP_PRO_SOURCE=/chemin/vers/restrict-content-pro \
  make prepare-tests && make test
```

Les deux variantes ne peuvent pas coexister : elles définissent la même classe
et les mêmes constantes. L'installation de l'une retire donc l'autre.

L'archive de RCP Pro est un produit commercial : elle n'est jamais versionnée.
`vendor-plugins/` est ignoré par Git, et le job de CI `integration-pro` ne
s'exécute que si le secret de dépôt `RCP_PRO_ARCHIVE_URL` est configuré. Il est
déclaré `continue-on-error` afin qu'une contribution externe, dépourvue d'accès
au secret, ne soit jamais bloquée par son absence.

## 6. Vérifications effectuées sur la variante commerciale

Suite exécutée contre **Restrict Content Pro 3.5.51** : 155 tests au vert,
dont les 27 tests de contrat.

- [x] Emplacement du SDK Stripe : `core/includes/libraries/stripe/`, identique
      à la variante libre. L'hypothèse initiale (`includes/`) était fausse ; le
      code interrogeait déjà les deux chemins.
- [x] Version du SDK embarqué : 10.3.0, identique.
- [x] Réglages de clés : `stripe_test_secret`, `stripe_test_publishable`,
      `stripe_live_secret`, `stripe_live_publishable` — identiques.
- [x] Version d'API forcée globalement : `2020-08-27`, identique. Les règles
      R-API-1 à R-API-3 s'appliquent donc de la même façon.
- [x] `RCP_Payment_Gateway_Stripe` n'est pas finale et hérite bien de
      `RCP_Payment_Gateway`.
- [x] Les douze capacités attendues sont déclarées, dont `ajax-payment`.
- [x] Le filtre `rcp_payment_gateways` est appliqué au même endroit.
- [x] `RCF_VERSION` n'est jamais définie par la variante commerciale.

**Une faiblesse a été révélée par cette exécution** : la détection de variante
reposait sur `active_plugins`, non renseignée dans le harnais de tests, et
renvoyait « inconnue » alors que RCP Pro était bien chargé. La détection repose
désormais sur `RCP_PLUGIN_DIR` (§2.0).

### Reste à vérifier

- [ ] Comportement des modules complémentaires officiels susceptibles de
      filtrer `rcp_payment_gateways` ou les arguments Stripe.
- [ ] Impact du système de licence sur le chargement du noyau, sur un site
      dont la licence est expirée.
