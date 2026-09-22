<?php
/**
 * Tests du garde-fou de cohérence entre mode et clé Stripe.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Mode;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Mode\ModeGuard;

/**
 * @covers \RCP_Stripe_Sepa\Mode\ModeGuard
 */
final class ModeGuardTest extends TestCase {

	/**
	 * Prépare les doublures de fonctions WordPress.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
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
	 * Le cas nommé T-SEC-24 au cahier des charges.
	 *
	 * @group SEC-24
	 */
	public function test_une_cle_de_production_en_mode_test_bloque_la_passerelle(): void {
		$this->assertSame( ModeGuard::STATUS_LIVE_KEY, ModeGuard::assess( 'sk_live_abcdef', true ) );
		$this->assertFalse( ModeGuard::allows_gateway( 'sk_live_abcdef', true ) );
		$this->assertNotSame( '', ModeGuard::message( 'sk_live_abcdef', true ) );
	}

	/**
	 * L'inverse est tout aussi grave : le site encaisserait dans le vide.
	 *
	 * @group SEC-24
	 */
	public function test_une_cle_de_test_en_production_bloque_la_passerelle(): void {
		$this->assertSame( ModeGuard::STATUS_TEST_KEY, ModeGuard::assess( 'sk_test_abcdef', false ) );
		$this->assertFalse( ModeGuard::allows_gateway( 'sk_test_abcdef', false ) );
		$this->assertNotSame( '', ModeGuard::message( 'sk_test_abcdef', false ) );
	}

	/**
	 * Une clé restreinte porte le même risque qu'une clé secrète.
	 *
	 * @group SEC-24
	 */
	public function test_une_cle_restreinte_de_production_est_traitee_comme_une_cle_secrete(): void {
		$this->assertSame( ModeGuard::STATUS_LIVE_KEY, ModeGuard::assess( 'rk_live_abcdef', true ) );
	}

	public function test_les_combinaisons_coherentes_sont_acceptees(): void {
		$this->assertSame( ModeGuard::STATUS_OK, ModeGuard::assess( 'sk_test_abcdef', true ) );
		$this->assertSame( ModeGuard::STATUS_OK, ModeGuard::assess( 'sk_live_abcdef', false ) );
		$this->assertTrue( ModeGuard::allows_gateway( 'sk_test_abcdef', true ) );
		$this->assertTrue( ModeGuard::allows_gateway( 'sk_live_abcdef', false ) );
	}

	/**
	 * Une installation qui n'a pas encore de clé n'est pas incohérente : la
	 * passerelle reste proposée, et l'écran de diagnostic signale l'absence.
	 */
	public function test_une_cle_absente_n_est_pas_une_incoherence(): void {
		$this->assertSame( ModeGuard::STATUS_NO_KEY, ModeGuard::assess( '', true ) );
		$this->assertTrue( ModeGuard::allows_gateway( '', true ) );
		$this->assertSame( '', ModeGuard::message( '', true ) );
	}

	/**
	 * Les espaces autour d'une clé collée à la main ne doivent pas masquer son
	 * préfixe.
	 */
	public function test_les_espaces_autour_de_la_cle_sont_ignores(): void {
		$this->assertSame( ModeGuard::STATUS_LIVE_KEY, ModeGuard::assess( "  sk_live_abcdef \n", true ) );
	}

	/**
	 * Une clé d'une forme inattendue ne doit pas bloquer le site : le plugin ne
	 * sait rien en conclure, et laisse Stripe trancher.
	 */
	public function test_une_cle_de_forme_inconnue_n_est_pas_bloquante(): void {
		$this->assertSame( ModeGuard::STATUS_OK, ModeGuard::assess( 'whsec_autre_chose', true ) );
		$this->assertTrue( ModeGuard::allows_gateway( 'whsec_autre_chose', true ) );
	}
}
