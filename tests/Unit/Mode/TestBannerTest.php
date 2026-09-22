<?php
/**
 * Tests du bandeau de mode test.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Mode;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Mode\TestBanner;

/**
 * @covers \RCP_Stripe_Sepa\Mode\TestBanner
 */
final class TestBannerTest extends TestCase {

	/**
	 * Prépare les doublures de fonctions WordPress.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->alias( 'htmlspecialchars' );
	}

	/**
	 * Restaure l'état global.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @group SEC-23
	 */
	public function test_le_bandeau_s_affiche_en_mode_test(): void {
		Functions\when( 'rcp_is_sandbox' )->justReturn( true );

		$html = TestBanner::render();

		$this->assertStringContainsString( 'rcp-stripe-sepa-test-mode', $html );
		$this->assertStringContainsString( 'test mode', strtolower( $html ) );
	}

	/**
	 * @group SEC-23
	 */
	public function test_aucun_bandeau_hors_mode_test(): void {
		Functions\when( 'rcp_is_sandbox' )->justReturn( false );

		$this->assertSame( '', TestBanner::render() );
	}

	/**
	 * Un bandeau que l'on peut fermer ne prévient qu'une fois. Celui-ci ne
	 * porte donc pas la classe qui, dans WordPress, rend un avis masquable.
	 *
	 * @group SEC-23
	 */
	public function test_le_bandeau_n_est_pas_masquable(): void {
		Functions\when( 'rcp_is_sandbox' )->justReturn( true );

		$html = TestBanner::render();

		$this->assertStringNotContainsString( 'is-dismissible', $html );
		$this->assertStringNotContainsString( 'notice-dismiss', $html );
	}

	/**
	 * Le message est destiné à l'adhérent autant qu'à l'administrateur : il
	 * doit dire ce qui ne se produira pas, non nommer un réglage.
	 */
	public function test_le_message_annonce_l_absence_de_prelevement(): void {
		Functions\when( 'rcp_is_sandbox' )->justReturn( true );

		$this->assertMatchesRegularExpression( '/no real payment/i', TestBanner::message() );
	}

	public function test_le_bandeau_echappe_son_contenu(): void {
		Functions\when( 'rcp_is_sandbox' )->justReturn( true );

		$this->assertStringNotContainsString( '<script', TestBanner::render() );
	}
}
