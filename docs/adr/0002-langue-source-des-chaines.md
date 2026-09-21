# ADR-0002 — Chaînes source en anglais, code et documentation en français

- **Statut** : Accepté
- **Date** : 2026-09-21
- **Décideurs** : Mathieu (porteur du projet)

## Contexte

Le plugin a d'abord été écrit intégralement en français : code, commentaires,
documentation, messages de commit et chaînes visibles par l'utilisateur.

WordPress attend en revanche des chaînes source en anglais, traduites ensuite
par des fichiers `.po`/`.mo`. C'est ce que supposent `translate.wordpress.org`,
les outils de la communauté et tout développeur non francophone amené à
intervenir.

Deux options se présentaient :

1. **Garder le français comme langue source**, avec une traduction `en_US`.
2. **Basculer les chaînes visibles en anglais**, avec une traduction `fr_FR`.

## Décision

Option 2 : les chaînes passées aux fonctions de traduction sont en anglais ; la
traduction française est fournie dans `languages/rcp-stripe-sepa-fr_FR.po`.

Le reste du projet demeure en français : noms de variables et de méthodes
exceptés, tous les commentaires, la documentation, les noms de tests et les
messages de commit restent rédigés en français.

## Justification

- C'est l'usage de WordPress, et le seul compatible avec une éventuelle
  publication sur WordPress.org.
- Le coût était borné : 125 chaînes, converties en une passe, et le français
  d'origine est devenu la traduction — rien n'a été perdu.
- Garder deux conventions — français pour le code, anglais pour les chaînes —
  est moins gênant qu'il n'y paraît : les deux ensembles ne se croisent que
  dans les appels aux fonctions de traduction.

## Conséquences

**Positives**
- Le plugin est traduisible par les outils habituels de l'écosystème.
- Un intervenant non francophone peut lire l'interface sans traduction.

**Négatives et mitigations**
- *Un contributeur peut réintroduire une chaîne française par habitude.* Un
  test d'intégration échoue si un `msgid` du modèle contient un caractère
  accenté.
- *Une traduction peut perdre un marqueur de substitution, ce qui casserait un
  `sprintf()` en production.* Un test compare les marqueurs de chaque chaîne
  source avec ceux de sa traduction, formes plurielles comprises.
- *Le `.pot` peut devenir obsolète.* L'intégration continue le régénère et
  échoue s'il diffère de la version versionnée.

## Notes d'implémentation

Les chaînes destinées au JavaScript sont traduites côté serveur puis passées
par `wp_localize_script()`. `wp_set_script_translations()` et
`@wordpress/i18n` ne sont pas employés : ils n'apporteraient rien ici, aucune
chaîne n'étant construite dans le navigateur.
