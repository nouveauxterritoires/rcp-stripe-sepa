<?php
/**
 * Tests de la résolution du secret de webhook.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Integration;

use RCP_Stripe_Sepa\Support\StripeSdk;
use RCP_Stripe_Sepa\Webhook\EventResult;
use RCP_Stripe_Sepa\Webhook\EventStore;
use RCP_Stripe_Sepa\Webhook\Settings;
use RCP_Stripe_Sepa\Webhook\WebhookSecret;
use RCP_Stripe_Sepa\Membership\StateMachine;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Webhook\WebhookSecret
 * @covers \RCP_Stripe_Sepa\Webhook\Settings
 * @covers \RCP_Stripe_Sepa\Webhook\EventResult
 * @covers \RCP_Stripe_Sepa\Support\StripeSdk
 */
final class WebhookSecretTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		global $rcp_options;

		$rcp_options            = is_array( $rcp_options ) ? $rcp_options : array();
		$rcp_options['sandbox'] = 1;
	}

	public function tear_down(): void {
		global $rcp_options;

		$rcp_options['sandbox'] = 1;

		delete_option( WebhookSecret::OPTION_TEST );
		delete_option( WebhookSecret::OPTION_LIVE );
		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	// -- Cloisonnement des modes ------------------------------------------------

	public function test_le_mode_est_celui_de_rcp(): void {
		global $rcp_options;

		$this->assertTrue( WebhookSecret::is_test_mode() );

		$rcp_options['sandbox'] = 0;

		$this->assertFalse( WebhookSecret::is_test_mode() );
	}

	public function test_chaque_mode_a_son_propre_secret(): void {
		global $rcp_options;

		update_option( WebhookSecret::OPTION_TEST, 'secret-de-test' );
		update_option( WebhookSecret::OPTION_LIVE, 'secret-de-production' );

		$this->assertSame( 'secret-de-test', WebhookSecret::get() );

		$rcp_options['sandbox'] = 0;

		$this->assertSame( 'secret-de-production', WebhookSecret::get() );
	}

	public function test_un_secret_absent_renvoie_une_chaine_vide(): void {
		$this->assertSame( '', WebhookSecret::get() );
	}

	// -- Priorité de la constante -------------------------------------------------

	public function test_une_constante_prime_sur_l_option(): void {
		/*
		 * SEC-02 : un secret déclaré dans wp-config.php ne fuite ni dans un
		 * export de réglages, ni dans une sauvegarde de base partagée.
		 */
		global $rcp_options;

		define( WebhookSecret::CONSTANT_LIVE, 'secret-de-la-constante' );

		$rcp_options['sandbox'] = 0;

		update_option( WebhookSecret::OPTION_LIVE, 'secret-de-la-base' );

		$this->assertSame( 'secret-de-la-constante', WebhookSecret::get() );
		$this->assertTrue( WebhookSecret::is_locked(), 'Le champ de réglage doit être verrouillé.' );
	}

	public function test_sans_constante_le_reglage_reste_modifiable(): void {
		$this->assertFalse( WebhookSecret::is_locked() );
	}

	// -- Réglages --------------------------------------------------------------------

	public function test_la_politique_d_acces_est_stricte_par_defaut(): void {
		// RG-01 : par défaut, aucun accès avant encaissement effectif.
		$this->assertSame( StateMachine::ACCESS_STRICT, Settings::access_policy() );
	}

	public function test_la_politique_d_acces_optimiste_est_reconnue(): void {
		update_option( Settings::OPTION, array( 'access_policy' => StateMachine::ACCESS_OPTIMISTIC ) );

		$this->assertSame( StateMachine::ACCESS_OPTIMISTIC, Settings::access_policy() );
	}

	public function test_une_politique_inconnue_retombe_sur_la_plus_prudente(): void {
		update_option( Settings::OPTION, array( 'access_policy' => 'nimporte-quoi' ) );

		$this->assertSame( StateMachine::ACCESS_STRICT, Settings::access_policy() );
	}

	public function test_un_litige_revoque_l_acces_par_defaut(): void {
		$this->assertSame( StateMachine::DISPUTE_REVOKE, Settings::dispute_policy() );
	}

	public function test_la_notification_seule_est_reconnue(): void {
		update_option( Settings::OPTION, array( 'dispute_policy' => StateMachine::DISPUTE_NOTIFY ) );

		$this->assertSame( StateMachine::DISPUTE_NOTIFY, Settings::dispute_policy() );
	}

	public function test_le_delai_d_alerte_a_une_valeur_par_defaut_utile(): void {
		// Le délai doit couvrir le pire cas d'un prélèvement SEPA.
		$this->assertSame( 14, Settings::pending_alert_days() );
	}

	public function test_un_delai_d_alerte_absurde_est_ramene_a_un_jour(): void {
		update_option( Settings::OPTION, array( 'pending_alert_days' => 0 ) );

		$this->assertSame( 1, Settings::pending_alert_days() );
	}

	// -- Issue de traitement -----------------------------------------------------------

	public function test_une_issue_traitee_se_distingue_d_une_issue_ignoree(): void {
		$processed = EventResult::processed( 'adhésion activée' );
		$skipped   = EventResult::skipped( 'événement non géré' );

		$this->assertTrue( $processed->is_processed() );
		$this->assertSame( EventStore::STATUS_PROCESSED, $processed->status() );
		$this->assertSame( 'adhésion activée', $processed->note() );

		$this->assertFalse( $skipped->is_processed() );
		$this->assertSame( EventStore::STATUS_SKIPPED, $skipped->status() );
	}

	// -- SDK Stripe ---------------------------------------------------------------------

	public function test_le_sdk_stripe_peut_etre_charge_a_la_demande(): void {
		// Un webhook peut arriver sans qu'aucune passerelle n'ait été
		// instanciée : le SDK doit pouvoir être chargé à cet instant.
		$this->assertTrue( StripeSdk::ensure_loaded() );
		$this->assertTrue( class_exists( '\Stripe\Stripe' ) );
	}

	public function test_les_options_de_requete_portent_la_version_d_api_du_plugin(): void {
		// R-API-1 : la version est transmise par requête, jamais fixée
		// globalement, pour ne pas casser la passerelle carte de RCP.
		$options = StripeSdk::request_options( array( 'idempotency_key' => 'abc' ) );

		$this->assertSame( RCP_SEPA_STRIPE_API_VERSION, $options['stripe_version'] );
		$this->assertSame( 'abc', $options['idempotency_key'] );
	}

	public function test_la_version_d_api_du_plugin_est_celle_declaree(): void {
		$this->assertSame( RCP_SEPA_STRIPE_API_VERSION, StripeSdk::api_version() );
	}
}
