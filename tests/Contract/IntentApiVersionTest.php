<?php
/**
 * Tests de contrat des deux formes d'arguments d'intention.
 *
 * Le retrait de `payment_method_types` à la création d'une intention, entré en
 * vigueur avec la version d'API `2026-08-26.preview` puis repris par
 * `2026-09-30.endive`, oblige le plugin à produire deux formes d'arguments.
 * Les tests unitaires établissent que la bonne forme est choisie ; ceux-ci
 * établissent que chacune est réellement acceptée par l'API.
 *
 * Ils s'exécutent contre `stripe-mock`, épinglé à une version dont la
 * spécification connaît les deux paramètres — c'est la condition qui fixe ce
 * pin, et non plus le seul besoin de faire passer la forme historique.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Contract;

use RCP_Stripe_Sepa\Gateway\IntentFactory;
use RCP_Stripe_Sepa\Support\StripeApiVersion;
use RCP_Stripe_Sepa\Support\StripeSdk;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Gateway\IntentFactory
 * @covers \RCP_Stripe_Sepa\Support\StripeApiVersion
 * @group contract
 */
final class IntentApiVersionTest extends WP_UnitTestCase {

	/**
	 * Clé secrète acceptée par stripe-mock.
	 */
	private const MOCK_SECRET_KEY = 'sk_test_123456789';

	/**
	 * Base d'API d'origine, restaurée après chaque test.
	 *
	 * @var string
	 */
	private $api_base = '';

	public function set_up(): void {
		parent::set_up();

		$this->assertTrue( StripeSdk::ensure_loaded() );

		$this->api_base          = \Stripe\Stripe::$apiBase;
		\Stripe\Stripe::$apiBase = $this->mock_base();

		\Stripe\Stripe::setApiKey( self::MOCK_SECRET_KEY );

		$this->skip_unless_mock_reachable();
	}

	public function tear_down(): void {
		\Stripe\Stripe::$apiBase = $this->api_base;

		parent::tear_down();
	}

	/**
	 * Versions couvertes, et paramètre attendu pour chacune.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_api_versions(): array {
		return array(
			'version par défaut du plugin' => array( '2022-11-15', StripeApiVersion::LEGACY_INTENT_PARAMETER ),
			'version endive'               => array( '2026-09-30.endive', StripeApiVersion::CURRENT_INTENT_PARAMETER ),
		);
	}

	/**
	 * @dataProvider provide_api_versions
	 *
	 * @param string $version   Version d'API visée.
	 * @param string $parameter Paramètre attendu dans les arguments.
	 */
	public function test_l_intention_de_paiement_est_acceptee( string $version, string $parameter ): void {
		$args = IntentFactory::payment_intent( $this->context( $version ) );

		$this->assertSame( array( 'sepa_debit' ), $args[ $parameter ] );

		$intent = \Stripe\PaymentIntent::create( $args, array( 'stripe_version' => $version ) );

		$this->assertNotEmpty( $intent->id );
	}

	/**
	 * @dataProvider provide_api_versions
	 *
	 * @param string $version   Version d'API visée.
	 * @param string $parameter Paramètre attendu dans les arguments.
	 */
	public function test_l_intention_d_enregistrement_est_acceptee( string $version, string $parameter ): void {
		$args = IntentFactory::setup_intent( $this->context( $version, 0 ) );

		$this->assertSame( array( 'sepa_debit' ), $args[ $parameter ] );

		$intent = \Stripe\SetupIntent::create( $args, array( 'stripe_version' => $version ) );

		$this->assertNotEmpty( $intent->id );
	}

	/**
	 * Le préfixe de référence de mandat voyage dans `payment_method_options`,
	 * que le retrait ne touche pas : les deux formes doivent l'accepter.
	 *
	 * @dataProvider provide_api_versions
	 *
	 * @param string $version Version d'API visée.
	 */
	public function test_le_prefixe_de_reference_de_mandat_reste_accepte( string $version ): void {
		$args = IntentFactory::payment_intent(
			$this->context( $version ) + array( 'statement_descriptor' => 'ASSOCIATION' )
		);

		$intent = \Stripe\PaymentIntent::create( $args, array( 'stripe_version' => $version ) );

		$this->assertNotEmpty( $intent->id );
	}

	/**
	 * Contexte d'inscription minimal.
	 *
	 * @param string $version Version d'API visée.
	 * @param int    $amount  Montant à encaisser.
	 * @return array
	 */
	private function context( string $version, int $amount = 1000 ): array {
		return array(
			'customer_id' => 'cus_123',
			'amount'      => $amount,
			'currency'    => 'eur',
			'description' => 'Adhésion mensuelle',
			'recurring'   => true,
			'metadata'    => array( 'rcp_membership_id' => '42' ),
			'api_version' => $version,
		);
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
	 * @return void
	 */
	private function skip_unless_mock_reachable(): void {
		$response = wp_remote_get( $this->mock_base() . '/v1/payment_intents', array( 'timeout' => 5 ) );

		$this->assertFalse(
			is_wp_error( $response ),
			sprintf( 'stripe-mock injoignable sur %s. Démarrez la pile : make up', $this->mock_base() )
		);
	}
}
