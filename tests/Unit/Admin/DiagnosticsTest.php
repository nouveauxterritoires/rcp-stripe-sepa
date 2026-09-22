<?php
/**
 * Tests du rapport de diagnostic.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Admin\Diagnostics;

/**
 * @covers \RCP_Stripe_Sepa\Admin\Diagnostics
 */
final class DiagnosticsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );

		// Les formes plurielles ne sont pas l'objet de ces tests : la forme
		// choisie importe moins que la valeur interpolée.
		Functions\when( '_n' )->alias(
			static function ( $single, $plural, $number ) {
				return 1 === (int) $number ? $single : $plural;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Contexte d'une installation saine.
	 *
	 * @param array $overrides Valeurs à remplacer.
	 * @return array
	 */
	private function context( array $overrides = array() ): array {
		return array_merge(
			array(
				'rcp_supported'   => true,
				'rcp_issues'      => array(),
				'gateway_enabled' => true,
				'currency'        => 'EUR',
				'is_https'        => true,
				'test_mode'       => true,
				'secret_present'  => true,
				'secret_locked'   => true,
				'events_total'    => 12,
				'events_failed'   => 0,
				'stale_payments'  => 0,
				'stale_after'     => 14,
			),
			$overrides
		);
	}

	/**
	 * Recherche un contrôle par identifiant.
	 *
	 * @param array  $report Rapport produit.
	 * @param string $id     Identifiant du contrôle.
	 * @return array
	 */
	private function check( array $report, string $id ): array {
		foreach ( $report['checks'] as $check ) {
			if ( $id === $check['id'] ) {
				return $check;
			}
		}

		$this->fail( 'Contrôle absent du rapport : ' . $id );
	}

	// -- Installation saine ------------------------------------------------------

	public function test_une_installation_saine_ne_signale_rien(): void {
		$report = Diagnostics::build( $this->context() );

		$this->assertSame( Diagnostics::STATUS_OK, $report['status'] );
		$this->assertSame( array(), $report['problems'] );
	}

	public function test_chaque_controle_porte_un_identifiant_et_un_statut(): void {
		foreach ( Diagnostics::build( $this->context() )['checks'] as $check ) {
			$this->assertArrayHasKey( 'id', $check );
			$this->assertArrayHasKey( 'status', $check );
			$this->assertContains(
				$check['status'],
				array( Diagnostics::STATUS_OK, Diagnostics::STATUS_WARNING, Diagnostics::STATUS_ERROR )
			);
		}
	}

	// -- Anomalies bloquantes ------------------------------------------------------

	public function test_un_environnement_rcp_incompatible_est_bloquant(): void {
		$report = Diagnostics::build(
			$this->context( array( 'rcp_supported' => false, 'rcp_issues' => array( 'legacy_mode' ) ) )
		);

		$this->assertSame( Diagnostics::STATUS_ERROR, $report['status'] );
		$this->assertSame( Diagnostics::STATUS_ERROR, $this->check( $report, 'rcp' )['status'] );
	}

	public function test_une_devise_autre_que_l_euro_est_bloquante(): void {
		$report = Diagnostics::build( $this->context( array( 'currency' => 'USD' ) ) );

		$this->assertSame( Diagnostics::STATUS_ERROR, $this->check( $report, 'currency' )['status'] );
		$this->assertStringContainsString( 'USD', $this->check( $report, 'currency' )['detail'] );
	}

	public function test_un_secret_de_webhook_absent_est_bloquant(): void {
		// Sans secret, aucun webhook ne peut être authentifié : les adhésions
		// resteraient indéfiniment en attente.
		$report = Diagnostics::build( $this->context( array( 'secret_present' => false ) ) );

		$this->assertSame( Diagnostics::STATUS_ERROR, $this->check( $report, 'webhook_secret' )['status'] );
	}

	public function test_l_absence_de_https_est_bloquante_en_production(): void {
		$report = Diagnostics::build( $this->context( array( 'is_https' => false, 'test_mode' => false ) ) );

		$this->assertSame( Diagnostics::STATUS_ERROR, $this->check( $report, 'https' )['status'] );
	}

	public function test_l_absence_de_https_est_toleree_en_developpement(): void {
		// Un environnement local en HTTP est la norme ; le signaler comme une
		// erreur noierait les vrais problèmes.
		$report = Diagnostics::build( $this->context( array( 'is_https' => false, 'test_mode' => true ) ) );

		$this->assertSame( Diagnostics::STATUS_WARNING, $this->check( $report, 'https' )['status'] );
	}

	// -- Avertissements ---------------------------------------------------------------

	public function test_une_passerelle_desactivee_est_signalee(): void {
		$report = Diagnostics::build( $this->context( array( 'gateway_enabled' => false ) ) );

		$this->assertSame( Diagnostics::STATUS_WARNING, $this->check( $report, 'gateway' )['status'] );
	}

	public function test_un_secret_stocke_en_base_est_signale(): void {
		// SEC-02 : une constante de wp-config ne fuite ni dans un export ni
		// dans une sauvegarde partagée.
		$report = Diagnostics::build( $this->context( array( 'secret_locked' => false ) ) );

		$this->assertSame( Diagnostics::STATUS_WARNING, $this->check( $report, 'webhook_secret' )['status'] );
	}

	public function test_l_absence_totale_d_evenements_est_signalee(): void {
		// Un point de terminaison qui n'a jamais rien reçu est le symptôme le
		// plus courant d'une configuration incomplète chez Stripe.
		$report = Diagnostics::build( $this->context( array( 'events_total' => 0 ) ) );

		$this->assertSame( Diagnostics::STATUS_WARNING, $this->check( $report, 'events' )['status'] );
	}

	public function test_des_evenements_en_echec_sont_signales(): void {
		$report = Diagnostics::build( $this->context( array( 'events_failed' => 3 ) ) );

		$this->assertSame( Diagnostics::STATUS_ERROR, $this->check( $report, 'events' )['status'] );
		$this->assertStringContainsString( '3', $this->check( $report, 'events' )['detail'] );
	}

	public function test_des_prelevements_trop_longs_sont_signales(): void {
		// Au-delà du délai configuré, un prélèvement encore en attente trahit
		// un webhook qui n'arrive pas.
		$report = Diagnostics::build( $this->context( array( 'stale_payments' => 2 ) ) );

		$this->assertSame( Diagnostics::STATUS_WARNING, $this->check( $report, 'stale_payments' )['status'] );
		$this->assertStringContainsString( '14', $this->check( $report, 'stale_payments' )['detail'] );
	}

	// -- Synthèse -----------------------------------------------------------------------

	public function test_le_statut_global_retient_le_plus_grave(): void {
		$report = Diagnostics::build(
			$this->context( array( 'gateway_enabled' => false, 'currency' => 'USD' ) )
		);

		$this->assertSame( Diagnostics::STATUS_ERROR, $report['status'] );
	}

	public function test_les_problemes_sont_listes_du_plus_grave_au_moins_grave(): void {
		$report = Diagnostics::build(
			$this->context( array( 'gateway_enabled' => false, 'currency' => 'USD' ) )
		);

		$this->assertCount( 2, $report['problems'] );
		$this->assertSame( Diagnostics::STATUS_ERROR, $report['problems'][0]['status'] );
		$this->assertSame( Diagnostics::STATUS_WARNING, $report['problems'][1]['status'] );
	}

	public function test_le_mode_courant_est_rapporte(): void {
		$this->assertTrue( Diagnostics::build( $this->context() )['test_mode'] );
		$this->assertFalse( Diagnostics::build( $this->context( array( 'test_mode' => false ) ) )['test_mode'] );
	}

	// -- Confidentialité -------------------------------------------------------------------

	public function test_le_rapport_ne_contient_aucun_secret(): void {
		// Le diagnostic est destiné au support : il peut être copié dans un
		// ticket.
		$serialized = (string) json_encode( Diagnostics::build( $this->context() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		$this->assertStringNotContainsString( 'whsec_', $serialized );
		$this->assertStringNotContainsString( 'sk_test', $serialized );
		$this->assertStringNotContainsString( 'sk_live', $serialized );
	}

	public function test_le_contexte_fourni_n_est_pas_mute(): void {
		$context = $this->context();
		$copy    = $context;

		Diagnostics::build( $context );

		$this->assertSame( $copy, $context );
	}
}
