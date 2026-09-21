<?php
/**
 * Tests de la programmation des abonnements.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Gateway;

use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Gateway\SubscriptionSchedule;

/**
 * @covers \RCP_Stripe_Sepa\Gateway\SubscriptionSchedule
 */
final class SubscriptionScheduleTest extends TestCase {

	/**
	 * Arguments d'abonnement minimaux.
	 *
	 * @return array
	 */
	private function args(): array {
		return array( 'customer' => 'cus_1', 'plan' => 'plan_1' );
	}

	public function test_une_echeance_proche_utilise_l_ancrage_du_cycle(): void {
		// L'ancrage préserve le calcul du revenu récurrent chez Stripe.
		$args = SubscriptionSchedule::apply( $this->args(), 1790000000, 1790900000, false );

		$this->assertSame( 1790000000, $args['billing_cycle_anchor'] );
		$this->assertArrayNotHasKey( 'trial_end', $args );
	}

	public function test_une_echeance_trop_lointaine_bascule_sur_une_fin_d_essai(): void {
		// Stripe refuse un ancrage au-delà d'un cycle de facturation.
		$args = SubscriptionSchedule::apply( $this->args(), 1790900001, 1790900000, false );

		$this->assertSame( 1790900001, $args['trial_end'] );
		$this->assertArrayNotHasKey( 'billing_cycle_anchor', $args );
	}

	public function test_une_periode_d_essai_impose_la_fin_d_essai(): void {
		$args = SubscriptionSchedule::apply( $this->args(), 1790000000, 1790900000, true );

		$this->assertSame( 1790000000, $args['trial_end'] );
		$this->assertArrayNotHasKey( 'billing_cycle_anchor', $args );
	}

	public function test_une_echeance_exactement_a_la_limite_reste_un_ancrage(): void {
		$args = SubscriptionSchedule::apply( $this->args(), 1790900000, 1790900000, false );

		$this->assertArrayHasKey( 'billing_cycle_anchor', $args );
	}

	public function test_les_deux_mecanismes_ne_sont_jamais_combines(): void {
		/*
		 * Combiner `trial_end` et `billing_cycle_anchor` produit chez Stripe
		 * une date de prochaine échéance imprévisible.
		 */
		foreach ( array( array( 1790000000, false ), array( 1790900001, false ), array( 1790000000, true ) ) as $case ) {
			$args = SubscriptionSchedule::apply( $this->args(), $case[0], 1790900000, $case[1] );

			$this->assertFalse(
				isset( $args['trial_end'], $args['billing_cycle_anchor'] ),
				'Les deux mécanismes ont été appliqués simultanément.'
			);
		}
	}

	public function test_le_mecanisme_retenu_est_lisible(): void {
		$anchored = SubscriptionSchedule::apply( $this->args(), 1790000000, 1790900000, false );
		$trialed  = SubscriptionSchedule::apply( $this->args(), 1790000000, 1790900000, true );

		$this->assertFalse( SubscriptionSchedule::uses_trial( $anchored ) );
		$this->assertTrue( SubscriptionSchedule::uses_trial( $trialed ) );
	}

	public function test_les_arguments_fournis_ne_sont_pas_mutes(): void {
		$args = $this->args();
		$copy = $args;

		SubscriptionSchedule::apply( $args, 1790000000, 1790900000, false );

		$this->assertSame( $copy, $args );
	}
}
