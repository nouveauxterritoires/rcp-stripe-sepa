<?php
/**
 * Tests de la construction des intentions Stripe.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Gateway;

use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Gateway\IntentFactory;

/**
 * @covers \RCP_Stripe_Sepa\Gateway\IntentFactory
 */
final class IntentFactoryTest extends TestCase {

	/**
	 * Contexte d'inscription par défaut.
	 *
	 * @param array $overrides Valeurs à remplacer.
	 * @return array
	 */
	private function context( array $overrides = array() ): array {
		return array_merge(
			array(
				'customer_id'    => 'cus_123',
				'amount'         => 1000,
				'currency'       => 'eur',
				'description'    => 'Adhésion mensuelle',
				'recurring'      => true,
				'metadata'       => array( 'rcp_membership_id' => '42' ),
				'payment_method' => '',
			),
			$overrides
		);
	}

	// -- Moyen de paiement ------------------------------------------------------

	public function test_l_intention_de_paiement_n_accepte_que_le_prelevement_sepa(): void {
		// Sans cette restriction, Stripe proposerait la carte et le formulaire
		// du plugin ne saurait pas la confirmer.
		$args = IntentFactory::payment_intent( $this->context() );

		$this->assertSame( array( 'sepa_debit' ), $args['payment_method_types'] );
	}

	public function test_l_intention_d_enregistrement_n_accepte_que_le_prelevement_sepa(): void {
		$args = IntentFactory::setup_intent( $this->context( array( 'amount' => 0 ) ) );

		$this->assertSame( array( 'sepa_debit' ), $args['payment_method_types'] );
	}

	// -- Forme imposée par la version d'API ---------------------------------------

	/**
	 * La version `2026-08-26.preview`, reprise dans le canal stable par
	 * `2026-09-30.endive`, retire `payment_method_types` à la création d'une
	 * intention. Le transmettre au-delà fait refuser la requête.
	 */
	public function test_la_version_endive_restreint_le_paiement_par_le_parametre_qui_succede(): void {
		$args = IntentFactory::payment_intent(
			$this->context( array( 'api_version' => '2026-09-30.endive' ) )
		);

		$this->assertSame( array( 'sepa_debit' ), $args['allowed_payment_method_types'] );
		$this->assertArrayNotHasKey( 'payment_method_types', $args );
	}

	public function test_la_version_endive_restreint_aussi_l_intention_d_enregistrement(): void {
		$args = IntentFactory::setup_intent(
			$this->context( array( 'amount' => 0, 'api_version' => '2026-09-30.endive' ) )
		);

		$this->assertSame( array( 'sepa_debit' ), $args['allowed_payment_method_types'] );
		$this->assertArrayNotHasKey( 'payment_method_types', $args );
	}

	/**
	 * Le SDK Stripe embarqué par Restrict Content Pro borne la version que le
	 * plugin peut viser : la forme historique reste celle émise par défaut.
	 */
	public function test_une_version_anterieure_au_retrait_conserve_la_forme_historique(): void {
		$args = IntentFactory::payment_intent(
			$this->context( array( 'api_version' => '2022-11-15' ) )
		);

		$this->assertSame( array( 'sepa_debit' ), $args['payment_method_types'] );
		$this->assertArrayNotHasKey( 'allowed_payment_method_types', $args );
	}

	/**
	 * Les deux formes s'excluent : les transmettre ensemble ferait refuser la
	 * requête, quelle que soit la version visée.
	 */
	public function test_les_deux_formes_ne_sont_jamais_transmises_ensemble(): void {
		foreach ( array( '2022-11-15', '2026-09-30.endive' ) as $version ) {
			$args = IntentFactory::payment_intent( $this->context( array( 'api_version' => $version ) ) );

			$this->assertCount(
				1,
				array_intersect_key(
					$args,
					array( 'payment_method_types' => true, 'allowed_payment_method_types' => true )
				),
				"Version {$version} : une seule des deux formes doit être transmise."
			);
		}
	}

	/**
	 * La version visée pilote la forme des arguments, elle ne doit pas se
	 * retrouver dans la charge utile envoyée à Stripe.
	 */
	public function test_la_version_visee_n_est_pas_transmise_a_stripe(): void {
		$args = IntentFactory::payment_intent(
			$this->context( array( 'api_version' => '2026-09-30.endive' ) )
		);

		$this->assertArrayNotHasKey( 'api_version', $args );
	}

	// -- Usage futur ------------------------------------------------------------

	public function test_une_adhesion_recurrente_enregistre_le_mandat_pour_l_avenir(): void {
		$args = IntentFactory::payment_intent( $this->context( array( 'recurring' => true ) ) );

		$this->assertSame( 'off_session', $args['setup_future_usage'] );
	}

