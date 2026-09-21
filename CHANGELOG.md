# Journal des modifications

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).
Versionnement : [SemVer](https://semver.org/lang/fr/).

## [Non publié]

### Ajouté
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
