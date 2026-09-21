# Fixtures de webhooks

Deux origines, à ne pas confondre.

## Fixtures capturées (à privilégier)

Enregistrées depuis un compte Stripe de test réel :

```bash
php bin/webhook.php events --type=payment_intent.succeeded
php bin/webhook.php capture evt_xxx payment-intent-succeeded
```

Elles portent la forme exacte produite par Stripe et constituent la référence.

## Fixtures synthétiques (`synthetic-*`)

Écrites à la main pour permettre de travailler hors ligne, avant que le compte
Stripe ne soit activé. Elles reproduisent la forme documentée des objets mais
**ne proviennent pas de Stripe** : elles ne doivent jamais servir à valider un
comportement d'intégration, seulement à exercer le transport (signature,
idempotence, routage). Chaque fixture capturée doit remplacer son équivalent
synthétique.

## Version d'API des charges utiles

Le champ `api_version` d'un événement est déterminé par **le point de
terminaison Stripe ou le compte**, pas par la version transmise dans les
requêtes du plugin (`RCP_SEPA_STRIPE_API_VERSION`). Les gestionnaires ne
doivent donc jamais supposer une version : ils lisent défensivement, en
acceptant `latest_charge` comme `charges.data[0]`.

Les fixtures synthétiques sont écrites en `2022-11-15`, version du SDK embarqué
par RCP.
