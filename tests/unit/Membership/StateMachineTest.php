<?php
/**
 * Tests de la machine à états des adhésions.
 *
 * Traduit un événement Stripe en transition d'adhésion et de paiement. Cette
 * logique est purement fonctionnelle : elle ne touche ni WordPress ni RCP, et
 * concentre les règles de gestion du cahier des charges §8.3.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Membership;

use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Membership\StateMachine;

/**
 * @covers \RCP_Stripe_Sepa\Membership\StateMachine
 * @covers \RCP_Stripe_Sepa\Membership\Transition
 */
final class StateMachineTest extends TestCase {

	// -- Prélèvement en cours --------------------------------------------------

	public function test_un_prelevement_en_cours_laisse_l_adhesion_en_attente(): void {
		// RG-01 : aucun accès au contenu tant que le prélèvement n'a pas abouti.
		$transition = StateMachine::resolve(
			'payment_intent.processing',
			array( 'status' => 'processing' ),
			StateMachine::MEMBERSHIP_PENDING
		);

		$this->assertFalse( $transition->is_skipped() );
		$this->assertSame( StateMachine::MEMBERSHIP_PENDING, $transition->membership_status() );
		$this->assertSame( StateMachine::PAYMENT_PENDING, $transition->payment_status() );
	}

	public function test_la_politique_optimiste_active_des_le_mandat(): void {
		$transition = StateMachine::resolve(
			'payment_intent.processing',
			array( 'status' => 'processing' ),
			StateMachine::MEMBERSHIP_PENDING,
			StateMachine::ACCESS_OPTIMISTIC
		);

		$this->assertSame( StateMachine::MEMBERSHIP_ACTIVE, $transition->membership_status() );
		$this->assertSame( StateMachine::PAYMENT_PENDING, $transition->payment_status() );
	}

	public function test_un_renouvellement_en_cours_ne_retrograde_pas_une_adhesion_active(): void {
		// I-3 : les événements peuvent arriver dans le désordre ; aucune
		// transition ne doit retirer un accès déjà accordé.
		$transition = StateMachine::resolve(
			'payment_intent.processing',
			array( 'status' => 'processing' ),
			StateMachine::MEMBERSHIP_ACTIVE
		);

		$this->assertNull( $transition->membership_status(), 'L\'adhésion active doit être laissée intacte.' );
		$this->assertSame( StateMachine::PAYMENT_PENDING, $transition->payment_status() );
	}

	// -- Prélèvement abouti ----------------------------------------------------

	public function test_un_prelevement_abouti_active_l_adhesion(): void {
		$transition = StateMachine::resolve(
			'payment_intent.succeeded',
			array( 'status' => 'succeeded' ),
			StateMachine::MEMBERSHIP_PENDING
		);

		$this->assertSame( StateMachine::MEMBERSHIP_ACTIVE, $transition->membership_status() );
		$this->assertSame( StateMachine::PAYMENT_COMPLETE, $transition->payment_status() );
	}

	public function test_une_facture_reglee_active_l_adhesion(): void {
		$transition = StateMachine::resolve(
			'invoice.paid',
			array( 'paid' => true, 'attempt_count' => 1 ),
			StateMachine::MEMBERSHIP_PENDING
		);

		$this->assertSame( StateMachine::MEMBERSHIP_ACTIVE, $transition->membership_status() );
		$this->assertSame( StateMachine::PAYMENT_COMPLETE, $transition->payment_status() );
	}

	public function test_un_reglement_tardif_reactive_une_adhesion_resiliee(): void {
		// Un prélèvement peut aboutir après qu'un échec a résilié l'adhésion :
		// l'argent est arrivé, l'accès doit être rétabli.
		$transition = StateMachine::resolve(
			'payment_intent.succeeded',
			array( 'status' => 'succeeded' ),
			StateMachine::MEMBERSHIP_CANCELLED
		);

		$this->assertSame( StateMachine::MEMBERSHIP_ACTIVE, $transition->membership_status() );
	}

	// -- Échecs ----------------------------------------------------------------

