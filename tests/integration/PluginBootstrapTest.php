<?php
/**
 * Tests d'intégration de l'amorçage du plugin.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Integration;

use RCP_Stripe_Sepa\Compat\RcpEnvironment;
use RCP_Stripe_Sepa\Plugin;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Plugin
 */
final class PluginBootstrapTest extends WP_UnitTestCase {

	public function test_le_plugin_est_charge(): void {
		$this->assertTrue( defined( 'RCP_SEPA_VERSION' ) );
		$this->assertTrue( defined( 'RCP_SEPA_STRIPE_API_VERSION' ) );
		$this->assertTrue( class_exists( Plugin::class ) );
	}

	public function test_le_plugin_demarre_dans_un_environnement_compatible(): void {
		$booted = false;

		add_action(
			'rcp_stripe_sepa_booted',
			static function () use ( &$booted ) {
				$booted = true;
			}
		);

		$plugin = Plugin::boot();

		$this->assertTrue( $plugin->is_active(), implode( ', ', $plugin->environment()->issues() ) );
		$this->assertTrue( $booted, 'L\'action rcp_stripe_sepa_booted n\'a pas été déclenchée.' );
	}

	public function test_le_plugin_ne_demarre_pas_sans_rcp_et_affiche_un_avis(): void {
		$plugin = Plugin::boot( $this->unsupported_environment() );

		$this->assertFalse( $plugin->is_active() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		$plugin->render_requirements_notice();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
	}

	public function test_l_avis_n_est_pas_affiche_aux_utilisateurs_sans_droits(): void {
		// SEC-16 : aucune information d'environnement ne fuite vers un abonné.
		$plugin = Plugin::boot( $this->unsupported_environment() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		$plugin->render_requirements_notice();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_aucune_erreur_fatale_sans_rcp(): void {
		// STD-05 : désactivation propre, jamais d'erreur fatale.
		$plugin = Plugin::boot( $this->unsupported_environment() );

		$this->assertFalse( $plugin->is_active() );
		$this->assertSame( $plugin, Plugin::instance() );
	}

	/**
	 * Environnement dans lequel RCP est absent.
	 *
	 * @return RcpEnvironment
	 */
	private function unsupported_environment(): RcpEnvironment {
		return RcpEnvironment::from_snapshot(
			array(
				'active_plugins'     => array(),
				'constants'          => array(),
				'classes'            => array(),
				'methods'            => array(),
				'functions'          => array(),
				'stripe_sdk_version' => null,
				'stripe_sdk_path'    => null,
				'chosen_version'     => '',
			)
		);
	}
}
