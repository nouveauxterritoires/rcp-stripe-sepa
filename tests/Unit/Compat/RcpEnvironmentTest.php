<?php
/**
 * Tests de la détection d'environnement RCP.
 *
 * Couvre la compatibilité entre la variante libre (« Restrict Content » /
 * « Kadence Memberships ») et la variante commerciale (« Restrict Content Pro »).
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Compat;

use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Compat\RcpEnvironment;

/**
 * @covers \RCP_Stripe_Sepa\Compat\RcpEnvironment
 */
final class RcpEnvironmentTest extends TestCase {

	/**
	 * Instantané d'un environnement pleinement compatible.
	 *
	 * @param array $overrides Valeurs à remplacer.
	 * @return array
	 */
	private function snapshot( array $overrides = array() ): array {
		$base = array(
			'active_plugins'     => array( 'restrict-content/restrictcontent.php' ),
			'constants'          => array(
				'RCP_PLUGIN_VERSION' => '4.0.7',
				'RCP_PLUGIN_DIR'     => '/var/www/html/wp-content/plugins/restrict-content/',
				'RCF_VERSION'        => '4.0.4',
			),
			'classes'            => array_fill_keys( RcpEnvironment::REQUIRED_CLASSES, true ),
			'methods'            => array_fill_keys( RcpEnvironment::REQUIRED_METHODS, true ),
			'functions'          => array_fill_keys( RcpEnvironment::REQUIRED_FUNCTIONS, true ),
			'stripe_sdk_version' => '10.3.0',
			'stripe_sdk_path'    => '/var/www/html/wp-content/plugins/restrict-content/core/includes/libraries/stripe/init.php',
			'chosen_version'     => '3.0',
		);

		return array_merge( $base, $overrides );
	}

	// -- Variante détectée ----------------------------------------------------

	public function test_detecte_la_variante_libre_par_la_constante_rcf_version(): void {
		$env = RcpEnvironment::from_snapshot( $this->snapshot() );

		$this->assertSame( RcpEnvironment::VARIANT_FREE, $env->variant() );
		$this->assertTrue( $env->is_supported() );
	}

	public function test_detecte_la_variante_pro_par_le_plugin_actif(): void {
		$snapshot = $this->snapshot(
			array(
				'active_plugins' => array( 'restrict-content-pro/restrict-content-pro.php' ),
				'constants'      => array(
					'RCP_PLUGIN_VERSION' => '3.5.40',
					'RCP_PLUGIN_DIR'     => '/var/www/html/wp-content/plugins/restrict-content-pro/',
				),
			)
		);

		$env = RcpEnvironment::from_snapshot( $snapshot );

		$this->assertSame( RcpEnvironment::VARIANT_PRO, $env->variant() );
		$this->assertSame( '3.5.40', $env->core_version() );
		$this->assertTrue( $env->is_supported() );
	}

	public function test_la_variante_pro_prime_quand_les_deux_plugins_sont_listes(): void {
		// Sans indication de répertoire, les deux variantes étant listées comme
		// actives, la commerciale l'emporte : c'est elle qui fournit le noyau.
		$snapshot = $this->snapshot(
			array(
				'active_plugins' => array(
					'restrict-content/restrictcontent.php',
					'restrict-content-pro/restrict-content-pro.php',
				),
				'constants'      => array( 'RCP_PLUGIN_VERSION' => '3.5.51' ),
			)
		);

		$this->assertSame( RcpEnvironment::VARIANT_PRO, RcpEnvironment::from_snapshot( $snapshot )->variant() );
	}

	public function test_le_repertoire_charge_prime_sur_une_entree_residuelle(): void {
		// La version libre reste listée comme active, mais c'est bien elle qui
		// est chargée : le diagnostic doit dire « libre », pas « commerciale ».
		$snapshot = $this->snapshot(
			array(
				'active_plugins' => array(
					'restrict-content/restrictcontent.php',
					'restrict-content-pro/restrict-content-pro.php',
				),
			)
		);

		$this->assertSame( RcpEnvironment::VARIANT_FREE, RcpEnvironment::from_snapshot( $snapshot )->variant() );
	}

	public function test_la_variante_est_deduite_du_repertoire_de_rcp(): void {
		/*
		 * `active_plugins` n'est pas toujours renseignée : RCP peut être chargé
		 * par un must-use plugin, par un harnais de tests ou par un bootstrap
		 * applicatif. `RCP_PLUGIN_DIR` est en revanche toujours défini, par RCP
		 * lui-même, à partir du fichier réellement chargé.
		 */
		$snapshot = $this->snapshot(
			array(
				'active_plugins' => array(),
				'constants'      => array(
					'RCP_PLUGIN_VERSION' => '3.5.51',
					'RCP_PLUGIN_DIR'     => '/var/www/html/wp-content/plugins/restrict-content-pro/',
				),
			)
		);

		$this->assertSame( RcpEnvironment::VARIANT_PRO, RcpEnvironment::from_snapshot( $snapshot )->variant() );
	}

