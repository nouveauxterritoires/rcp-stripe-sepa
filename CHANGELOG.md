# Journal des modifications

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).
Versionnement : [SemVer](https://semver.org/lang/fr/).

## [1.0.0-rc.1] — 2026-09-22

Candidate à la version 1.0.0. Le code est complet et vérifié — 456 tests PHP,
17 parcours de bout en bout, 83,7 % de couverture de lignes, et les 64 exigences
du cahier des charges toutes établies — mais aucun prélèvement réel n'a encore
été encaissé : la recette de l'annexe C.4 exige un IBAN réel et un compte en
production. C'est le seul écart qui sépare cette version d'une 1.0.0.

### Ajouté
- Suite de bout en bout Playwright : inscription récurrente, activation par webhook, bascule depuis
  la carte et adhésion à vie, jouées sur le site Docker réel avec Stripe en mode test.
- Matrice de traçabilité exigences ↔ tests (`docs/traceability.md`), générée depuis les annotations
  `@group` de PHPUnit. Chaque exigence du cahier des charges est donc rejouable isolément —
  `vendor/bin/phpunit --group RG-01` — et l'intégration continue refuse une exigence sans preuve.
- Garde-fou de cohérence des modes : une clé Stripe de production employée alors que le bac à sable
  est actif retire la passerelle, et inversement. Un avis d'administration en explique la cause.
- Bandeau non masquable signalant le mode test, à l'inscription, à la bascule et dans
  l'administration : un formulaire de paiement en mode test est autrement indiscernable d'un
  formulaire réel.
- Champ de réglage du secret de webhook, masqué et jamais pré-rempli — le laisser vide conserve la
  valeur en place — et en lecture seule lorsqu'une constante de `wp-config.php` prévaut.
- Annexe C du cahier des charges refondue en runbook de mise en production : six phases ordonnées,
  de l'habilitation SEPA du compte jusqu'à la surveillance des fenêtres de rejet à huit semaines et
  treize mois, chaque point indiquant comment le vérifier, et un retour arrière écrit d'avance.
- Internationalisation complète : modèle `.pot`, traduction française compilée, et tests vérifiant
  la couverture de la traduction ainsi que l'intégrité des marqueurs de substitution.
- Exportateur et effaceur de données personnelles branchés sur les outils de WordPress : un adhérent
  obtient son mandat par la procédure standard, et sa suppression efface les métadonnées locales.
- L'effacement signale explicitement que les données détenues par Stripe ne sont pas supprimées,
  les obligations comptables primant sur le droit à l'effacement.
- Mention proposée à la politique de confidentialité du site.
- Purge quotidienne des adresses de signature de mandat au-delà de treize mois.
- Écran « Restrict › Prélèvement SEPA » : état de la configuration, URL du point de terminaison,
  vingt derniers événements reçus et rejeu manuel de l'un d'eux.
- Rejeu d'événement relisant la charge utile auprès de Stripe plutôt qu'une copie locale, avec
  contrôle de cohérence du mode.
- Remontée des anomalies dans « Outils › Santé du site ».
- Affichage du mandat sur la fiche d'adhésion, avec liens vers le tableau de bord Stripe dans le
  mode courant, et sur la page « Mon compte » de l'adhérent.
- E-mails transactionnels propres au prélèvement : attente, refus, nouveau mandat, et alertes
  d'administration pour les litiges et les événements abandonnés. L'encaissement réussi n'envoie
  rien, RCP émettant déjà son e-mail d'activation.
- Guide d'exploitation (`docs/exploitation.md`).
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
- Les chaînes visibles par l'utilisateur passent du français à l'anglais, conformément aux usages de
  WordPress ; le français d'origine devient la traduction `fr_FR`. Le code, les commentaires et la
  documentation restent en français (ADR-0002).
- Le contrôle statique des IBAN distingue le code livré, où aucun IBAN n'est toléré, et le reste du
  dépôt, où seuls les IBAN de test publiés par Stripe sont admis. Les contrôles de secrets couvrent
  désormais l'outillage et les tests.

### Corrigé
- **Révoquer un accès s'écrit `expired`, jamais `cancelled`.** `RCP_Membership::is_active()` tient
  pour actives les adhésions `cancelled` dont l'échéance n'est pas passée : une adhésion résiliée
  garde l'accès jusqu'au terme de la période qu'elle a réglée. Trois transitions laissaient donc le
  contenu ouvert alors que rien n'avait été encaissé, ou que les fonds avaient été repris : mandat
  refusé, litige révoqué et remboursement total. Ce dernier divergeait en outre de Restrict Content
  Pro, qui appelle `expire()`. Défaut révélé par un test de bout en bout.
- La clé secrète Stripe n'était armée nulle part hors d'une passerelle RCP. La bascule carte → SEPA
  et le rejeu d'événement partaient donc sans clé : l'un comme l'autre étaient inopérants.
- Stripe refuse d'émettre un mandat SEPA sans adresse de contact ; la bascule ne la transmettait
  pas, ce qui la rendait totalement inopérante.
- Le gestionnaire JavaScript d'inscription lisait la mauvaise signature d'événement : RCP transmet
  `(form, response)` et conclut par `rcp_submit_registration_form()`.
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
