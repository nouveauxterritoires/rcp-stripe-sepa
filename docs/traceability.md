# Traçabilité des exigences

> Document généré par `bin/traceability.php`. Ne pas modifier à la main.

Chaque exigence du [cahier des charges](cahier-des-charges.md) est rattachée aux tests
qui l'établissent, par l'annotation `@group` de PHPUnit. Une exigence est donc
rejouable isolément :

```bash
vendor/bin/phpunit --group RG-01
```

**64 exigences sur 64 sont établies.**

## Exigences fonctionnelles

| Exigence | Énoncé | Établie par |
|---|---|---|
| **F-01** | Nouvelle passerelle de paiement RCP stripe_sepa, activable dans les réglages RCP | `GatewayRegistrationTest::test_la_passerelle_est_declaree_a_rcp` — tests/Integration/GatewayRegistrationTest.php<br>`GatewayRegistrationTest::test_la_passerelle_est_activable_dans_les_reglages` — tests/Integration/GatewayRegistrationTest.php<br>« le prélèvement SEPA est proposé à côté de la carte » — tests/e2e/inscription-sepa.spec.js |
| **F-02** | Inscription à une adhésion récurrente payée par prélèvement SEPA (mandat + abonnement S… | `GatewayStripeTest::test_une_adhesion_reconductible_cree_un_abonnement` — tests/Contract/GatewayStripeTest.php<br>« une inscription laisse l\ » — tests/e2e/inscription-sepa.spec.js |
| **F-03** | Inscription à une adhésion à vie / paiement unique par PaymentIntent SEPA | `IntentFactoryTest::test_une_adhesion_a_vie_n_enregistre_pas_de_mandat_recurrent` — tests/Unit/Gateway/IntentFactoryTest.php<br>`GatewayStripeTest::test_une_adhesion_non_reconductible_ne_cree_pas_d_abonnement` — tests/Contract/GatewayStripeTest.php |
| **F-04** | Gestion de l'état intermédiaire « paiement en cours de traitement » propre à SEPA | `StateMachineTest::test_un_prelevement_en_cours_laisse_l_adhesion_en_attente` — tests/Unit/Membership/StateMachineTest.php<br>`MembershipLifecycleTest::test_un_prelevement_en_cours_laisse_l_adhesion_en_attente` — tests/Webhooks/MembershipLifecycleTest.php<br>« l\ » — tests/e2e/webhook-activation.spec.js |
| **F-05** | Migration du moyen de paiement d'une adhésion existante : carte → SEPA (et SEPA → SEPA) | `MigrationStripeTest::test_une_intention_du_bon_client_bascule_l_adhesion` — tests/Contract/MigrationStripeTest.php<br>« la bascule remplace le moyen de paiement sans toucher à l\ » — tests/e2e/migration-sepa.spec.js |
| **F-06** | Traitement des webhooks Stripe dédiés, avec vérification de signature et idempotence | `WebhookEndpointTest::test_une_charge_utile_signee_est_acceptee` — tests/Webhooks/WebhookEndpointTest.php<br>`WebhookEndpointTest::test_un_evenement_rejoue_n_est_traite_qu_une_fois` — tests/Webhooks/WebhookEndpointTest.php |
| **F-07** | Gestion des échecs et des impayés (R-transactions), rejets, litiges | `StateMachineTest::test_une_facture_en_echec_apres_tentative_est_un_impaye` — tests/Unit/Membership/StateMachineTest.php<br>`StateMachineTest::test_un_litige_revoque_l_adhesion` — tests/Unit/Membership/StateMachineTest.php |
| **F-08** | Affichage du mandat (référence, ICS créancier, IBAN masqué) côté membre et côté adminis… | `AdminScreensTest::test_le_mandat_est_affiche_sur_la_fiche_d_adhesion` — tests/Integration/AdminScreensTest.php<br>`AdminScreensTest::test_le_mandat_est_affiche_sur_la_page_du_compte` — tests/Integration/AdminScreensTest.php<br>« le mandat en vigueur est ensuite rappelé à l\ » — tests/e2e/migration-sepa.spec.js |
| **F-09** | Mode bac à sable (test) et mode production, strictement cloisonnés | `AdminScreensTest::test_les_liens_stripe_visent_le_mode_courant` — tests/Integration/AdminScreensTest.php<br>`WebhookEndpointTest::test_un_evenement_de_production_est_ignore_en_mode_test` — tests/Webhooks/WebhookEndpointTest.php |
| **F-10** | E-mails transactionnels spécifiques SEPA (mandat accepté, prélèvement en cours, rejet) | `NotificationsTest::test_un_prelevement_engage_previent_l_adherent_du_delai` — tests/Integration/NotificationsTest.php<br>`NotificationsTest::test_un_prelevement_refuse_previent_l_adherent` — tests/Integration/NotificationsTest.php |
| **F-11** | Internationalisation complète (FR / EN fournis) | `GatewayRegistrationTest::test_la_passerelle_porte_des_libelles_traduits` — tests/Integration/GatewayRegistrationTest.php<br>`TranslationTest::test_la_traduction_couvre_les_chaines_du_modele` — tests/Integration/TranslationTest.php<br>`TranslationTest::test_le_plugin_declare_son_repertoire_de_traductions` — tests/Integration/TranslationTest.php |
| **F-12** | Exportateur / effaceur de données personnelles (RGPD) | `PrivacyTest::test_l_exportateur_est_declare` — tests/Integration/PrivacyTest.php<br>`PrivacyTest::test_l_effaceur_est_declare` — tests/Integration/PrivacyTest.php |
| **F-13** | Écran d'état et de diagnostic (santé de la configuration, derniers webhooks reçus) | `AdminScreensTest::test_l_ecran_s_affiche_pour_un_administrateur` — tests/Integration/AdminScreensTest.php<br>`AdminScreensTest::test_l_ecran_liste_les_evenements_recus` — tests/Integration/AdminScreensTest.php |
| **F-14** | Environnement Docker de développement et de test, et suite de tests automatisés | *La chaîne d'intégration continue elle-même : toute exécution installe la pile et rejoue les quatre suites.* |
| **F-15** | Documentation développeur et documentation administrateur | `SupplyChainTest::test_toute_documentation_citee_existe` — tests/Integration/SupplyChainTest.php<br>`SupplyChainTest::test_la_documentation_administrateur_et_developpeur_est_livree` — tests/Integration/SupplyChainTest.php |

## Règles de gestion

| Exigence | Énoncé | Établie par |
|---|---|---|
| **RG-01** | l'adhésion n'accorde aucun accès au contenu tant que le premier prélèvement n'est | `StateMachineTest::test_un_prelevement_en_cours_laisse_l_adhesion_en_attente` — tests/Unit/Membership/StateMachineTest.php<br>`GatewayStripeTest::test_l_adhesion_n_est_pas_activee_a_l_inscription` — tests/Contract/GatewayStripeTest.php<br>« une inscription laisse l\ » — tests/e2e/inscription-sepa.spec.js<br>« le contenu réservé reste fermé tant que le prélèvement n\ » — tests/e2e/webhook-activation.spec.js |
| **RG-02** | si la devise du niveau d'adhésion n'est pas EUR, la passerelle SEPA n'est pas proposée. | `GatewayRegistrationTest::test_une_devise_autre_que_l_euro_est_refusee` — tests/Integration/GatewayRegistrationTest.php<br>`GatewayStripeTest::test_une_devise_non_euro_interrompt_l_inscription` — tests/Contract/GatewayStripeTest.php |
| **RG-03** | en cas de remise de 100 %, un SetupIntent est utilisé et l'adhésion est activée dès | `StateMachineTest::test_la_politique_optimiste_active_des_le_mandat` — tests/Unit/Membership/StateMachineTest.php<br>`IntentFactoryTest::test_un_montant_nul_impose_une_intention_d_enregistrement` — tests/Unit/Gateway/IntentFactoryTest.php |
| **RG-04** | si le PaymentIntent échoue (payment_intent.payment_failed), le paiement RCP passe | `StateMachineTest::test_un_echec_au_premier_paiement_ferme_l_acces` — tests/Unit/Membership/StateMachineTest.php<br>« un prélèvement refusé laisse le contenu fermé » — tests/e2e/webhook-activation.spec.js |
| **RG-05** | un litige (charge.dispute.created) sur un paiement à vie révoque l'adhésion et | `StateMachineTest::test_un_litige_revoque_l_adhesion` — tests/Unit/Membership/StateMachineTest.php<br>`MembershipLifecycleTest::test_un_litige_revoque_l_adhesion` — tests/Webhooks/MembershipLifecycleTest.php |
| **RG-06** | la migration ne modifie ni le prix, ni la date de prochaine échéance de l'adhésion. | `SubscriptionUpdateTest::test_aucune_proratisation_n_est_demandee` — tests/Unit/Migration/SubscriptionUpdateTest.php<br>« la bascule remplace le moyen de paiement sans toucher à l\ » — tests/e2e/migration-sepa.spec.js |
| **RG-07** | la migration est bloquée si l'adhésion est en pending, expired ou cancelled. | `EligibilityTest::test_une_adhesion_non_active_n_est_pas_eligible` — tests/Unit/Migration/EligibilityTest.php |
| **RG-08** | une facture déjà ouverte (open) au moment de la migration continue d'être réglée par | `SubscriptionUpdateTest::test_aucune_facture_en_cours_n_est_affectee` — tests/Unit/Migration/SubscriptionUpdateTest.php<br>`SubscriptionUpdateTest::test_la_bascule_ne_manipule_aucune_facture` — tests/Unit/Migration/SubscriptionUpdateTest.php |

## Exigences de sécurité

| Exigence | Énoncé | Établie par |
|---|---|---|
| **SEC-01** | les clés secrètes Stripe ne sont jamais écrites dans le code, un fichier versionné, un | `AdminScreensTest::test_le_rapport_ne_contient_aucun_secret` — tests/Integration/AdminScreensTest.php |
| **SEC-02** | les secrets de webhook peuvent être définis par constante dans wp-config.php | `DiagnosticsTest::test_un_secret_stocke_en_base_est_signale` — tests/Unit/Admin/DiagnosticsTest.php |
| **SEC-03** | tout champ de réglage sensible utilise type="password" et autocomplete="off", et | `SecretFieldTest::test_le_champ_est_masque_et_jamais_pre_rempli` — tests/Integration/SecretFieldTest.php<br>`SecretFieldTest::test_l_ecran_ne_revele_jamais_le_secret` — tests/Integration/SecretFieldTest.php<br>`SecretFieldTest::test_un_champ_laisse_vide_conserve_le_secret` — tests/Integration/SecretFieldTest.php<br>`SecretFieldTest::test_un_secret_verrouille_par_constante_refuse_la_saisie` — tests/Integration/SecretFieldTest.php |
| **SEC-04** | les secrets sont exclus de l'exportateur de données personnelles et de toute | `PersonalDataTest::test_aucun_identifiant_technique_n_est_exporte` — tests/Unit/Privacy/PersonalDataTest.php |
| **SEC-05** | uninstall.php supprime les options et métadonnées créées par le plugin, sauf si la | `SupplyChainTest::test_la_desinstallation_efface_les_options_et_la_table` — tests/Integration/SupplyChainTest.php |
| **SEC-06** | la signature est vérifiée avec \Stripe\Webhook::constructEvent( $payload, $sig_header, … | `WebhookSigningTest::test_la_signature_produite_est_acceptee_par_le_sdk_stripe` — tests/Contract/WebhookSigningTest.php<br>`WebhookEndpointTest::test_une_charge_utile_signee_est_acceptee` — tests/Webhooks/WebhookEndpointTest.php |
| **SEC-07** | tolérance temporelle de 300 secondes ; les événements trop anciens sont rejetés. | `WebhookEndpointTest::test_une_signature_antidatee_est_rejetee` — tests/Webhooks/WebhookEndpointTest.php |
| **SEC-08** | le plugin n'utilise pas le listener ?listener=stripe de RCP. Si les deux points | `WebhookEndpointTest::test_le_point_de_terminaison_n_est_pas_celui_de_rcp` — tests/Webhooks/WebhookEndpointTest.php |
| **SEC-09** | contrôle de cohérence livemode ↔ mode configuré (I-4). | `WebhookEndpointTest::test_un_evenement_de_production_est_ignore_en_mode_test` — tests/Webhooks/WebhookEndpointTest.php |
| **SEC-10** | la réponse au webhook ne divulgue aucune information métier (pas d'ID d'adhésion, pas | `WebhookEndpointTest::test_la_reponse_ne_divulgue_aucune_information` — tests/Webhooks/WebhookEndpointTest.php |
| **SEC-11** | limitation de débit du point de terminaison (ex. 120 requêtes/minute par IP) avec | `WebhookEndpointTest::test_la_limitation_de_debit_protege_le_point_de_terminaison` — tests/Webhooks/WebhookEndpointTest.php |
| **SEC-12** | l'IBAN est saisi exclusivement dans un Stripe Element (iframe hébergée par Stripe). | `GatewayRegistrationTest::test_le_formulaire_ne_contient_aucun_champ_iban_natif` — tests/Integration/GatewayRegistrationTest.php<br>`MigrationAccountPageTest::test_le_formulaire_ne_contient_aucun_champ_iban_soumis` — tests/Integration/MigrationAccountPageTest.php<br>« le formulaire SEPA ne contient aucun champ IBAN soumis au serveur » — tests/e2e/inscription-sepa.spec.js |
| **SEC-13** | Logging\Redactor masque, dans tout message journalisé : IBAN, client_secret, | `RedactorTest::test_masque_les_secrets` — tests/Unit/Logging/RedactorTest.php<br>`RedactorTest::test_masque_un_iban` — tests/Unit/Logging/RedactorTest.php |
| **SEC-14** | les données de mandat sont considérées comme des données personnelles au sens du | `PrivacyTest::test_l_export_restitue_le_mandat` — tests/Integration/PrivacyTest.php |
| **SEC-15** | l'IP de signature du mandat est purgée automatiquement après 13 mois (durée de la | `MandateRepositoryTest::test_l_adresse_d_acceptation_est_purgee_apres_la_duree_de_conservation` — tests/Integration/MandateRepositoryTest.php |
| **SEC-16** | toute action d'administration vérifie current_user_can( 'rcp_manage_settings' ) (ou | `AdminScreensTest::test_l_ecran_est_reserve_aux_administrateurs` — tests/Integration/AdminScreensTest.php<br>`AdminScreensTest::test_le_rejeu_est_refuse_sans_droits` — tests/Integration/AdminScreensTest.php |
| **SEC-17** | toute action côté membre (migration de moyen de paiement) vérifie | `MigrationAjaxTest::test_un_adherent_ne_peut_pas_migrer_l_adhesion_d_un_autre` — tests/Integration/MigrationAjaxTest.php<br>`MigrationAjaxTest::test_une_requete_sans_nonce_est_refusee` — tests/Integration/MigrationAjaxTest.php |
| **SEC-18** | toutes les entrées sont assainies (sanitize_text_field, absint, sanitize_email, | `MigrationAccountPageTest::test_un_parametre_inattendu_ne_casse_pas_l_affichage` — tests/Integration/MigrationAccountPageTest.php |
| **SEC-19** | aucune requête SQL concaténée ; $wpdb->prepare() obligatoire, vérifié par PHPCS | *PHPCS, jeu de règles `WordPress.DB.PreparedSQL` — tâche « Standards et analyse statique ».* |
| **SEC-20** | validation IBAN côté serveur uniquement sur le pays et le format lorsqu'un IBAN | `SupplyChainTest::test_aucun_iban_complet_n_est_recu_ni_valide_cote_serveur` — tests/Integration/SupplyChainTest.php |
| **SEC-21** | le mode est unique et hérité de RCP. Le plugin n'introduit pas son propre sélecteur. | `DiagnosticsTest::test_le_mode_courant_est_rapporte` — tests/Unit/Admin/DiagnosticsTest.php |
| **SEC-22** | les secrets de webhook, les événements stockés et les liens Dashboard sont cloisonnés | `AdminScreensTest::test_les_liens_stripe_visent_le_mode_courant` — tests/Integration/AdminScreensTest.php<br>`EventStoreTest::test_le_mode_de_l_evenement_est_conserve` — tests/Webhooks/EventStoreTest.php<br>`WebhookEndpointTest::test_un_evenement_de_production_est_ignore_en_mode_test` — tests/Webhooks/WebhookEndpointTest.php |
| **SEC-23** | en mode test, un bandeau permanent et non masquable est affiché sur le formulaire | `TestBannerTest::test_le_bandeau_s_affiche_en_mode_test` — tests/Unit/Mode/TestBannerTest.php<br>`TestBannerTest::test_aucun_bandeau_hors_mode_test` — tests/Unit/Mode/TestBannerTest.php<br>`TestBannerTest::test_le_bandeau_n_est_pas_masquable` — tests/Unit/Mode/TestBannerTest.php |
| **SEC-24** | un garde-fou refuse le démarrage si une clé sk_live_* est détectée alors que le mode | `ModeGuardTest::test_une_cle_de_production_en_mode_test_bloque_la_passerelle` — tests/Unit/Mode/ModeGuardTest.php<br>`ModeGuardTest::test_une_cle_de_test_en_production_bloque_la_passerelle` — tests/Unit/Mode/ModeGuardTest.php<br>`ModeGuardTest::test_une_cle_restreinte_de_production_est_traitee_comme_une_cle_secrete` — tests/Unit/Mode/ModeGuardTest.php |
| **SEC-25** | composer audit et npm audit exécutés en CI ; échec du build sur vulnérabilité | *`composer audit` et `npm audit` — tâche « Sécurité ».* |
| **SEC-26** | versions des dépendances verrouillées (composer.lock, package-lock.json versionnés). | `SupplyChainTest::test_les_versions_des_dependances_sont_verrouillees` — tests/Integration/SupplyChainTest.php |
| **SEC-27** | aucune ressource tierce chargée depuis un CDN, à l'exception de js.stripe.com | `SupplyChainTest::test_aucune_ressource_tierce_hors_stripe` — tests/Integration/SupplyChainTest.php<br>`SupplyChainTest::test_seul_le_script_de_stripe_est_charge_depuis_un_tiers` — tests/Integration/SupplyChainTest.php |
| **SEC-28** | revue de sécurité obligatoire avant chaque version (checklist en annexe D). | *Acte humain : checklist de l'annexe D, passée avant chaque version.* |

## Exigences de conformité

| Exigence | Énoncé | Établie par |
|---|---|---|
| **CNF-01** | le texte de mandat affiché doit comporter les mentions obligatoires : identité du | `GatewayRegistrationTest::test_le_formulaire_presente_le_mandat` — tests/Integration/GatewayRegistrationTest.php<br>« le mandat est présenté avant la signature » — tests/e2e/inscription-sepa.spec.js |
| **CNF-02** | le consentement doit être explicite et actif — la soumission du formulaire vaut | « le mandat est présenté avant la signature » — tests/e2e/inscription-sepa.spec.js |
| **CNF-03** | la pré-notification du débiteur (montant et date du prélèvement, au moins 2 jours | *Réglage du compte Stripe, hors du code. Vérifié à la mise en production (annexe C.1).* |
| **CNF-04** | le prélèvement SEPA n'est pas soumis à l'authentification forte DSP2 (SCA) — le mandat | `IntentFactoryTest::test_l_intention_n_est_pas_confirmee_cote_serveur` — tests/Unit/Gateway/IntentFactoryTest.php |
| **CNF-05** | enregistrement d'un exportateur (wp_privacy_personal_data_exporters) restituant les | `PrivacyTest::test_l_exportateur_est_declare` — tests/Integration/PrivacyTest.php |
| **CNF-06** | enregistrement d'un effaceur (wp_privacy_personal_data_erasers) supprimant les | `PrivacyTest::test_l_effaceur_est_declare` — tests/Integration/PrivacyTest.php |
| **CNF-07** | mention, dans la documentation administrateur, du transfert de données vers Stripe | `PersonalDataTest::test_la_mention_de_confidentialite_nomme_le_sous_traitant` — tests/Unit/Privacy/PersonalDataTest.php |
| **CNF-08** | durée de conservation documentée pour chaque métadonnée (§7.2). | `PersonalDataTest::test_la_mention_precise_la_duree_de_conservation` — tests/Unit/Privacy/PersonalDataTest.php |

## Idempotence et robustesse

| Exigence | Énoncé | Établie par |
|---|---|---|
| **I-1** | avant tout traitement, insertion de event_id dans la table d'idempotence avec | `EventStoreTest::test_une_reception_deja_traitee_est_un_doublon` — tests/Webhooks/EventStoreTest.php<br>`WebhookEndpointTest::test_un_evenement_rejoue_n_est_traite_qu_une_fois` — tests/Webhooks/WebhookEndpointTest.php<br>« un événement rejoué n\ » — tests/e2e/webhook-activation.spec.js |
| **I-2** | toute création d'objet Stripe utilise une clé d'idempotence déterministe | `RcpContractTest::test_la_generation_de_cle_d_idempotence_est_disponible` — tests/Contract/RcpContractTest.php |
| **I-3** | les événements peuvent arriver dans le désordre. Chaque handler vérifie l'état courant | `MembershipLifecycleTest::test_les_evenements_arrives_dans_le_desordre_ne_retrogradent_pas_l_adhesion` — tests/Webhooks/MembershipLifecycleTest.php |
| **I-4** | le champ livemode de l'événement doit correspondre au mode configuré ; sinon l'événement | `WebhookEndpointTest::test_un_evenement_de_production_est_ignore_en_mode_test` — tests/Webhooks/WebhookEndpointTest.php |
| **I-5** | un échec de traitement renvoie 500 afin que Stripe rejoue l'événement ; au-delà de | `WebhookEndpointTest::test_un_evenement_en_erreur_repetee_est_abandonne` — tests/Webhooks/WebhookEndpointTest.php |

