# Journal des modifications

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).
Versionnement : [SemVer](https://semver.org/lang/fr/).

## [Non publié]

### Ajouté
- Bascule d'une adhésion de la carte vers le prélèvement SEPA, depuis « Mon compte » : bouton dans
  les actions de l'adhésion, formulaire de mandat, et application immédiate — un SetupIntent SEPA
  aboutit sans délai, aucun fonds ne circulant.
- Prix et date de prochaine échéance préservés, invariants vérifiés sur un compte Stripe réel.
- Quatre contrôles d'accès par requête : session, nonce, propriété de l'adhésion et rattachement de
  l'intention au client Stripe. Une adhésion inexistante et une adhésion d'autrui produisent le même
  message, pour empêcher toute énumération.
- Règles d'éligibilité isolées dans une classe purement fonctionnelle, entièrement couverte.
- Fabrique de données de test partagée, remplaçant la duplication de cinq jeux de fixtures.
- Passerelle de paiement `stripe_sepa`, dérivée de la passerelle Stripe de RCP, coexistant avec la
  passerelle carte native sur le même site et avec les mêmes clés API.
- Formulaire de collecte du mandat : Stripe Element pour l'IBAN, nom du titulaire, texte de mandat
  filtrable portant les mentions obligatoires, messages d'erreur annoncés aux lecteurs d'écran.
- Création des intentions SEPA : PaymentIntent lorsqu'il y a un montant à encaisser, SetupIntent
  sinon, mandat réutilisable pour les seules adhésions reconductibles.
- Finalisation d'inscription propre au prélèvement : le paiement reste en attente, l'adhésion n'est
  pas activée, l'abonnement démarre à l'expiration de la période déjà réglée.
- Persistance du mandat en métadonnées d'adhésion, avec preuve de consentement horodatée et purge
  de l'adresse d'acceptation après treize mois.
- Tests de contrat pilotant la passerelle contre `stripe-mock`.
- Point de terminaison REST des webhooks (`/wp-json/rcp-stripe-sepa/v1/webhook`) : vérification de
  signature HMAC sur la charge utile brute, tolérance temporelle de 300 s, contrôle de cohérence du
  mode, limitation de débit et réponses minimales.
- Journal d'événements assurant l'idempotence, avec empreinte de la charge utile, compteur de
  tentatives, abandon au-delà de cinq essais et purge quotidienne.
- Machine à états du cycle de vie des adhésions, dont la distinction entre le faux
  `invoice.payment_failed` émis à la création d'un abonnement et un impayé réel.
- Résolution de l'adhésion par métadonnée, abonnement ou client Stripe.
- Expurgation des journaux (clés, secrets, IBAN) avant écriture.
- 102 tests supplémentaires, dont le rejeu des treize charges utiles capturées sur un compte
  Stripe réel.
- Amorçage du plugin : en-tête WordPress, autoloader, désactivation propre sans erreur fatale
  lorsque Restrict Content Pro est absent ou incompatible.
- `Compat\RcpEnvironment` : détection de l'environnement RCP par capacités — compatible avec la
  variante libre et la variante commerciale, gestion du mode legacy et du chargement paresseux du
  SDK Stripe.
- `Compat\RequirementsNotice` : messages d'incompatibilité actionnables dans l'administration.
- Suites de tests : 27 unitaires, 5 d'intégration, 22 de contrat — 90 % de couverture de lignes.
- Documentation de la stratégie de compatibilité (`docs/compatibilite-rcp.md`).
- Couverture fusionnée entre suites (`bin/coverage.sh`) et contrôle de seuil.
- Job d'intégration continue dédié à Restrict Content Pro, déclenché par secret de dépôt.
- Outillage console des webhooks (`bin/webhook.php`) : génération d'un secret local, rejeu de
  fixtures signées hors ligne, rejeu et capture d'événements Stripe réels, envois volontairement
  invalides (mauvaise signature, signature absente, horodatage antidaté).
- Fixtures de webhooks SEPA et documentation associée (`docs/webhooks-en-local.md`).
- Outillage du compte Stripe de test (`bin/stripe.php`) : diagnostic d'environnement, création de
  parcours SEPA (paiement unique et abonnement récurrent) sur les IBAN de test, nettoyage.
- Fixtures de webhooks capturées sur un compte Stripe réel, et enseignements consignés dans
  `docs/environnement-stripe-test.md`.
- Script de construction de l'archive distribuable (`bin/build.sh`).
- Cahier des charges complet (`docs/cahier-des-charges.md`).
- ADR-0001 : stratégie d'intégration à Restrict Content Pro par héritage.
- Environnement Docker : WordPress, MySQL, Restrict Content, WP-CLI, Mailpit, Stripe CLI, stripe-mock.
- Outillage de test : PHPUnit (4 suites), PHPCS (standards WordPress), PHPStan, seuil de couverture.
- Contrôles de sécurité automatisés : détection de secrets, d'IBAN en dur et de fausse complétion.
- Chaîne d'intégration continue GitHub Actions (matrice PHP 7.4 → 8.3, WordPress, RCP).

### Modifié
- Le contrôle statique des IBAN distingue le code livré, où aucun IBAN n'est toléré, et le reste du
  dépôt, où seuls les IBAN de test publiés par Stripe sont admis. Les contrôles de secrets couvrent
  désormais l'outillage et les tests.

### Corrigé
- `rcp_subscription_details_action_links` est une action et non un filtre : le bouton de migration
  n'apparaissait pas. Les tests appelaient `apply_filters()` et validaient donc l'hypothèse plutôt
  que la réalité ; ils empruntent désormais le même chemin que le gabarit de RCP.
- `AccountPage` déréférençait le client RCP sans vérifier son existence, ce qui aurait produit une
  erreur fatale pour un utilisateur sans client.
- La passerelle déclare les trois propriétés que RCP affecte sans les déclarer : PHP 8.2 dépréciait
  leur création dynamique à chaque inscription.
- Les abonnements sont créés avec `items[].price` et non le paramètre `plan`, déprécié et refusé par
  la spécification actuelle de l'API — écart révélé par les tests de contrat.
- La détection de variante reposait sur `active_plugins`, non renseignée lorsque RCP est chargé par
  un must-use plugin, un harnais de tests ou un bootstrap applicatif : elle repose désormais sur
  `RCP_PLUGIN_DIR`, défini par RCP à partir du fichier réellement chargé. Défaut révélé en exécutant
  la suite contre Restrict Content Pro 3.5.51.
- Le client MariaDB de l'image WordPress refusait le certificat auto-signé de MySQL 8 ; la
  vérification TLS est désactivée pour le client en ligne de commande du réseau de développement.
- Les tables de RCP ne sont créées que sur `admin_init` : le provisionnement déclenche désormais ce
  hook, sans quoi aucun niveau d'adhésion ne pouvait être créé.
- La bibliothèque de tests de WordPress est installée dans un volume persistant : `/tmp` ne
  survivait pas d'un conteneur éphémère à l'autre.
- Le contrôle de seuil de couverture échouait silencieusement sur une sortie sans saut de ligne.
- Xdebug était déclaré deux fois, et son avertissement polluait la sortie de tous les scripts en
  ligne de commande.
- Les services `wordpress` et `wpcli` construisaient deux images distinctes : un rebuild pouvait
  laisser l'une des deux périmée. Elles partagent désormais un seul tag.
- Le secret de webhook n'atteignait pas `wp-config.php` sur un volume existant, l'entrypoint de
  l'image ne régénérant pas le fichier ; il est posé par `wp config set` au provisionnement.
