<?php
/**
 * Tests des écrans d'administration et d'affichage du mandat.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Integration;

use RCP_Stripe_Sepa\Admin\Diagnostics;
use RCP_Stripe_Sepa\Admin\DiagnosticsPage;
use RCP_Stripe_Sepa\Admin\MembershipMandate;
use RCP_Stripe_Sepa\Admin\SiteHealth;
use RCP_Stripe_Sepa\Frontend\MandateDetails;
use RCP_Stripe_Sepa\Mandate\MandateData;
use RCP_Stripe_Sepa\Mandate\MandateRepository;
use RCP_Stripe_Sepa\Tests\Support\RcpFixtures;
use RCP_Stripe_Sepa\Webhook\EventStore;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Admin\Diagnostics
 * @covers \RCP_Stripe_Sepa\Admin\DiagnosticsPage
 * @covers \RCP_Stripe_Sepa\Admin\SiteHealth
 * @covers \RCP_Stripe_Sepa\Admin\MembershipMandate
 * @covers \RCP_Stripe_Sepa\Frontend\MandateDetails
 */
final class AdminScreensTest extends WP_UnitTestCase {

	use RcpFixtures;

	/**
	 * Utilisateur adhérent.
	 *
	 * @var int
	 */
	private $user_id = 0;

	public function set_up(): void {
		parent::set_up();

		$this->configure_rcp_stripe();
		$this->redirect_stripe_to_mock();

		EventStore::install();

		$this->user_id = $this->create_user();

		wp_set_current_user( $this->user_id );
	}

	public function tear_down(): void {
		$this->restore_stripe_api_base();

		parent::tear_down();
	}

	/**
	 * Crée une adhésion SEPA dotée d'un mandat.
	 *
	 * @return int
	 */
	private function sepa_membership_with_mandate(): int {
		$membership_id = $this->create_membership(
			array(
				'user_id'                 => $this->user_id,
				'status'                  => 'active',
				'gateway'                 => 'stripe_sepa',
				'gateway_customer_id'     => 'cus_ecran',
				'gateway_subscription_id' => 'sub_ecran',
			)
		);

		MandateRepository::save(
			$membership_id,
			MandateData::with_acceptance(
				MandateData::from_stripe(
					array(
						'id'              => 'pm_ecran',
						'type'            => 'sepa_debit',
						'billing_details' => array( 'name' => 'Membre Écran' ),
						'sepa_debit'      => array( 'last4' => '2606', 'country' => 'FR' ),
					),
					array(
						'id'                     => 'mandate_ecran',
						'status'                 => 'active',
						'payment_method_details' => array(
							'sepa_debit' => array( 'reference' => 'RUM123456', 'url' => 'https://example.test/mandat' ),
						),
					)
				),
				'203.0.113.4',
				'2026-09-21 16:30:00'
			)
		);

		return $membership_id;
	}

	// -- Diagnostic -----------------------------------------------------------

	public function test_le_rapport_reflete_l_installation_reelle(): void {
		$report = Diagnostics::report();

		$this->assertArrayHasKey( 'checks', $report );
		$this->assertNotEmpty( $report['checks'] );
		$this->assertTrue( $report['test_mode'] );
	}

	public function test_le_rapport_signale_une_devise_inadaptee(): void {
		global $rcp_options;

		$rcp_options['currency'] = 'USD';

		$statuses = wp_list_pluck( Diagnostics::report()['checks'], 'status', 'id' );

		$this->assertSame( Diagnostics::STATUS_ERROR, $statuses['currency'] );
	}

	public function test_le_rapport_ne_contient_aucun_secret(): void {
		// L'écran est destiné au support : son contenu peut être copié ailleurs.
		$serialized = (string) wp_json_encode( Diagnostics::report() );

		$this->assertStringNotContainsString( 'whsec_', $serialized );
		$this->assertStringNotContainsString( 'sk_test_', $serialized );
	}

	// -- Écran d'administration -------------------------------------------------

	public function test_l_ecran_est_reserve_aux_administrateurs(): void {
		// SEC-16 : la capacité est vérifiée à l'affichage, pas seulement à
		// l'inscription du menu.
		wp_set_current_user( $this->create_user() );

		$this->expectException( \WPDieException::class );

		DiagnosticsPage::render();
	}