	public function test_un_echec_au_premier_paiement_ferme_l_acces(): void {
		/*
		 * `expired` et non `cancelled` : dans RCP, une adhésion résiliée garde
		 * l'accès jusqu'à son échéance, la période ayant été réglée. Ici rien
		 * n'a été encaissé.
		 */
		$transition = StateMachine::resolve(
			'payment_intent.payment_failed',
			array( 'status' => 'requires_payment_method' ),
			StateMachine::MEMBERSHIP_PENDING
		);

		$this->assertSame( StateMachine::MEMBERSHIP_EXPIRED, $transition->membership_status() );
		$this->assertSame( StateMachine::PAYMENT_FAILED, $transition->payment_status() );
	}

	public function test_un_echec_de_renouvellement_laisse_l_adhesion_active(): void {
		// L'adhésion expirera d'elle-même à son terme si le paiement n'aboutit
		// pas : la résilier immédiatement priverait le membre d'une période
		// déjà réglée.
		$transition = StateMachine::resolve(
			'payment_intent.payment_failed',
			array( 'status' => 'requires_payment_method' ),
			StateMachine::MEMBERSHIP_ACTIVE
		);

		$this->assertNull( $transition->membership_status() );
		$this->assertSame( StateMachine::PAYMENT_FAILED, $transition->payment_status() );
	}

	// -- Le faux échec à la création d'abonnement ------------------------------

	public function test_une_facture_en_echec_sans_tentative_est_ignoree(): void {
		/*
		 * Constat vérifié sur un compte Stripe réel : à la création d'un
		 * abonnement SEPA, Stripe émet invoice.payment_failed avant toute
		 * tentative de prélèvement, parce que la facture attend la
		 * confirmation du mandat. La traiter comme un impayé résilierait des
		 * adhésions valides dès l'inscription.
		 *
		 * @see docs/environnement-stripe-test.md §4.4
		 */
		$transition = StateMachine::resolve(
			'invoice.payment_failed',
			array( 'attempt_count' => 0, 'charge' => null, 'billing_reason' => 'subscription_create' ),
			StateMachine::MEMBERSHIP_PENDING
		);

		$this->assertTrue( $transition->is_skipped() );
		$this->assertNull( $transition->membership_status() );
		$this->assertNull( $transition->payment_status() );
	}

	public function test_une_facture_en_echec_apres_tentative_est_un_impaye(): void {
		$transition = StateMachine::resolve(
			'invoice.payment_failed',
			array( 'attempt_count' => 1, 'charge' => 'py_123', 'billing_reason' => 'subscription_create' ),
			StateMachine::MEMBERSHIP_PENDING
		);

		$this->assertFalse( $transition->is_skipped() );
		$this->assertSame( StateMachine::MEMBERSHIP_EXPIRED, $transition->membership_status() );
		$this->assertSame( StateMachine::PAYMENT_FAILED, $transition->payment_status() );
	}

	/**
	 * Un mandat refusé n'a jamais rien encaissé : l'accès doit rester fermé.
	 */
	public function test_un_mandat_refuse_ferme_l_acces(): void {
		$transition = StateMachine::resolve(
			'setup_intent.setup_failed',
			array(),
			StateMachine::MEMBERSHIP_PENDING
		);

		$this->assertSame( StateMachine::MEMBERSHIP_EXPIRED, $transition->membership_status() );
		$this->assertSame( StateMachine::PAYMENT_FAILED, $transition->payment_status() );
	}

	/**
	 * Seule exception : l'adhérent qui se désabonne a réglé sa période et en
	 * conserve le bénéfice jusqu'au terme — ce que `cancelled` exprime.
	 */
	public function test_un_desabonnement_laisse_l_acces_jusqu_au_terme(): void {
		$transition = StateMachine::resolve(
			'customer.subscription.deleted',
			array(),
			StateMachine::MEMBERSHIP_ACTIVE
		);

		$this->assertSame( StateMachine::MEMBERSHIP_CANCELLED, $transition->membership_status() );
	}

	// -- Litiges et remboursements ---------------------------------------------

