<?php
/**
 * Tests de contrat de la passerelle contre l'API Stripe.
 *
 * Ces tests pilotent la passerelle et le processeur d'inscription contre
 * `stripe-mock`, l'implémentation de référence publiée par Stripe et adossée à
 * sa spécification OpenAPI. Ils ne valident pas une règle métier : ils
 * garantissent que les requêtes émises sont acceptées, et que l'orchestration
 * s'exécute de bout en bout sans erreur.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Contract;

use RCP_Stripe_Sepa\Gateway\Gateway;
use RCP_Stripe_Sepa\Gateway\SignupProcessor;
use RCP_Stripe_Sepa\Membership\StateMachine;
use RCP_Stripe_Sepa\Support\StripeSdk;
use RCP_Stripe_Sepa\Tests\Support\RcpFixtures;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Gateway\Gateway
 * @covers \RCP_Stripe_Sepa\Gateway\SignupProcessor
 * @group contract
 */
final class GatewayStripeTest extends WP_UnitTestCase {

	use RcpFixtures;

	/**
	 * Clé secrète acceptée par stripe-mock.
	 *
	 * Le serveur refuse toute clé qui ne ressemble pas à une clé de test
	 * réelle : `sk_test_` doit être suivi de caractères alphanumériques.
	 */
	private const MOCK_SECRET_KEY = 'sk_test_123456789';

	/**
	 * Clé publiable correspondante.
	 */
	private const MOCK_PUBLISHABLE_KEY = 'pk_test_123456789';

	/**
	 * Base d'API d'origine, restaurée après chaque test.
	 *
	 * @var string
	 */
	private $api_base = '';

	/**
	 * Adhésion utilisée par les tests.
	 *
	 * @var int
	 */
	private $membership_id = 0;

	/**
	 * Paiement RCP associé.
	 *
	 * @var int
	 */
	private $payment_id = 0;

	/**
	 * Utilisateur WordPress adhérent.
	 *
	 * @var int
	 */
	private $user_id = 0;

	/**
	 * Client RCP.
	 *
	 * @var int
	 */
	private $customer_id = 0;

	/**
	 * Niveau d'adhésion.
	 *
	 * @var int
	 */
	private $level_id = 0;

	/**
	 * Clé d'abonnement, unique par test.
	 *
	 * @var string
	 */
	private $subscription_key = '';