	public function test_la_variante_libre_est_deduite_du_repertoire_de_rcp(): void {
		$snapshot = $this->snapshot(
			array(
				'active_plugins' => array(),
				'constants'      => array(
					'RCP_PLUGIN_VERSION' => '4.0.7',
					'RCP_PLUGIN_DIR'     => '/var/www/html/wp-content/plugins/restrict-content/',
				),
			)
		);

		$this->assertSame( RcpEnvironment::VARIANT_FREE, RcpEnvironment::from_snapshot( $snapshot )->variant() );
	}

	public function test_le_repertoire_prime_sur_la_liste_des_plugins_actifs(): void {
		// Une version libre restée active dans l'option alors que Pro est
		// effectivement chargée ne doit pas fausser le diagnostic.
		$snapshot = $this->snapshot(
			array(
				'active_plugins' => array( 'restrict-content/restrictcontent.php' ),
				'constants'      => array(
					'RCP_PLUGIN_VERSION' => '3.5.51',
					'RCP_PLUGIN_DIR'     => '/var/www/html/wp-content/plugins/restrict-content-pro/',
				),
			)
		);

		$this->assertSame( RcpEnvironment::VARIANT_PRO, RcpEnvironment::from_snapshot( $snapshot )->variant() );
	}

	public function test_variante_inconnue_mais_supportee_si_les_capacites_sont_la(): void {
		// La détection de variante ne conditionne jamais le support :
		// seules les capacités effectivement présentes comptent.
		$snapshot = $this->snapshot(
			array(
				'active_plugins' => array( 'un-fork-quelconque/plugin.php' ),
				'constants'      => array(
					'RCP_PLUGIN_VERSION' => '3.5.40',
					'RCP_PLUGIN_DIR'     => '/var/www/html/wp-content/plugins/un-fork-quelconque/',
				),
			)
		);

		$env = RcpEnvironment::from_snapshot( $snapshot );

		$this->assertSame( RcpEnvironment::VARIANT_UNKNOWN, $env->variant() );
		$this->assertTrue( $env->is_supported(), 'Le support dépend des capacités, pas de la variante.' );
	}

	// -- Mode legacy de la variante libre -------------------------------------

	public function test_le_mode_legacy_est_detecte_et_non_supporte(): void {
		$env = RcpEnvironment::from_snapshot( $this->legacy_snapshot() );

		$this->assertTrue( $env->is_legacy_mode() );
		$this->assertFalse( $env->is_supported() );
	}

	public function test_le_mode_legacy_masque_les_autres_anomalies(): void {
		// En mode legacy le noyau RCP n'est pas chargé : lister chaque classe
		// manquante noierait le seul message actionnable.
		$issues = RcpEnvironment::from_snapshot( $this->legacy_snapshot() )->issues();

		$this->assertSame( array( RcpEnvironment::ISSUE_LEGACY_MODE ), $issues );
	}

	/**
	 * Environnement en mode legacy : la variante libre est active mais le noyau
	 * RCP n'est pas chargé.
	 *
	 * @return array
	 */
	private function legacy_snapshot(): array {
		return $this->snapshot(
			array(
				'chosen_version' => 'legacy',
				'classes'        => array(),
				'methods'        => array(),
				'functions'      => array(),
			)
		);
	}

	// -- Capacités requises ---------------------------------------------------

	public function test_signale_une_classe_rcp_manquante(): void {
		$classes = array_fill_keys( RcpEnvironment::REQUIRED_CLASSES, true );
		unset( $classes['RCP_Payment_Gateway_Stripe'] );

		$env = RcpEnvironment::from_snapshot( $this->snapshot( array( 'classes' => $classes ) ) );

		$this->assertFalse( $env->is_supported() );
		$this->assertContains( 'class:RCP_Payment_Gateway_Stripe', $env->issues() );
	}

	public function test_signale_une_methode_rcp_manquante(): void {
		// Scénario de rupture amont : RCP renomme ou supprime une méthode surchargée.
		$methods = array_fill_keys( RcpEnvironment::REQUIRED_METHODS, true );
		unset( $methods['RCP_Payment_Gateway_Stripe::process_ajax_signup'] );

		$env = RcpEnvironment::from_snapshot( $this->snapshot( array( 'methods' => $methods ) ) );

		$this->assertFalse( $env->is_supported() );
		$this->assertContains( 'method:RCP_Payment_Gateway_Stripe::process_ajax_signup', $env->issues() );
	}

	public function test_signale_une_fonction_rcp_manquante(): void {
		$functions = array_fill_keys( RcpEnvironment::REQUIRED_FUNCTIONS, true );
		unset( $functions['rcp_stripe_generate_idempotency_key'] );

		$env = RcpEnvironment::from_snapshot( $this->snapshot( array( 'functions' => $functions ) ) );

		$this->assertContains( 'function:rcp_stripe_generate_idempotency_key', $env->issues() );
	}

	// -- SDK Stripe -----------------------------------------------------------

