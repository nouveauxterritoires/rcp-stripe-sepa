<?php
/**
 * Tests de l'intégration aux outils de confidentialité de WordPress.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Integration;

use RCP_Stripe_Sepa\Mandate\MandateData;
use RCP_Stripe_Sepa\Mandate\MandateRepository;
use RCP_Stripe_Sepa\Privacy\Registry;
use RCP_Stripe_Sepa\Tests\Support\RcpFixtures;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Privacy\Registry
 */
final class PrivacyTest extends WP_UnitTestCase {

	use RcpFixtures;

	/**
	 * Adhérent concerné.
	 *
	 * @var int
	 */
	private $user_id = 0;

	/**
	 * Son adhésion.
	 *
	 * @var int
	 */
	private $membership_id = 0;

	public function set_up(): void {
		parent::set_up();

		$this->configure_rcp_stripe();
		$this->redirect_stripe_to_mock();

		$this->user_id       = $this->create_user();
		$this->membership_id = $this->create_membership(
			array(
				'user_id' => $this->user_id,
				'status'  => 'active',
				'gateway' => 'stripe_sepa',
			)
		);

		MandateRepository::save( $this->membership_id, $this->mandate_data() );

		Registry::register();
	}

	public function tear_down(): void {
		$this->restore_stripe_api_base();

		parent::tear_down();
	}

	/**
	 * Données de mandat enregistrées.
	 *
	 * @param string $accepted_at Date d'acceptation.
	 * @return array
	 */
	private function mandate_data( string $accepted_at = '2026-09-21 16:30:00' ): array {
		return MandateData::with_acceptance(
			MandateData::from_stripe(
				array(
					'id'              => 'pm_rgpd',
					'type'            => 'sepa_debit',
					'billing_details' => array( 'name' => 'Membre RGPD' ),
					'sepa_debit'      => array( 'last4' => '2606', 'country' => 'FR' ),
				),
				array(
					'id'                     => 'mandate_rgpd',
					'status'                 => 'active',
					'payment_method_details' => array( 'sepa_debit' => array( 'reference' => 'RUMRGPD' ) ),
				)
			),
			'203.0.113.4',
			$accepted_at
		);
	}

	/**
	 * Adresse de l'adhérent.
	 *
	 * @return string
	 */
	private function email(): string {
		return (string) get_userdata( $this->user_id )->user_email;
	}

	// -- Déclarations ------------------------------------------------------------

	/**
	 * @group F-12
	 * @group CNF-05
	 */
	public function test_l_exportateur_est_declare(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );

		$this->assertArrayHasKey( Registry::EXPORTER_ID, $exporters );
		$this->assertIsCallable( $exporters[ Registry::EXPORTER_ID ]['callback'] );
	}

	/**
	 * @group F-12
	 * @group CNF-06
	 */
	public function test_l_effaceur_est_declare(): void {
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( Registry::ERASER_ID, $erasers );
		$this->assertIsCallable( $erasers[ Registry::ERASER_ID ]['callback'] );
	}

	// -- Export ----------------------------------------------------------------------

	/**
	 * @group SEC-14
	 */
	public function test_l_export_restitue_le_mandat(): void {
		$export = Registry::export( $this->email() );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );

		$values = wp_list_pluck( $export['data'][0]['data'], 'value' );

		$this->assertContains( 'Membre RGPD', $values );
		$this->assertContains( 'RUMRGPD', $values );
	}

	public function test_l_export_ne_contient_jamais_d_iban_complet(): void {
		$serialized = (string) wp_json_encode( Registry::export( $this->email() ) );

		$this->assertDoesNotMatchRegularExpression( '/FR\d{2}[A-Z0-9]{10,}/', $serialized );
	}

	public function test_l_export_d_une_adresse_inconnue_est_vide(): void {
		$export = Registry::export( 'inconnu@example.test' );

		$this->assertSame( array(), $export['data'] );
		$this->assertTrue( $export['done'] );
	}

	public function test_une_adhesion_sans_mandat_n_apparait_pas_dans_l_export(): void {
		MandateRepository::forget( $this->membership_id );

		$this->assertSame( array(), Registry::export( $this->email() )['data'] );
	}

	// -- Effacement ----------------------------------------------------------------------

	public function test_l_effacement_supprime_le_mandat(): void {
		$result = Registry::erase( $this->email() );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( array(), MandateRepository::find( $this->membership_id ) );
	}

	public function test_l_effacement_signale_les_donnees_conservees_par_stripe(): void {
		/*
		 * CNF-06 : la suppression côté Stripe n'est pas automatisée, les
		 * obligations comptables primant. L'administrateur doit le savoir
		 * plutôt que de croire l'effacement complet.
		 */
		$result = Registry::erase( $this->email() );

		$this->assertNotEmpty( $result['messages'] );
		$this->assertStringContainsString( 'Stripe', $result['messages'][0] );
	}

	public function test_l_effacement_d_une_adresse_sans_mandat_ne_signale_rien(): void {
		$result = Registry::erase( 'inconnu@example.test' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertSame( array(), $result['messages'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_l_effacement_est_idempotent(): void {
		Registry::erase( $this->email() );

		$this->assertFalse( Registry::erase( $this->email() )['items_removed'] );
	}

	// -- Conservation limitée -----------------------------------------------------------------

	public function test_une_adresse_de_signature_expiree_est_purgee(): void {
		// SEC-15 : au-delà de treize mois, un prélèvement ne peut plus être
		// contesté ; l'adresse perd toute valeur probatoire.
		MandateRepository::save(
			$this->membership_id,
			$this->mandate_data( gmdate( 'Y-m-d H:i:s', strtotime( '-14 months' ) ) )
		);

		$this->assertSame( 1, Registry::purge_expired_ips() );
		$this->assertSame( '', MandateRepository::find( $this->membership_id )['accepted_ip'] );
	}

	public function test_une_signature_recente_n_est_pas_purgee(): void {
		$this->assertSame( 0, Registry::purge_expired_ips() );
		$this->assertSame( '203.0.113.4', MandateRepository::find( $this->membership_id )['accepted_ip'] );
	}

	public function test_la_purge_est_planifiee(): void {
		$this->assertNotFalse( wp_next_scheduled( Registry::PURGE_HOOK ) );
	}

	public function test_la_purge_conserve_le_reste_du_mandat(): void {
		// Seule l'adresse expire : le mandat reste nécessaire tant que
		// l'adhésion existe.
		MandateRepository::save(
			$this->membership_id,
			$this->mandate_data( gmdate( 'Y-m-d H:i:s', strtotime( '-14 months' ) ) )
		);

		Registry::purge_expired_ips();

		$mandate = MandateRepository::find( $this->membership_id );

		$this->assertSame( 'RUMRGPD', $mandate['mandate_reference'] );
		$this->assertSame( '2606', $mandate['iban_last4'] );
	}

	// -- Politique de confidentialité ----------------------------------------------------------

	public function test_la_mention_est_proposee_au_bon_moment(): void {
		/*
		 * WordPress refuse `wp_add_privacy_policy_content()` hors de
		 * l'administration et avant `admin_init`. Le test vérifie donc le
		 * point d'accroche plutôt que d'invoquer la fonction, ce qui
		 * contournerait un garde-fou que la production respecte.
		 */
		$this->assertNotFalse(
			has_action( 'admin_init', array( Registry::class, 'suggest_privacy_policy' ) )
		);
	}

	public function test_la_mention_ne_fuite_aucune_donnee_bancaire(): void {
		$content = \RCP_Stripe_Sepa\Privacy\PersonalData::privacy_policy_content();

		$this->assertStringContainsString( 'Stripe', $content );
		$this->assertDoesNotMatchRegularExpression( '/FR\d{2}[A-Z0-9]{10,}/', $content );
	}
}