	/**
	 * @group F-03
	 */
	public function test_une_adhesion_a_vie_n_enregistre_pas_de_mandat_recurrent(): void {
		/*
		 * Un paiement unique ne doit pas laisser derrière lui un mandat
		 * réutilisable : le débiteur n'a autorisé qu'un seul prélèvement.
		 */
		$args = IntentFactory::payment_intent( $this->context( array( 'recurring' => false ) ) );

		$this->assertArrayNotHasKey( 'setup_future_usage', $args );
	}

	public function test_l_intention_d_enregistrement_vise_toujours_un_usage_hors_session(): void {
		$args = IntentFactory::setup_intent( $this->context( array( 'amount' => 0 ) ) );

		$this->assertSame( 'off_session', $args['usage'] );
	}

	// -- Montant et devise -------------------------------------------------------

	public function test_le_montant_et_la_devise_sont_repris(): void {
		$args = IntentFactory::payment_intent( $this->context( array( 'amount' => 9900 ) ) );

		$this->assertSame( 9900, $args['amount'] );
		$this->assertSame( 'eur', $args['currency'] );
	}

	public function test_la_devise_est_normalisee_en_minuscules(): void {
		$args = IntentFactory::payment_intent( $this->context( array( 'currency' => 'EUR' ) ) );

		$this->assertSame( 'eur', $args['currency'] );
	}

	// -- Confirmation ------------------------------------------------------------

	/**
	 * @group CNF-04
	 */
	public function test_l_intention_n_est_pas_confirmee_cote_serveur(): void {
		/*
		 * La confirmation a lieu dans le navigateur : c'est elle qui recueille
		 * l'acceptation du mandat par le débiteur, laquelle vaut signature.
		 */
		$args = IntentFactory::payment_intent( $this->context() );

		$this->assertArrayNotHasKey( 'confirm', $args );
		$this->assertArrayNotHasKey( 'mandate_data', $args );
	}

	// -- Métadonnées -------------------------------------------------------------

	public function test_les_metadonnees_permettent_de_retrouver_l_adhesion(): void {
		// C'est la piste de résolution la plus fiable côté webhook.
		$args = IntentFactory::payment_intent( $this->context() );

		$this->assertSame( '42', $args['metadata']['rcp_membership_id'] );
	}

	public function test_le_client_stripe_est_rattache(): void {
		$this->assertSame( 'cus_123', IntentFactory::payment_intent( $this->context() )['customer'] );
		$this->assertSame( 'cus_123', IntentFactory::setup_intent( $this->context() )['customer'] );
	}

	// -- Moyen de paiement existant ----------------------------------------------

	public function test_un_mandat_deja_enregistre_est_reutilise(): void {
		$args = IntentFactory::payment_intent( $this->context( array( 'payment_method' => 'pm_existing' ) ) );

		$this->assertSame( 'pm_existing', $args['payment_method'] );
	}

	public function test_aucun_moyen_de_paiement_n_est_impose_par_defaut(): void {
		$this->assertArrayNotHasKey( 'payment_method', IntentFactory::payment_intent( $this->context() ) );
	}

	// -- Descripteur de relevé ----------------------------------------------------

	public function test_le_descripteur_de_releve_est_transmis_quand_il_est_defini(): void {
		$args = IntentFactory::payment_intent( $this->context( array( 'statement_descriptor' => 'ASSOCIATION' ) ) );

		$this->assertSame( 'ASSOCIATION', $args['payment_method_options']['sepa_debit']['mandate_options']['reference_prefix'] ?? null );
	}

	public function test_aucun_descripteur_n_est_transmis_quand_il_est_vide(): void {
		$this->assertArrayNotHasKey( 'payment_method_options', IntentFactory::payment_intent( $this->context() ) );
	}

	// -- Choix de l'intention ------------------------------------------------------

	/**
	 * @group RG-03
	 */
	public function test_un_montant_nul_impose_une_intention_d_enregistrement(): void {
		// Adhésion gratuite ou remise de 100 % : rien à encaisser aujourd'hui,
		// mais un mandat à recueillir pour les échéances suivantes.
		$this->assertFalse( IntentFactory::needs_payment_intent( $this->context( array( 'amount' => 0 ) ) ) );
	}

	public function test_un_montant_positif_impose_une_intention_de_paiement(): void {
		$this->assertTrue( IntentFactory::needs_payment_intent( $this->context( array( 'amount' => 1000 ) ) ) );
	}

	// -- Immuabilité ----------------------------------------------------------------

	public function test_le_contexte_fourni_n_est_pas_mute(): void {
		$context = $this->context();
		$copy    = $context;

		IntentFactory::payment_intent( $context );
		IntentFactory::setup_intent( $context );

		$this->assertSame( $copy, $context );
	}
}
