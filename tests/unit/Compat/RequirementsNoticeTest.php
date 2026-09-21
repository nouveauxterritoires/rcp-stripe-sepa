<?php
/**
 * Tests du message d'incompatibilité affiché à l'administrateur.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Compat;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Compat\RcpEnvironment;
use RCP_Stripe_Sepa\Compat\RequirementsNotice;

/**
 * @covers \RCP_Stripe_Sepa\Compat\RequirementsNotice
 */
final class RequirementsNoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Environnement dégradé portant les anomalies demandées.
	 *
	 * @param array $overrides Valeurs à remplacer dans l'instantané.
	 * @return RcpEnvironment
	 */
	private function environment( array $overrides ): RcpEnvironment {
		return RcpEnvironment::from_snapshot(
			array_merge(
				array(
					'active_plugins'     => array( 'restrict-content/restrictcontent.php' ),
					'constants'          => array( 'RCP_PLUGIN_VERSION' => '4.0.7' ),
					'classes'            => array_fill_keys( RcpEnvironment::REQUIRED_CLASSES, true ),
					'methods'            => array_fill_keys( RcpEnvironment::REQUIRED_METHODS, true ),
					'functions'          => array_fill_keys( RcpEnvironment::REQUIRED_FUNCTIONS, true ),
					'stripe_sdk_version' => '10.3.0',
					'stripe_sdk_path'    => '/plugins/restrict-content/core/includes/libraries/stripe/init.php',
					'chosen_version'     => '3.0',
				),
				$overrides
			)
		);
	}

	public function test_aucun_message_si_l_environnement_est_supporte(): void {
		$notice = new RequirementsNotice( $this->environment( array() ) );

		$this->assertSame( array(), $notice->messages() );
	}

	public function test_message_dedie_quand_rcp_est_absent(): void {
		$notice = new RequirementsNotice( $this->environment( array( 'classes' => array() ) ) );

		$messages = $notice->messages();

		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'Restrict Content', $messages[0] );
	}

	public function test_message_dedie_pour_le_mode_legacy(): void {
		$notice = new RequirementsNotice( $this->environment( array( 'chosen_version' => 'legacy' ) ) );

		$messages = $notice->messages();

		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'legacy', $messages[0] );
	}

	public function test_message_dedie_pour_un_sdk_stripe_trop_ancien(): void {
		$notice = new RequirementsNotice( $this->environment( array( 'stripe_sdk_version' => '7.0.0' ) ) );

		$this->assertStringContainsString( RcpEnvironment::MINIMUM_STRIPE_SDK_VERSION, $notice->messages()[0] );
	}

	public function test_les_capacites_manquantes_sont_regroupees_en_un_message(): void {
		// Une rupture d'API amont produit souvent plusieurs absences : un seul
		// message, listant les éléments, reste lisible.
		$methods = array_fill_keys( RcpEnvironment::REQUIRED_METHODS, true );
		unset( $methods['RCP_Payment_Gateway_Stripe::process_signup'] );
		unset( $methods['RCP_Payment_Gateway_Stripe::process_webhooks'] );

		$notice = new RequirementsNotice( $this->environment( array( 'methods' => $methods ) ) );

		$messages = $notice->messages();

		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'process_signup', $messages[0] );
		$this->assertStringContainsString( 'process_webhooks', $messages[0] );
	}

	public function test_le_rendu_html_est_echappe_et_identifiable(): void {
		$notice = new RequirementsNotice( $this->environment( array( 'classes' => array() ) ) );

		$html = $notice->render();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'rcp-stripe-sepa', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_le_rendu_est_vide_si_tout_va_bien(): void {
		$notice = new RequirementsNotice( $this->environment( array() ) );

		$this->assertSame( '', $notice->render() );
	}
}
