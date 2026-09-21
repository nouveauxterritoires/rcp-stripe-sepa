# Journal des modifications

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).
Versionnement : [SemVer](https://semver.org/lang/fr/).

## [Non publié]

### Ajouté
- Cahier des charges complet (`docs/cahier-des-charges.md`).
- ADR-0001 : stratégie d'intégration à Restrict Content Pro par héritage.
- Environnement Docker : WordPress, MySQL, Restrict Content, WP-CLI, Mailpit, Stripe CLI, stripe-mock.
- Outillage de test : PHPUnit (4 suites), PHPCS (standards WordPress), PHPStan, seuil de couverture.
- Contrôles de sécurité automatisés : détection de secrets, d'IBAN en dur et de fausse complétion.
- Chaîne d'intégration continue GitHub Actions (matrice PHP 7.4 → 8.3, WordPress, RCP).
