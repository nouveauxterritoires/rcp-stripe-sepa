<?php
/**
 * Tests de l'accès au SDK Stripe.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Support\StripeSdk;

/**
 * La clé secrète conditionne tout appel à l'API : une requête AJAX ou un
 * webhook n'instancie aucune passerelle RCP, et partirait donc sans clé.
 *
 * @covers \RCP_Stripe_Sepa\Support\StripeSdk
 */
final class StripeSdkTest extends TestCase {

	/**
	 * Prépare les doublures de fonctions WordPress.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Restaure l'état global entre deux tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['rcp_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_lit_la_cle_de_test_en_bac_a_sable(): void {
		Functions\when( 'rcp_is_sandbox' )->justReturn( true );
		$GLOBALS['rcp_options'] = array(
			'stripe_test_secret' => 'sk_test_lue',
			'stripe_live_secret' => 'sk_live_ignoree',
		);

		$this->assertSame( 'sk_test_lue', StripeSdk::secret_key() );
	}

	public function test_lit_la_cle_de_production_hors_bac_a_sable(): void {
		Functions\when( 'rcp_is_sandbox' )->justReturn( false );
		$GLOBALS['rcp_options'] = array(
			'stripe_test_secret' => 'sk_test_ignoree',
			'stripe_live_secret' => 'sk_live_lue',
		);

		$this->assertSame( 'sk_live_lue', StripeSdk::secret_key() );
	}

	/**
	 * Les réglages de RCP conservent la saisie telle quelle, espaces compris.
	 */
	public function test_elague_les_espaces_autour_de_la_cle(): void {
		Functions\when( 'rcp_is_sandbox' )->justReturn( true );
		$GLOBALS['rcp_options'] = array( 'stripe_test_secret' => "  sk_test_entouree \n" );

		$this->assertSame( 'sk_test_entouree', StripeSdk::secret_key() );
	}

	public function test_renvoie_une_chaine_vide_quand_la_cle_manque(): void {
		Functions\when( 'rcp_is_sandbox' )->justReturn( true );
		$GLOBALS['rcp_options'] = array( 'stripe_live_secret' => 'sk_live_hors_sujet' );

		$this->assertSame( '', StripeSdk::secret_key() );
	}

	/**
	 * Avant le chargement de RCP, la variable globale n'existe pas encore.
	 */
	public function test_renvoie_une_chaine_vide_sans_reglages_rcp(): void {
		Functions\when( 'rcp_is_sandbox' )->justReturn( true );
		unset( $GLOBALS['rcp_options'] );

		$this->assertSame( '', StripeSdk::secret_key() );
	}

	/**
	 * Sans clé, mieux vaut un refus net qu'un appel rejeté par Stripe.
	 */
	public function test_ne_se_declare_pas_prete_sans_cle(): void {
		Functions\when( 'rcp_is_sandbox' )->justReturn( true );
		$GLOBALS['rcp_options'] = array( 'stripe_test_secret' => '' );

		$this->assertFalse( StripeSdk::ensure_ready() );
	}

	/**
	 * La version d'API transmise reste distincte de celle que RCP fixe
	 * globalement, que le plugin ne doit jamais modifier.
	 */
	public function test_joint_sa_version_d_api_a_chaque_requete(): void {
		$options = StripeSdk::request_options( array( 'idempotency_key' => 'abc' ) );

		$this->assertSame( StripeSdk::api_version(), $options['stripe_version'] );
		$this->assertSame( 'abc', $options['idempotency_key'] );
	}
}
