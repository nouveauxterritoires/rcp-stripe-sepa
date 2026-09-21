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
- Cahier des charges complet (`docs/cahier-des-charges.md`).
- ADR-0001 : stratégie d'intégration à Restrict Content Pro par héritage.
- Environnement Docker : WordPress, MySQL, Restrict Content, WP-CLI, Mailpit, Stripe CLI, stripe-mock.
- Outillage de test : PHPUnit (4 suites), PHPCS (standards WordPress), PHPStan, seuil de couverture.
- Contrôles de sécurité automatisés : détection de secrets, d'IBAN en dur et de fausse complétion.
- Chaîne d'intégration continue GitHub Actions (matrice PHP 7.4 → 8.3, WordPress, RCP).

### Corrigé
- Le client MariaDB de l'image WordPress refusait le certificat auto-signé de MySQL 8 ; la
  vérification TLS est désactivée pour le client en ligne de commande du réseau de développement.
- Les tables de RCP ne sont créées que sur `admin_init` : le provisionnement déclenche désormais ce
  hook, sans quoi aucun niveau d'adhésion ne pouvait être créé.
- La bibliothèque de tests de WordPress est installée dans un volume persistant : `/tmp` ne
  survivait pas d'un conteneur éphémère à l'autre.
- Le contrôle de seuil de couverture échouait silencieusement sur une sortie sans saut de ligne.