	public function test_refuse_un_sdk_stripe_absent(): void {
		$snapshot = $this->snapshot(
			array(
				'stripe_sdk_version' => null,
				'stripe_sdk_path'    => null,
			)
		);

		$env = RcpEnvironment::from_snapshot( $snapshot );

		$this->assertFalse( $env->is_supported() );
		$this->assertContains( RcpEnvironment::ISSUE_STRIPE_SDK_MISSING, $env->issues() );
	}

	public function test_accepte_un_sdk_stripe_present_mais_pas_encore_charge(): void {
		// RCP ne charge son SDK Stripe qu'au premier appel de
		// RCP_Payment_Gateway_Stripe::init(). Au démarrage du plugin, sur
		// plugins_loaded, la classe \Stripe\Stripe n'existe donc pas encore :
		// la présence du fichier suffit, la version est vérifiée plus tard.
		$snapshot = $this->snapshot( array( 'stripe_sdk_version' => null ) );

		$env = RcpEnvironment::from_snapshot( $snapshot );

		$this->assertTrue( $env->is_supported() );
		$this->assertTrue( $env->is_stripe_sdk_deferred() );
		$this->assertNull( $env->stripe_sdk_version() );
	}

	public function test_un_sdk_charge_n_est_pas_differe(): void {
		$env = RcpEnvironment::from_snapshot( $this->snapshot() );

		$this->assertFalse( $env->is_stripe_sdk_deferred() );
	}

	public function test_refuse_un_sdk_stripe_trop_ancien(): void {
		$env = RcpEnvironment::from_snapshot( $this->snapshot( array( 'stripe_sdk_version' => '7.128.0' ) ) );

		$this->assertFalse( $env->is_supported() );
		$this->assertContains( RcpEnvironment::ISSUE_STRIPE_SDK_TOO_OLD, $env->issues() );
	}

	public function test_accepte_un_sdk_stripe_plus_recent(): void {
		$env = RcpEnvironment::from_snapshot( $this->snapshot( array( 'stripe_sdk_version' => '13.18.0' ) ) );

		$this->assertTrue( $env->is_supported() );
		$this->assertSame( array(), $env->issues() );
	}

	// -- Version du noyau -----------------------------------------------------

	public function test_signale_un_noyau_rcp_trop_ancien(): void {
		$snapshot = $this->snapshot();

		$snapshot['constants']['RCP_PLUGIN_VERSION'] = '3.4.9';

		$env = RcpEnvironment::from_snapshot( $snapshot );

		$this->assertFalse( $env->is_supported() );
		$this->assertContains( RcpEnvironment::ISSUE_CORE_TOO_OLD, $env->issues() );
	}

	public function test_un_noyau_sans_version_declaree_n_est_pas_bloquant(): void {
		// La variante libre 4.0.4 déclare RCP_PLUGIN_VERSION = 4.0.7 : les numéros
		// de version ne sont pas fiables, les capacités restent l'arbitre.
		$snapshot = $this->snapshot();

		unset( $snapshot['constants']['RCP_PLUGIN_VERSION'] );

		$env = RcpEnvironment::from_snapshot( $snapshot );

		$this->assertNull( $env->core_version() );
		$this->assertTrue( $env->is_supported() );
	}

	// -- RCP absent -----------------------------------------------------------

	public function test_rcp_absent_produit_une_anomalie_unique(): void {
		$env = RcpEnvironment::from_snapshot(
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

		$this->assertFalse( $env->is_rcp_loaded() );
		$this->assertFalse( $env->is_supported() );
		$this->assertSame( array( RcpEnvironment::ISSUE_RCP_MISSING ), $env->issues() );
	}

	// -- Diagnostic -----------------------------------------------------------

	public function test_le_diagnostic_expose_les_informations_utiles(): void {
		$diagnostics = RcpEnvironment::from_snapshot( $this->snapshot() )->to_array();

		$this->assertSame( RcpEnvironment::VARIANT_FREE, $diagnostics['variant'] );
		$this->assertSame( '4.0.7', $diagnostics['core_version'] );
		$this->assertSame( '10.3.0', $diagnostics['stripe_sdk_version'] );
		$this->assertTrue( $diagnostics['supported'] );
		$this->assertSame( array(), $diagnostics['issues'] );
	}

	public function test_le_diagnostic_ne_contient_aucun_secret(): void {
		// CDC SEC-04 : le diagnostic est destiné au support, il ne doit rien divulguer.
		$snapshot = $this->snapshot();

		$snapshot['constants']['RCP_STRIPE_SECRET'] = 'sk_live_exemple_a_ne_pas_divulguer';

		$serialized = (string) json_encode( RcpEnvironment::from_snapshot( $snapshot )->to_array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		$this->assertStringNotContainsString( 'sk_live', $serialized );
		$this->assertStringNotContainsString( 'whsec_', $serialized );
	}

	// -- Immuabilité ----------------------------------------------------------

	public function test_l_instantane_fourni_n_est_pas_mute(): void {
		$snapshot = $this->snapshot();
		$copy     = $snapshot;

		RcpEnvironment::from_snapshot( $snapshot );

		$this->assertSame( $copy, $snapshot );
	}
}
