<?php
/**
 * Tests de contrat de la migration vers le prélèvement SEPA.
 *
 * Pilotés contre `stripe-mock`, ils vérifient que les requêtes émises sont
 * acceptées et que les garde-fous se déclenchent sur des objets Stripe réels.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Contract;

use RCP_Stripe_Sepa\Gateway\GatewayDefinition;
use RCP_Stripe_Sepa\Mandate\MandateRepository;
use RCP_Stripe_Sepa\Migration\Migrator;
use RCP_Stripe_Sepa\Support\StripeSdk;
use RCP_Stripe_Sepa\Tests\Support\RcpFixtures;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Migration\Migrator
 * @group contract
 */
final class MigrationStripeTest extends WP_UnitTestCase {

	use RcpFixtures;

	private const MOCK_SECRET_KEY = 'sk_test_123456789';

	/**
	 * Base d'API d'origine.
	 *
	 * @var string
	 */
	private $api_base = '';

	/**
	 * Adhésion réglée par carte.
	 *
	 * @var int
	 */
	private $membership_id = 0;

	public function set_up(): void {
		parent::set_up();

		global $rcp_options;

		$rcp_options                       = is_array( $rcp_options ) ? $rcp_options : array();
		$rcp_options['sandbox']            = 1;
		$rcp_options['currency']           = 'EUR';
		$rcp_options['stripe_test_secret'] = self::MOCK_SECRET_KEY;

		$this->assertTrue( StripeSdk::ensure_loaded() );

		$this->api_base          = \Stripe\Stripe::$apiBase;
		\Stripe\Stripe::$apiBase = $this->mock_base();

		\Stripe\Stripe::setApiKey( self::MOCK_SECRET_KEY );

		$this->membership_id = $this->card_membership();
	}

	public function tear_down(): void {
		\Stripe\Stripe::$apiBase = $this->api_base;

		parent::tear_down();
	}

	/**
	 * Adresse de stripe-mock.
	 *
	 * @return string
	 */
	private function mock_base(): string {
		$host = getenv( 'STRIPE_MOCK_HOST' ) ?: 'stripe-mock';
		$port = getenv( 'STRIPE_MOCK_PORT' ) ?: '12111';

		return 'http://' . $host . ':' . $port;
	}

	/**
	 * Crée une adhésion active réglée par carte.
	 *
	 * @param string $customer_id Identifiant du client Stripe.
	 * @return int
	 */
	private function card_membership( string $customer_id = 'cus_migration' ): int {
		return $this->create_membership(
			array(
				'status'                  => 'active',
				'gateway'                 => 'stripe',
				'gateway_customer_id'     => $customer_id,
				'gateway_subscription_id' => 'sub_migration',
			)
		);
	}

	// -- Préparation ------------------------------------------------------------

	public function test_la_preparation_renvoie_de_quoi_confirmer_le_mandat(): void {
		$result = Migrator::start( rcp_get_membership( $this->membership_id ) );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertNotEmpty( $result['client_secret'] );
		$this->assertStringStartsWith( 'seti_', $result['setup_intent_id'] );
	}

