# Politique de sécurité

## Signaler une vulnérabilité

Les vulnérabilités ne doivent **pas** être signalées via une issue publique.
Contact : le référent technique du projet, par un canal privé.

Merci d'inclure : version du plugin, version de WordPress et de RCP, étapes de reproduction, impact
estimé. Un accusé de réception est envoyé sous 72 heures.

## Principes appliqués

| Principe | Mise en œuvre |
|---|---|
| Les données bancaires ne touchent pas le serveur | Saisie de l'IBAN dans un Stripe Element (iframe Stripe) ; seuls les 4 derniers caractères sont conservés |
| Les webhooks sont authentifiés | Vérification de la signature HMAC Stripe sur la charge utile brute, tolérance 300 s |
| Les traitements sont idempotents | Verrou d'unicité sur `event_id` ; clés d'idempotence sur les appels Stripe |
| Les secrets ne sont pas en base | Constantes `wp-config.php` prioritaires sur les options ; masquage systématique |
| Les modes sont cloisonnés | Contrôle `livemode` ; refus de démarrage si une clé `sk_live_` est utilisée en mode test |
| Les entrées et sorties sont contrôlées | Assainissement à l'entrée, échappement à la sortie, `$wpdb->prepare()` systématique |
| Les accès sont vérifiés | Capacité + nonce pour l'administration ; propriété de l'adhésion + nonce pour les membres |

## Checklist de revue avant publication

Voir l'annexe D du [cahier des charges](docs/cahier-des-charges.md).

## Contrôles automatisés

- `bin/scan-secrets.sh` — clés Stripe, secrets de webhook, IBAN en dur, `setApiVersion()`.
- `bin/scan-placeholders.sh` — tests ignorés, marqueurs de substitution.
- `composer audit` — vulnérabilités des dépendances (bloquant en haute et critique).

Ces contrôles sont exécutés à chaque `push` et `pull request`.