	public function test_l_ecran_s_affiche_pour_un_administrateur(): void {
		wp_set_current_user( $this->create_rcp_admin() );

		ob_start();
		DiagnosticsPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'SEPA Direct Debit', $html );
		$this->assertStringContainsString( 'rcp-stripe-sepa/v1/webhook', $html, 'L\'URL du point de terminaison doit être rappelée.' );
		$this->assertStringContainsString( 'Stripe test mode', $html );
	}

	public function test_l_ecran_liste_les_evenements_recus(): void {
		EventStore::claim( 'evt_ecran_1', 'payment_intent.succeeded', false, '{}' );
		EventStore::resolve( 'evt_ecran_1', EventStore::STATUS_PROCESSED, 'membership activated' );

		wp_set_current_user( $this->create_rcp_admin() );

		ob_start();
		DiagnosticsPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'payment_intent.succeeded', $html );
		$this->assertStringContainsString( 'membership activated', $html );
		$this->assertStringContainsString( 'Replay', $html );
	}

	public function test_le_rejeu_exige_un_nonce(): void {
		wp_set_current_user( $this->create_rcp_admin() );

		$_POST = array( 'event_id' => 'evt_ecran_1' );

		$this->expectException( \WPDieException::class );

		DiagnosticsPage::handle_replay();
	}

	public function test_le_rejeu_est_refuse_sans_droits(): void {
		wp_set_current_user( $this->create_user() );

		$this->expectException( \WPDieException::class );

		DiagnosticsPage::handle_replay();
	}

	// -- Santé du site -------------------------------------------------------------

	public function test_le_test_est_declare_dans_la_sante_du_site(): void {
		SiteHealth::register();

		$tests = apply_filters( 'site_status_tests', array() );

		$this->assertArrayHasKey( SiteHealth::TEST_ID, $tests['direct'] );
	}

	public function test_le_test_remonte_les_anomalies_bloquantes(): void {
		global $rcp_options;

		$rcp_options['currency'] = 'USD';

		$result = SiteHealth::run_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'USD', $result['description'] );
	}

	public function test_le_test_renvoie_un_resultat_exploitable_par_wordpress(): void {
		foreach ( array( 'label', 'status', 'badge', 'description', 'actions', 'test' ) as $key ) {
			$this->assertArrayHasKey( $key, SiteHealth::run_test(), 'Clé manquante : ' . $key );
		}
	}

	// -- Mandat côté administration ---------------------------------------------------

	public function test_le_mandat_est_affiche_sur_la_fiche_d_adhesion(): void {
		$membership = rcp_get_membership( $this->sepa_membership_with_mandate() );

		ob_start();
		MembershipMandate::render( $membership );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'RUM123456', $html );
		$this->assertStringContainsString( '2606', $html );
		$this->assertStringContainsString( 'Membre Écran', $html );
	}

	public function test_la_fiche_ne_montre_jamais_d_iban_complet(): void {
		$membership = rcp_get_membership( $this->sepa_membership_with_mandate() );

		ob_start();
		MembershipMandate::render( $membership );
		$html = (string) ob_get_clean();

		$this->assertDoesNotMatchRegularExpression( '/FR\d{2}[A-Z0-9]{10,}/', $html );
	}

	public function test_les_liens_stripe_visent_le_mode_courant(): void {
		$membership = rcp_get_membership( $this->sepa_membership_with_mandate() );

		ob_start();
		MembershipMandate::render( $membership );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'dashboard.stripe.com/test/customers/cus_ecran', $html );
	}

	public function test_aucun_mandat_n_est_affiche_pour_une_adhesion_carte(): void {
		$membership = rcp_get_membership(
			$this->create_membership( array( 'user_id' => $this->user_id, 'gateway' => 'stripe' ) )
		);

		ob_start();
		MembershipMandate::render( $membership );

		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	// -- Mandat côté membre -------------------------------------------------------------

	public function test_le_mandat_est_affiche_sur_la_page_du_compte(): void {
		$this->sepa_membership_with_mandate();

		MandateDetails::register();

		ob_start();
		do_action( 'rcp_subscription_details_bottom' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Active SEPA Direct Debit', $html );
		$this->assertStringContainsString( 'FR•• •••• •••• 2606', $html );
		$this->assertStringContainsString( 'RUM123456', $html );
	}

	public function test_la_page_du_compte_ne_montre_jamais_d_iban_complet(): void {
		$this->sepa_membership_with_mandate();

		MandateDetails::register();

		ob_start();
		do_action( 'rcp_subscription_details_bottom' );
		$html = (string) ob_get_clean();

		$this->assertDoesNotMatchRegularExpression( '/FR\d{2}[A-Z0-9]{10,}/', $html );
	}

	public function test_aucun_mandat_pour_un_visiteur_sans_compte(): void {
		wp_set_current_user( 0 );

		MandateDetails::register();

		ob_start();
		do_action( 'rcp_subscription_details_bottom' );

		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}
}
