# Conventions de développement

Ce document décrit les conventions appliquées dans `rcp-stripe-sepa`. Il est
rédigé pour être réutilisable tel quel sur un autre plugin WordPress : il suffit
de remplacer les identifiants de la section 1.

---

## 1. Identifiants à adapter

| Élément | Convention | Exemple ici |
|---|---|---|
| Slug du plugin | kebab-case | `rcp-stripe-sepa` |
| Namespace PSR-4 | Pascal_Snake, mappé sur `src/` | `RCP_Stripe_Sepa\` |
| Préfixe des fonctions globales | snake_case | `rcp_stripe_sepa_` |
| Préfixe des hooks | identique aux fonctions | `rcp_stripe_sepa_booted` |
| Préfixe des options | identique aux fonctions | `rcp_stripe_sepa_settings` |
| Préfixe des constantes | UPPER_SNAKE court | `RCP_SEPA_` |
| Text domain | = slug | `rcp-stripe-sepa` |
| Tables personnalisées | `{$wpdb->prefix}` + préfixe court | `wp_rcp_sepa_webhook_events` |

Un seul préfixe par plugin, appliqué partout sans exception : c'est ce que
vérifie la règle `WordPress.NamingConventions.PrefixAllGlobals` de PHPCS.

## 2. Architecture

- **`src/` en PSR-4**, un dossier par domaine métier (`Webhook/`, `Membership/`,
  `Compat/`, `Logging/`, `Support/`, `Admin/`, `Frontend/`).
- **La logique métier est pure.** Les règles de décision ne lisent ni n'écrivent
  rien : ni base, ni option, ni appel réseau. Elles prennent des données en
  argument et renvoient un objet de valeur. C'est ce qui permet de les couvrir
  par des tests unitaires rapides et de les relire sans connaître WordPress.
- **Les objets de valeur sont immuables** : constructeur privé, fabriques
  statiques nommées, accesseurs en lecture seule, aucun setter.
- **Aucune mutation d'un argument.** Une fonction qui reçoit un tableau en
  renvoie une copie modifiée ; elle ne touche jamais l'original. Un test le
  vérifie explicitement.
- **Trois couches distinctes** pour tout traitement : transport (HTTP,
  authentification, idempotence) → décision (logique pure) → application
  (écriture dans WordPress). Chaque couche est testable seule.
- **Détecter les capacités, pas les versions.** Une intégration avec un autre
  plugin vérifie la présence effective des classes, méthodes et fonctions
  utilisées, jamais un numéro de version ni un nom de plugin.
- **Échouer proprement.** Si une dépendance manque, le plugin ne s'enregistre
  pas et affiche un avis d'administration nommant précisément ce qui manque.
  Jamais d'erreur fatale.

## 3. Style de code

- WordPress Coding Standards : `WordPress`, `WordPress-Extra`, `WordPress-Docs`.
- `declare( strict_types = 1 )` en tête de chaque fichier PHP.
- DocBlock complet sur chaque classe, méthode et fonction, **avec une
  description courte** — `@param`/`@return` seuls font échouer PHPCS.
- Fonctions de moins de 50 lignes, fichiers de 200 à 400 lignes (800 maximum).
- Sorties anticipées plutôt qu'imbrications : pas plus de 4 niveaux.
- Constantes nommées plutôt que valeurs magiques.
- Pas de mot réservé comme nom de paramètre (`$object`, `$class`, `$function`).
- Conditions Yoda, tableaux alignés : `phpcbf` s'en charge.

### Les commentaires expliquent *pourquoi*

Un commentaire qui paraphrase le code est du bruit. Un commentaire utile
explique une contrainte externe, un piège constaté, ou la raison d'un choix
non évident — et il cite la source quand il y en a une.

```php
// Mauvais : incrémente le compteur de tentatives
++$attempts;

// Bon :
/*
 * `INSERT IGNORE` échoue silencieusement sur la contrainte d'unicité : c'est
 * ce qui rend la réservation sûre entre deux requêtes concurrentes.
 */