	public function set_up(): void {
		parent::set_up();

		global $rcp_options;

		$rcp_options             = is_array( $rcp_options ) ? $rcp_options : array();
		$rcp_options['sandbox']  = 1;
		$rcp_options['currency'] = 'EUR';

		/*
		 * `RCP_Payment_Gateway_Stripe::init()` impose la clé lue dans les
		 * réglages : sans elle, toute requête partirait sans authentification.
		 * stripe-mock accepte n'importe quelle clé.
		 */
		$rcp_options['stripe_test_secret']      = self::MOCK_SECRET_KEY;
		$rcp_options['stripe_test_publishable'] = self::MOCK_PUBLISHABLE_KEY;

		$this->assertTrue( StripeSdk::ensure_loaded() );

		$this->api_base            = \Stripe\Stripe::$apiBase;
		\Stripe\Stripe::$apiBase   = $this->mock_base();

		\Stripe\Stripe::setApiKey( self::MOCK_SECRET_KEY );

		$this->skip_unless_mock_reachable();

		$this->create_membership_with_payment();
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
	 * Interrompt la suite si stripe-mock n'est pas joignable.
	 *
	 * Le service fait partie de la pile de développement et de l'intégration
	 * continue : son absence est une anomalie d'environnement, pas une raison
	 * d'ignorer silencieusement des tests.
	 *
	 * @return void
	 */
	private function skip_unless_mock_reachable(): void {
		$response = wp_remote_get( $this->mock_base() . '/v1/payment_intents', array( 'timeout' => 5 ) );

		$this->assertFalse(
			is_wp_error( $response ),
			sprintf(
				'stripe-mock injoignable sur %s. Démarrez la pile : make up',
				$this->mock_base()
			)
		);
	}

	/**
	 * Crée une adhésion et son enregistrement de paiement.
	 *
	 * @return void
	 */
	private function create_membership_with_payment(): void {
		global $rcp_payments_db;

		$this->subscription_key = 'key_' . wp_generate_password( 12, false );
		$this->user_id          = $this->create_user();
		$this->customer_id      = $this->create_customer( $this->user_id );
		$this->level_id         = $this->create_level();

		$this->membership_id = $this->create_membership(
			array(
				'user_id'          => $this->user_id,
				'customer_id'      => $this->customer_id,
				'object_id'        => $this->level_id,
				'status'           => 'pending',
				'gateway'          => Gateway::GATEWAY_ID,
				'subscription_key' => $this->subscription_key,
				'expiration_date'  => gmdate( 'Y-m-d 23:59:59', strtotime( '+1 month' ) ),
			)
		);

		$payment = $rcp_payments_db->insert(
			array(
				'subscription'     => 'Mensuel',
				'object_id'        => $this->level_id,
				'subscription_key' => $this->subscription_key,
				'amount'           => 10,
				'user_id'          => $this->user_id,
				'customer_id'      => $this->customer_id,
				'membership_id'    => $this->membership_id,
				'status'           => 'pending',
				'gateway'          => Gateway::GATEWAY_ID,
			)
		);

		$this->assertNotWPError( $payment, 'Création de paiement' );

		$this->payment_id = (int) $payment;

		$this->assertGreaterThan( 0, $this->payment_id );
	}

	/**
	 * Instancie la passerelle avec des données d'inscription complètes.
	 *
	 * @param array $overrides Valeurs à remplacer.
	 * @return Gateway
	 */
	private function gateway( array $overrides = array() ): Gateway {
		$data = array_merge(
			array(
				'user_email'              => 'membre@example.test',
				'user_id'                 => $this->user_id,
				'user_name'               => 'membre',
				'currency'                => 'EUR',
				'recurring_price'         => 10,
				'initial_price'           => 10,
				'discount'                => 0,
				'discount_code'           => '',
				'length'                  => 1,
				'length_unit'             => 'month',
				'fee'                     => 0,
				'key'                     => $this->subscription_key,
				'subscription_id'         => $this->level_id,
				'subscription_name'       => 'Mensuel',
				'auto_renew'              => true,
				'return_url'              => home_url( '/merci/' ),
				'payment_id'              => $this->payment_id,
				'customer'                => rcp_get_customer( $this->customer_id ),
				'membership_id'           => $this->membership_id,
				'subscription_start_date' => '',
			),
			$overrides
		);

		return new Gateway( $data );
	}

	// -- Client Stripe -------------------------------------------------------------

	public function test_le_client_stripe_est_cree_ou_retrouve(): void {
		$customer = $this->gateway()->stripe_customer();

		$this->assertFalse(
			is_wp_error( $customer ),
			is_wp_error( $customer )
				? sprintf( 'code=%s message=%s', (string) $customer->get_error_code(), (string) $customer->get_error_message() )
				: ''
		);
		$this->assertStringStartsWith( 'cus_', (string) $customer->id );
	}

	// -- Création de l'intention -------------------------------------------------

	public function test_l_inscription_ajax_cree_une_intention_de_paiement(): void {
		$result = $this->gateway()->process_ajax_signup();

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertNotEmpty( $result['stripe_client_secret'] );
		$this->assertSame( 'payment_intent', $result['stripe_intent_type'] );
	}

	public function test_l_identifiant_d_intention_est_conserve_avec_le_paiement(): void {
		global $rcp_payments_db;

		$this->gateway()->process_ajax_signup();

		$stored = $rcp_payments_db->get_meta( $this->payment_id, 'stripe_payment_intent_id', true );

		$this->assertNotEmpty( $stored );
		$this->assertStringStartsWith( 'pi_', (string) $stored );
	}

	public function test_une_adhesion_sans_montant_initial_cree_une_intention_d_enregistrement(): void {
		// Adhésion gratuite ou remise de 100 % : rien à encaisser, mais un
		// mandat à recueillir pour les échéances suivantes.
		$result = $this->gateway( array( 'initial_price' => 0 ) )->process_ajax_signup();

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'setup_intent', $result['stripe_intent_type'] );
	}