	/**
	 * Révoquer, c'est fermer l'accès : `cancelled` le laisserait ouvert
	 * jusqu'à l'échéance, alors même que les fonds ont été repris.
	 */
	public function test_un_litige_revoque_l_adhesion(): void {
		$transition = StateMachine::resolve(
			'charge.dispute.created',
			array( 'amount' => 1000 ),
			StateMachine::MEMBERSHIP_ACTIVE
		);

		$this->assertSame( StateMachine::MEMBERSHIP_EXPIRED, $transition->membership_status() );
	}

	public function test_un_litige_peut_se_limiter_a_une_notification(): void {
		$transition = StateMachine::resolve(
			'charge.dispute.created',
			array( 'amount' => 1000 ),
			StateMachine::MEMBERSHIP_ACTIVE,
			StateMachine::ACCESS_STRICT,
			StateMachine::DISPUTE_NOTIFY
		);

		$this->assertFalse( $transition->is_skipped() );
		$this->assertNull( $transition->membership_status() );
	}

	public function test_un_remboursement_total_revoque_l_adhesion(): void {
		$transition = StateMachine::resolve(
			'charge.refunded',
			array( 'amount' => 1000, 'amount_refunded' => 1000, 'refunded' => true ),
			StateMachine::MEMBERSHIP_ACTIVE
		);

		$this->assertSame( StateMachine::MEMBERSHIP_EXPIRED, $transition->membership_status() );
		$this->assertSame( StateMachine::PAYMENT_REFUNDED, $transition->payment_status() );
	}

	public function test_un_remboursement_partiel_laisse_l_adhesion_active(): void {
		$transition = StateMachine::resolve(
			'charge.refunded',
			array( 'amount' => 1000, 'amount_refunded' => 400, 'refunded' => false ),
			StateMachine::MEMBERSHIP_ACTIVE
		);

		$this->assertNull( $transition->membership_status() );
		$this->assertSame( StateMachine::PAYMENT_REFUNDED, $transition->payment_status() );
	}

	// -- Abonnement ------------------------------------------------------------

	public function test_la_suppression_de_l_abonnement_resilie_l_adhesion(): void {
		$transition = StateMachine::resolve(
			'customer.subscription.deleted',
			array( 'status' => 'canceled' ),
			StateMachine::MEMBERSHIP_ACTIVE
		);

		$this->assertSame( StateMachine::MEMBERSHIP_CANCELLED, $transition->membership_status() );
	}

	public function test_un_mandat_enregistre_ne_change_aucun_statut(): void {
		// L'événement est traité — le mandat doit être persisté — mais il ne
		// déclenche aucune transition.
		$transition = StateMachine::resolve(
			'setup_intent.succeeded',
			array( 'status' => 'succeeded' ),
			StateMachine::MEMBERSHIP_PENDING
		);

		$this->assertFalse( $transition->is_skipped() );
		$this->assertNull( $transition->membership_status() );
		$this->assertNull( $transition->payment_status() );
	}

	// -- Événements non gérés ---------------------------------------------------

	public function test_un_evenement_non_gere_est_ignore(): void {
		$transition = StateMachine::resolve( 'capability.updated', array(), StateMachine::MEMBERSHIP_ACTIVE );

		$this->assertTrue( $transition->is_skipped() );
	}

	public function test_toute_transition_porte_une_raison_lisible(): void {
		$events = array(
			'payment_intent.processing',
			'payment_intent.succeeded',
			'payment_intent.payment_failed',
			'invoice.paid',
			'invoice.payment_failed',
			'charge.dispute.created',
			'charge.refunded',
			'customer.subscription.deleted',
			'setup_intent.succeeded',
			'capability.updated',
		);

		foreach ( $events as $event ) {
			$reason = StateMachine::resolve( $event, array(), StateMachine::MEMBERSHIP_PENDING )->reason();

			$this->assertNotSame( '', $reason, $event . ' : raison manquante.' );
		}
	}

	public function test_les_evenements_geres_sont_declares(): void {
		$handled = StateMachine::handled_events();

		$this->assertContains( 'payment_intent.succeeded', $handled );
		$this->assertContains( 'invoice.payment_failed', $handled );
		$this->assertNotContains( 'capability.updated', $handled );
	}
}
