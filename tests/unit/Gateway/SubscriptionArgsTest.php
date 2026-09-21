<?php
/**
 * Tests des arguments d'abonnement Stripe.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Gateway;

use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Gateway\SubscriptionArgs;

/**
 * @covers \RCP_Stripe_Sepa\Gateway\SubscriptionArgs
 */
final class SubscriptionArgsTest extends TestCase {

	/**
	 * Arguments tels que RCP les construit pour la carte.
	 *
	 * @return array
	 */
	private function rcp_args(): array {
		return array(
			'customer'               => 'cus_123',
			'default_payment_method' => 'pm_sepa_123',
			'plan'                   => 'plan_abc',
			'proration_behavior'     => 'none',
			'billing_cycle_anchor'   => 1790000000,
			'metadata'               => array( 'rcp_membership_id' => '42' ),
		);
	}

	public function test_l_abonnement_est_restreint_au_prelevement_sepa(): void {
		$args = SubscriptionArgs::for_sepa( $this->rcp_args() );

		$this->assertSame(
			array( 'sepa_debit' ),
			$args['payment_settings']['payment_method_types']
		);
	}

	public function test_les_arguments_construits_par_rcp_sont_conserves(): void {
		$args = SubscriptionArgs::for_sepa( $this->rcp_args() );

		$this->assertSame( 'cus_123', $args['customer'] );
		$this->assertSame( 'plan_abc', $args['plan'] );
		$this->assertSame( 1790000000, $args['billing_cycle_anchor'] );
		$this->assertSame( '42', $args['metadata']['rcp_membership_id'] );
	}

	public function test_les_reglages_de_paiement_existants_sont_completes_sans_etre_ecrases(): void {
		$args = SubscriptionArgs::for_sepa(
			array_merge(
				$this->rcp_args(),
				array( 'payment_settings' => array( 'save_default_payment_method' => 'on_subscription' ) )
			)
		);

		$this->assertSame( 'on_subscription', $args['payment_settings']['save_default_payment_method'] );
		$this->assertSame( array( 'sepa_debit' ), $args['payment_settings']['payment_method_types'] );
	}

	public function test_le_tableau_fourni_n_est_pas_mute(): void {
		$args = $this->rcp_args();
		$copy = $args;

		SubscriptionArgs::for_sepa( $args );

		$this->assertSame( $copy, $args );
	}

	public function test_la_passerelle_carte_n_est_pas_affectee(): void {
		// Le filtre est partagé avec la passerelle carte de RCP : il ne doit
		// modifier que les abonnements créés par la passerelle SEPA.
		$args = $this->rcp_args();

		$this->assertSame( $args, SubscriptionArgs::maybe_for_sepa( $args, 'stripe' ) );
		$this->assertNotSame( $args, SubscriptionArgs::maybe_for_sepa( $args, 'stripe_sepa' ) );
	}
}