	/**
	 * @group RG-02
	 */
	public function test_une_devise_non_euro_interrompt_l_inscription(): void {
		global $rcp_options;

		$rcp_options['currency'] = 'USD';

		$result = $this->gateway()->process_ajax_signup();

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'rcp_stripe_sepa_invalid_currency', $result->get_error_code() );
	}

	// -- Finalisation de l'inscription ---------------------------------------------

	public function test_l_inscription_laisse_le_paiement_en_attente(): void {
		/*
		 * Un prélèvement SEPA n'aboutit jamais au moment de l'inscription :
		 * l'encaissement est confirmé par webhook, deux à quatorze jours plus
		 * tard. Conclure ici à un paiement abouti ouvrirait l'accès au contenu
		 * avant tout encaissement.
		 */
		global $rcp_payments_db;

		$gateway = $this->gateway();
		$intent  = \Stripe\PaymentIntent::create(
			array( 'amount' => 1000, 'currency' => 'eur', 'payment_method_types' => array( 'sepa_debit' ) )
		);

		( new SignupProcessor( $gateway ) )->run(
			$intent,
			rcp_get_membership( $this->membership_id ),
			$rcp_payments_db->get_payment( $this->payment_id )
		);

		$payment = $rcp_payments_db->get_payment( $this->payment_id );

		$this->assertSame( StateMachine::PAYMENT_PENDING, $payment->status );
	}

	/**
	 * @group RG-01
	 */
	public function test_l_adhesion_n_est_pas_activee_a_l_inscription(): void {
		global $rcp_payments_db;

		$gateway = $this->gateway();
		$intent  = \Stripe\PaymentIntent::create(
			array( 'amount' => 1000, 'currency' => 'eur', 'payment_method_types' => array( 'sepa_debit' ) )
		);

		( new SignupProcessor( $gateway ) )->run(
			$intent,
			rcp_get_membership( $this->membership_id ),
			$rcp_payments_db->get_payment( $this->payment_id )
		);

		$this->assertSame(
			StateMachine::MEMBERSHIP_PENDING,
			rcp_get_membership( $this->membership_id )->get_status()
		);
	}

	public function test_le_type_de_paiement_enregistre_identifie_le_prelevement(): void {
		global $rcp_payments_db;

		$gateway = $this->gateway();
		$intent  = \Stripe\PaymentIntent::create(
			array( 'amount' => 1000, 'currency' => 'eur', 'payment_method_types' => array( 'sepa_debit' ) )
		);

		( new SignupProcessor( $gateway ) )->run(
			$intent,
			rcp_get_membership( $this->membership_id ),
			$rcp_payments_db->get_payment( $this->payment_id )
		);

		$this->assertStringContainsString(
			'SEPA',
			(string) $rcp_payments_db->get_payment( $this->payment_id )->payment_type
		);
	}

	/**
	 * Construit une intention portant un moyen de paiement.
	 *
	 * `constructFrom()` fabrique l'objet localement : stripe-mock ne renvoie
	 * pas les champs transmis, et le parcours complet exige une intention
	 * effectivement rattachée à un moyen de paiement et à un client.
	 *
	 * @param array $overrides Champs à remplacer.
	 * @return \Stripe\PaymentIntent
	 */
	private function confirmed_intent( array $overrides = array() ): \Stripe\PaymentIntent {
		$customer       = $this->gateway()->stripe_customer();
		$payment_method = \Stripe\PaymentMethod::retrieve( 'pm_123456789' );

		return \Stripe\PaymentIntent::constructFrom(
			array_merge(
				array(
					'id'             => 'pi_contract_123',
					'object'         => 'payment_intent',
					'customer'       => $customer->id,
					'payment_method' => $payment_method->id,
					'status'         => 'processing',
					'latest_charge'  => 'py_contract_123',
				),
				$overrides
			)
		);
	}

	/**
	 * Exécute la finalisation d'inscription.
	 *
	 * @param Gateway                $gateway Passerelle.
	 * @param \Stripe\PaymentIntent $intent  Intention confirmée.
	 * @return void
	 */
	private function finalize( Gateway $gateway, \Stripe\PaymentIntent $intent ): void {
		global $rcp_payments_db;

		( new SignupProcessor( $gateway ) )->run(
			$intent,
			rcp_get_membership( $this->membership_id ),
			$rcp_payments_db->get_payment( $this->payment_id )
		);
	}

	public function test_le_mandat_est_rattache_au_client_stripe(): void {
		$this->finalize( $this->gateway(), $this->confirmed_intent() );

		$this->assertSame(
			StateMachine::MEMBERSHIP_PENDING,
			rcp_get_membership( $this->membership_id )->get_status()
		);
	}

	public function test_la_charge_est_enregistree_comme_identifiant_de_transaction(): void {
		// Les charges SEPA portent un identifiant `py_`, non `ch_`.
		global $rcp_payments_db;

		$this->finalize( $this->gateway(), $this->confirmed_intent() );

		$this->assertSame(
			'py_contract_123',
			(string) $rcp_payments_db->get_payment( $this->payment_id )->transaction_id
		);
	}

	public function test_une_intention_sans_charge_n_enregistre_aucune_transaction(): void {
		global $rcp_payments_db;

		$this->finalize( $this->gateway(), $this->confirmed_intent( array( 'latest_charge' => null ) ) );

		$this->assertEmpty( $rcp_payments_db->get_payment( $this->payment_id )->transaction_id );
	}

	/**
	 * @group F-02
	 */
	public function test_une_adhesion_reconductible_cree_un_abonnement(): void {
		$this->finalize( $this->gateway(), $this->confirmed_intent() );

		$membership = rcp_get_membership( $this->membership_id );

		$this->assertStringStartsWith(
			'sub_',
			(string) $membership->get_gateway_subscription_id(),
			'Notes de l\'adhésion : ' . $membership->get_notes()
		);
	}

	public function test_un_abonnement_precedent_est_resilie(): void {
		/*
		 * Un renouvellement manuel alors qu'un abonnement subsiste produirait
		 * deux prélèvements pour la même période.
		 */
		$membership = rcp_get_membership( $this->membership_id );
		$membership->set_gateway_subscription_id( 'sub_precedent_123' );

		$this->finalize( $this->gateway(), $this->confirmed_intent() );

		$this->assertNotSame(
			'sub_precedent_123',
			(string) rcp_get_membership( $this->membership_id )->get_gateway_subscription_id()
		);
	}

	public function test_une_intention_sans_moyen_de_paiement_n_interrompt_pas_l_inscription(): void {
		// Cas dégradé : le mandat n'a pas pu être rattaché. L'inscription doit
		// se terminer proprement, le webhook tranchera.
		$this->finalize( $this->gateway(), $this->confirmed_intent( array( 'payment_method' => null ) ) );

		$this->assertSame(
			StateMachine::MEMBERSHIP_PENDING,
			rcp_get_membership( $this->membership_id )->get_status()
		);
	}

	/**
	 * @group F-03
	 */
	public function test_une_adhesion_non_reconductible_ne_cree_pas_d_abonnement(): void {
		// Adhésion à vie : un seul prélèvement, aucun mandat récurrent.
		global $rcp_payments_db;

		$this->finalize( $this->gateway( array( 'auto_renew' => false ) ), $this->confirmed_intent() );

		$this->assertSame( '', rcp_get_membership( $this->membership_id )->get_gateway_subscription_id() );
	}
}