	public function test_une_adhesion_ineligible_ne_prepare_rien(): void {
		$membership = rcp_get_membership( $this->membership_id );
		$membership->set_status( 'cancelled' );

		$result = Migrator::start( rcp_get_membership( $this->membership_id ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'rcp_stripe_sepa_not_eligible', $result->get_error_code() );
	}

	// -- Garde-fous de la confirmation ---------------------------------------------

	public function test_une_intention_confirmee_mais_sans_moyen_de_paiement_est_refusee(): void {
		$membership_id = $this->card_membership( 'cus_sans_moyen' );

		$intent                 = $this->confirmed_intent( 'cus_sans_moyen' );
		$intent->payment_method = null;

		$result = Migrator::apply( rcp_get_membership( $membership_id ), $intent, $this->sepa_payment_method() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'rcp_stripe_sepa_no_payment_method', $result->get_error_code() );
	}

	public function test_une_intention_non_confirmee_est_refusee(): void {
		$membership_id = $this->card_membership( 'cus_non_confirmee' );

		$intent         = $this->confirmed_intent( 'cus_non_confirmee' );
		$intent->status = 'requires_payment_method';

		$result = Migrator::apply( rcp_get_membership( $membership_id ), $intent, $this->sepa_payment_method() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'rcp_stripe_sepa_intent_not_confirmed', $result->get_error_code() );
	}

	public function test_une_intention_appartenant_a_un_autre_client_est_refusee(): void {
		/*
		 * Contrôle central : sans lui, un adhérent pourrait transmettre
		 * l'identifiant d'une intention confirmée par quelqu'un d'autre et
		 * rattacher le mandat d'un tiers à sa propre adhésion.
		 *
		 * stripe-mock renvoie une intention rattachée à un client qui n'est pas
		 * celui de l'adhésion : le refus doit être immédiat.
		 */
		$result = Migrator::complete( rcp_get_membership( $this->membership_id ), 'seti_123456789' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'rcp_stripe_sepa_foreign_intent', $result->get_error_code() );
	}

	public function test_une_adhesion_refusee_conserve_sa_passerelle(): void {
		Migrator::complete( rcp_get_membership( $this->membership_id ), 'seti_123456789' );

		$this->assertSame( 'stripe', rcp_get_membership( $this->membership_id )->get_gateway() );
	}

	public function test_une_adhesion_refusee_n_enregistre_aucun_mandat(): void {
		Migrator::complete( rcp_get_membership( $this->membership_id ), 'seti_123456789' );

		$this->assertSame( array(), MandateRepository::find( $this->membership_id ) );
	}

	public function test_un_moyen_de_paiement_qui_n_est_pas_un_mandat_est_refuse(): void {
		// Un moyen de paiement par carte ne doit pas pouvoir être enregistré
		// comme mandat SEPA.
		$membership_id = $this->card_membership( 'cus_carte' );

		$card = \Stripe\PaymentMethod::constructFrom(
			array( 'id' => 'pm_carte', 'object' => 'payment_method', 'type' => 'card' )
		);

		$result = Migrator::apply(
			rcp_get_membership( $membership_id ),
			$this->confirmed_intent( 'cus_carte' ),
			$card
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'rcp_stripe_sepa_wrong_type', $result->get_error_code() );
	}

	public function test_une_intention_introuvable_est_refusee(): void {
		$result = Migrator::complete( rcp_get_membership( $this->membership_id ), 'seti_inexistante' );

		$this->assertTrue( is_wp_error( $result ) );
	}

	// -- Bascule effective ---------------------------------------------------------------

	/**
	 * Mandat SEPA, construit localement.
	 *
	 * stripe-mock ne conserve pas les objets créés : une relecture renvoie
	 * toujours une carte. Le mandat du cas nominal est donc construit ici.
	 *
	 * @return \Stripe\PaymentMethod
	 */
	private function sepa_payment_method(): \Stripe\PaymentMethod {
		return \Stripe\PaymentMethod::constructFrom(
			array(
				'id'              => 'pm_sepa_migration',
				'object'          => 'payment_method',
				'type'            => 'sepa_debit',
				'billing_details' => array( 'name' => 'Membre Migration' ),
				'sepa_debit'      => array( 'last4' => '2606', 'country' => 'FR' ),
			)
		);
	}

	/**
	 * Intention confirmée, rattachée à un client donné.
	 *
	 * stripe-mock renvoie une intention au statut `requires_payment_method` :
	 * le cas nominal doit donc être construit localement.
	 *
	 * @param string $customer_id Client Stripe.
	 * @return \Stripe\SetupIntent
	 */
	private function confirmed_intent( string $customer_id ): \Stripe\SetupIntent {
		return \Stripe\SetupIntent::constructFrom(
			array(
				'id'             => 'seti_confirmee',
				'object'         => 'setup_intent',
				'customer'       => $customer_id,
				'status'         => 'succeeded',
				'payment_method' => 'pm_123456789',
				'mandate'        => null,
			)
		);
	}

	public function test_une_intention_du_bon_client_bascule_l_adhesion(): void {
		$membership_id = $this->card_membership( 'cus_nominal' );

		$result = Migrator::apply(
			rcp_get_membership( $membership_id ),
			$this->confirmed_intent( 'cus_nominal' ),
			$this->sepa_payment_method()
		);

		$this->assertTrue(
			true === $result,
			is_wp_error( $result ) ? $result->get_error_code() . ' — ' . $result->get_error_message() : ''
		);
		$this->assertSame(
			GatewayDefinition::ID,
			rcp_get_membership( $membership_id )->get_gateway()
		);
	}

	public function test_la_bascule_laisse_une_note_sur_l_adhesion(): void {
		$membership_id = $this->card_membership( 'cus_note' );

		Migrator::apply(
			rcp_get_membership( $membership_id ),
			$this->confirmed_intent( 'cus_note' ),
			$this->sepa_payment_method()
		);

		$notes = rcp_get_membership( $membership_id )->get_notes();

		$this->assertStringContainsString( 'migrated', $notes );
	}

	public function test_la_bascule_declenche_une_action(): void {
		$fired         = false;
		$membership_id = $this->card_membership( 'cus_action' );

		add_action(
			'rcp_stripe_sepa_migrated',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		Migrator::apply(
			rcp_get_membership( $membership_id ),
			$this->confirmed_intent( 'cus_action' ),
			$this->sepa_payment_method()
		);

		$this->assertTrue( $fired );
	}
}