```

## 4. Développement piloté par les tests

**Le cycle est non négociable** : écrire le test, le voir échouer, écrire
l'implémentation, le voir passer, refactorer.

Quatre suites PHPUnit, définies dans `phpunit.xml.dist` :

| Suite | Contexte | Rôle |
|---|---|---|
| `unit` | WordPress mocké (Brain Monkey) | Logique pure, exécution en moins d'une seconde |
| `integration` | WordPress réel | Amorçage, hooks, persistance |
| `contract` | Dépendances réelles | Vérifie que les API tierces utilisées existent toujours |
| *(métier)* | WordPress réel | Une suite par domaine à fort risque |

**Les suites ne partagent pas de processus.** La suite `unit` remplace les
fonctions de WordPress ; les autres les chargent. Les exécuter ensemble fait
échouer Patchwork avec « DefinedTooEarly ». Le fichier d'amorçage doit
**refuser explicitement** une invocation sans `--testsuite`, et la couverture
se fusionne a posteriori avec `phpcov` (`bin/coverage.sh`).

### Tests de contrat

Dès qu'on dépend des internes d'un autre plugin — héritage, hook non
documenté, structure de données — il faut une suite de contrat qui vérifie,
contre l'installation réelle, que ce sur quoi on s'appuie existe toujours :
classe non finale, méthode publique et non statique, filtre appliqué, constante
définie. Exécutée quotidiennement en intégration continue, elle transforme une
rupture amont en incident diagnostiquable avant les utilisateurs.

### Fixtures réelles

Les charges utiles fabriquées à la main mentent. Dès qu'un service externe est
impliqué, il faut capturer ses réponses réelles, les versionner comme fixtures
et les rejouer à chaque exécution. Une fixture synthétique est un pis-aller
temporaire, nommée `synthetic-*` et remplacée dès qu'une capture est possible.

### Interdits

- `markTestSkipped`, `markTestIncomplete`, `.only` : un test qui ne s'exécute
  pas masque toujours une faiblesse de conception. Rendre le test exécutable
  partout — par exemple en lisant la configuration depuis l'environnement
  plutôt que depuis un fichier local.
- `TODO:`, `FIXME:`, branches non implémentées dans le code livré.

Ces interdits sont vérifiés par `bin/scan-placeholders.sh`, bloquant en CI.

### Seuil

80 % de lignes minimum, contrôlé par `bin/check-coverage.sh` sur un rapport
Clover fusionné. La couverture ne peut pas diminuer d'une version à l'autre.

## 5. Sécurité

- **Aucun secret dans le code, les journaux, les réponses HTTP ou les tests.**
  Les secrets se déclarent par constante dans `wp-config.php`, qui prévaut
  toujours sur une option en base.
- **Expurger avant de journaliser** : un `Redactor` masque clés d'API, secrets,
  jetons et données personnelles dans tout message écrit.
- **Authentifier avant de lire** : une charge utile entrante est authentifiée
  (signature HMAC, nonce, capacité) avant toute interprétation métier.
- **Réponses minimales** : un appelant non authentifié n'apprend rien de l'état
  du site. Le détail part dans le journal serveur.
- **Nonce + capacité** sur toute action d'administration ; **nonce + vérification
  de propriété** sur toute action d'un utilisateur sur ses propres données. Le
  contournement de propriété (IDOR) est un test de sécurité à part entière.
- **`$wpdb->prepare()` systématique**, assainissement à l'entrée, échappement à
  la sortie.
- **Idempotence** de tout traitement déclenché par un tiers : un rejeu ne doit
  jamais produire deux effets.
- **Cloisonnement test / production** explicite, vérifié sur chaque entrée.
- `bin/scan-secrets.sh` bloque en CI : clés, secrets, données bancaires en dur,
  appels interdits. Distinguer le **code livré** (règle stricte) du reste du
  dépôt (outillage et tests, où des données de test documentées sont admises,
  par liste blanche).

Toute exigence de sécurité porte un identifiant (`SEC-xx`) et un test qui la
couvre.

## 6. Documentation

- **En français**, dans `docs/`, au fil du développement — pas à la fin.
- Un document par sujet, structuré, avec des tableaux plutôt que des listes à
  puces quand il s'agit de comparer.
- Les décisions d'architecture dans `docs/adr/NNNN-titre.md` : contexte,
  options envisagées, décision, justification, conséquences positives et
  négatives avec leurs mitigations, conditions de révision.
- **Consigner les constats empiriques.** Chaque comportement surprenant d'une
  dépendance est documenté avec la preuve qui l'établit, et référencé depuis le
  code qui le contourne. C'est ce qui évite qu'un successeur « simplifie » une
  précaution qu'il ne comprend pas.
- `README.md` : état réel du projet, démarrage en trois commandes, table des
  documents. Ne jamais y annoncer une fonctionnalité non livrée.
- `CHANGELOG.md` au format Keep a Changelog, rubriques *Ajouté*, *Modifié*,
  *Corrigé*.

## 7. Environnement et outillage

Reproductible en une commande, via Docker Compose :

- WordPress + MySQL + base de test séparée, en volume pour survivre aux
  conteneurs éphémères ;
- une seule image partagée par les services applicatifs, pour qu'un rebuild
  n'en laisse jamais un sur une version périmée ;
- provisionnement **idempotent** par script (`bin/setup.sh`), relançable sans
  détruire l'existant ;
- capture des e-mails, bouchons des services externes ;
- ports paramétrables par `.env`, jamais en dur.

`Makefile` avec cible `help` auto-documentée. **L'aide décrit ce que la cible
fait réellement** : une cible qui annonce un outil qu'elle n'exécute pas est un
mensonge. Aucune cible ne doit référencer un script inexistant.

Fichiers à reprendre tels quels, en adaptant les préfixes :
`phpcs.xml.dist`, `phpstan.neon.dist`, `phpunit.xml.dist`, `.editorconfig`,
`bin/check-coverage.sh`, `bin/coverage.sh`, `bin/scan-secrets.sh`,
`bin/scan-placeholders.sh`, `bin/install-wp-tests.sh`, `bin/build.sh`.

PHPStan niveau 5 minimum avec `szepeviktor/phpstan-wordpress`, et des **stubs**
sous `stubs/` pour les dépendances fournies à l'exécution. Ne jamais faire taire
une erreur par `@phpstan-ignore`, un cast ou un élargissement de type : écrire
le stub manquant ou corriger la cause.

## 8. Intégration continue

Jobs : `lint` (PHPCS + PHPStan), `security` (`composer audit` + scanners),
`unit` sur la matrice PHP, `integration` sur la matrice WordPress, `coverage`
avec contrôle de seuil, `i18n` vérifiant que le `.pot` est à jour.

Un déclencheur quotidien exécute les tests de contrat contre la dernière version
des dépendances.

Les dépendances propriétaires ne sont jamais versionnées : `.gitignore` bloque
leurs répertoires et archives, et le job qui en a besoin ne s'exécute que si un
secret de dépôt le permet, en `continue-on-error` pour ne pas bloquer une
contribution externe.

## 9. Internationalisation

Toutes les chaînes visibles passent par les fonctions de traduction avec le text
domain du plugin ; aucune concaténation. Les messages d'erreur d'un service
externe sont traduits par une table de correspondance de codes, avec repli sur
le message d'origine si le code est inconnu. Le `.pot` est généré en CI et sa
fraîcheur vérifiée.

## 10. Git

- Messages de commit en français, format conventionnel : `feat:`, `fix:`,
  `refactor:`, `docs:`, `test:`, `chore:`, `perf:`, `ci:`.
- Le corps explique **ce que le changement résout et pourquoi**, pas ce que le
  diff montre déjà. Mentionner les constats qui ont motivé le choix.
- **Ne jamais mentionner Claude, Claude Code ou Anthropic** dans un message de
  commit ou une description de pull request : ni `Co-Authored-By`, ni
  `Generated with`, ni lien de session.
- Vérifier ce qui part : `git add -A` peut absorber des répertoires non voulus.
  Contrôler `git status` avant, et le nombre de fichiers du commit après.

## 11. Méthode de travail

- **La preuve prime sur l'hypothèse.** Avant d'écrire du code contre une
  dépendance, lire sa source ou interroger son API. Chaque affirmation
  technique du cahier des charges cite ce qui l'établit.
- **Vérifier en exécutant.** Une fonctionnalité n'est pas livrée parce que le
  code existe : la commande a tourné, la suite est verte, la requête HTTP a
  renvoyé le code attendu.
- **Un test qui échoue est une information.** Avant de l'assouplir, chercher ce
  qu'il révèle. Plusieurs défauts réels de ce projet ont été trouvés ainsi.
- **Corriger la cause, pas le symptôme.** Filtrer un avertissement dans la
  sortie d'une commande, c'est masquer un défaut de configuration.
- **Signaler ce qui n'a pas pu être vérifié**, plutôt que de le présenter comme
  acquis. Tenir une liste des points ouverts dans la documentation concernée.
