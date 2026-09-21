# Migration d'une adhésion de la carte vers le prélèvement SEPA

## 1. Ce que la migration change, et ce qu'elle ne change pas

| | |
|---|---|
| Moyen de paiement de l'abonnement Stripe | **Remplacé** par le nouveau mandat |
| Passerelle de l'adhésion RCP | `stripe` → `stripe_sepa` |
| Prix | **Inchangé** |
| Date de prochaine échéance | **Inchangée** |
| Statut de l'adhésion | **Inchangé** — elle reste active, sans interruption |
| Ancienne carte | Conservée chez Stripe, simplement plus utilisée par défaut |

Ces deux invariants — prix et échéance — ont été vérifiés sur un compte Stripe
réel avant d'être codés : la mise à jour de l'abonnement avec
`proration_behavior: none` laisse `current_period_end` et le montant intacts,
et l'abonnement reste `active`.

## 2. Pourquoi la bascule est immédiate

Un SetupIntent SEPA passe à `succeeded` **dès sa confirmation** : aucun fonds ne
circule, seul un mandat est créé. Contrairement à une inscription, il n'y a donc
pas d'attente de plusieurs jours, et l'adhérent voit l'effet sans délai.

## 3. Le parcours

```
« Mon compte »
    │
    ├─ bouton « Passer au prélèvement SEPA »  (si l'adhésion est éligible)
    │
    ├─ formulaire : titulaire + IBAN (Stripe Element) + texte du mandat
    │
    ├─ AJAX « start »   ─▶ contrôles d'accès ─▶ SetupIntent SEPA ─▶ client_secret
    │
    ├─ confirmSepaDebitSetup()  ──▶ Stripe : mandat créé, intention succeeded
    │
    └─ AJAX « complete » ─▶ contrôles d'accès
                          ─▶ garde-fous sur l'intention
                          ─▶ abonnement Stripe basculé
                          ─▶ mandat persisté
                          ─▶ passerelle de l'adhésion mise à jour
```

## 4. Éligibilité

La bascule n'est proposée que si toutes ces conditions sont réunies :

| Condition | Pourquoi |
|---|---|
| Adhésion `active` | Migrer une adhésion en attente changerait le moyen de paiement d'un prélèvement déjà engagé ; migrer une adhésion résiliée n'a pas d'objet |
| Passerelle `stripe` ou `stripe_sepa` | Un paiement manuel ou par un autre prestataire n'a pas de client Stripe à rattacher |
| Client Stripe rattaché | Le mandat doit appartenir à quelqu'un |
| Devise EUR | Le prélèvement SEPA n'existe qu'en euros |
| Échéance à venir | Sans renouvellement, changer de moyen de paiement serait sans effet |

La passerelle SEPA figure dans la liste : un adhérent qui change de banque doit
pouvoir fournir un nouvel IBAN sans résilier son adhésion.

## 5. Contrôles d'accès

Chaque requête franchit quatre contrôles avant toute écriture :

1. **Session WordPress** — aucune variante `nopriv` n'est déclarée.
2. **Nonce** — lié à l'action `rcp_stripe_sepa_migration`.
3. **Propriété de l'adhésion** — l'identifiant est un entier séquentiel ; sans
   ce contrôle, n'importe quel adhérent pourrait changer le moyen de paiement
   d'un autre. Les tentatives sont journalisées.
4. **Rattachement de l'intention** — l'intention transmise doit appartenir au
   client Stripe de l'adhésion, sinon le mandat d'un tiers pourrait y être
   rattaché.

Une adhésion inexistante et une adhésion appartenant à autrui produisent
**exactement le même message** : les distinguer permettrait d'énumérer les
adhésions existantes.

L'intention est ensuite contrôlée dans cet ordre : rattachement au client,
puis statut `succeeded`, puis présence d'un moyen de paiement, puis type
`sepa_debit`. Le rattachement passe en premier — un appelant qui ne possède pas
l'adhésion n'a pas à apprendre l'état d'une intention.

## 6. Un piège de l'API de RCP

`rcp_subscription_details_action_links` **est une action, pas un filtre**,
malgré son nom et malgré la signature `( $links, $membership )` de sa
documentation. RCP l'invoque par `do_action()` pour laisser une extension
écrire du HTML dans la colonne « Actions ». Un filtre qui renverrait un tableau
enrichi n'aurait aucun effet.

Le défaut n'a pas été vu par les tests, qui appelaient `apply_filters()` — et
validaient donc l'hypothèse plutôt que la réalité. Il a été découvert en
regardant la page dans un navigateur. Les tests empruntent désormais le même
chemin que le gabarit : `do_action()` avec capture de la sortie.

## 7. Points d'extension

| Hook | Type | Usage |
|---|---|---|
| `rcp_stripe_sepa_migrated` | action | Réagir à une bascule réussie |
| `rcp_stripe_sepa_mandate_saved` | action | Réagir à l'enregistrement du mandat |
| `rcp_stripe_sepa_mandate_text` | filtre | Personnaliser le texte du mandat |
