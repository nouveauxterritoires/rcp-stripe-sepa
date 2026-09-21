<?php
/**
 * Tests du cycle de vie d'une adhésion piloté par les webhooks.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Webhooks;

use RCP_Stripe_Sepa\Membership\StateMachine;
use RCP_Stripe_Sepa\Webhook\EventStore;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Webhook\EventProcessor
 * @covers \RCP_Stripe_Sepa\Membership\MembershipResolver
 * @covers \RCP_Stripe_Sepa\Membership\TransitionApplier
 */
final class MembershipLifecycleTest extends WP_UnitTestCase {

	use WebhookTestHelpers;

	public function set_up(): void {
		parent::set_up();

		$this->prepare_webhook_environment();
	}

	public function tear_down(): void {
		$this->reset_webhook_environment();

		parent::tear_down();
	}

	public function test_l_adhesion_est_resolue_par_les_metadonnees(): void {
		$membership_id = $this->create_sepa_membership();

		$event = $this->event(
			'payment_intent.succeeded',
			array( 'metadata' => array( 'rcp_membership_id' => (string) $membership_id ) )
		);

		$this->assertSame( 200, $this->send( $event )->get_status() );
		$this->assertSame(
			EventStore::STATUS_PROCESSED,
			$this->stored_status( $event['id'] ),
			'Note enregistrée : ' . $this->stored_note( $event['id'] )
		);
	}

	public function test_un_prelevement_abouti_active_l_adhesion(): void {
		$membership_id = $this->create_sepa_membership( 0, StateMachine::MEMBERSHIP_PENDING );

		$this->send(
			$this->event(
				'payment_intent.succeeded',
				array( 'metadata' => array( 'rcp_membership_id' => (string) $membership_id ) )
			)
		);

		$this->assertSame(
			StateMachine::MEMBERSHIP_ACTIVE,
			$this->reload_membership( $membership_id )->get_status()
		);
	}

	public function test_un_prelevement_en_cours_laisse_l_adhesion_en_attente(): void {
		// RG-01 : aucun accès au contenu avant encaissement effectif.
		$membership_id = $this->create_sepa_membership( 0, StateMachine::MEMBERSHIP_PENDING );

		$this->send(
			$this->event(
				'payment_intent.processing',
				array(
					'status'   => 'processing',
					'metadata' => array( 'rcp_membership_id' => (string) $membership_id ),
				)
			)
		);

		$this->assertSame(
			StateMachine::MEMBERSHIP_PENDING,
			$this->reload_membership( $membership_id )->get_status()
		);
	}

	public function test_un_premier_prelevement_refuse_ferme_l_acces(): void {
		$membership_id = $this->create_sepa_membership( 0, StateMachine::MEMBERSHIP_PENDING );

		$this->send(
			$this->event(
				'payment_intent.payment_failed',
				array(
					'status'   => 'requires_payment_method',
					'metadata' => array( 'rcp_membership_id' => (string) $membership_id ),
				)
			)
		);

		$membership = $this->reload_membership( $membership_id );

		$this->assertSame( StateMachine::MEMBERSHIP_EXPIRED, $membership->get_status() );
		$this->assertFalse( $membership->is_active(), 'Le contenu doit rester fermé.' );
	}

	public function test_un_renouvellement_refuse_laisse_l_adhesion_active(): void {
		$membership_id = $this->create_sepa_membership( 0, StateMachine::MEMBERSHIP_ACTIVE );

		$this->send(
			$this->event(
				'payment_intent.payment_failed',
				array(
					'status'   => 'requires_payment_method',
					'metadata' => array( 'rcp_membership_id' => (string) $membership_id ),
				)
			)
		);

		$this->assertSame(
			StateMachine::MEMBERSHIP_ACTIVE,
			$this->reload_membership( $membership_id )->get_status()
		);
	}

	public function test_les_evenements_arrives_dans_le_desordre_ne_retrogradent_pas_l_adhesion(): void {
		// I-3 : un « processing » livré après un « succeeded » ne doit pas
		// refermer un accès déjà accordé.
		$membership_id = $this->create_sepa_membership( 0, StateMachine::MEMBERSHIP_PENDING );
		$metadata      = array( 'metadata' => array( 'rcp_membership_id' => (string) $membership_id ) );

		$this->send( $this->event( 'payment_intent.succeeded', $metadata ) );
		$this->send( $this->event( 'payment_intent.processing', array_merge( $metadata, array( 'status' => 'processing' ) ) ) );

		$this->assertSame(
			StateMachine::MEMBERSHIP_ACTIVE,
			$this->reload_membership( $membership_id )->get_status()
		);
	}

	public function test_la_facture_en_echec_de_creation_ne_resilie_pas_l_adhesion(): void {
		/*
		 * Constat vérifié sur un compte Stripe réel : à la création d'un
		 * abonnement SEPA, Stripe émet invoice.payment_failed avant toute
		 * tentative de prélèvement.
		 *
		 * @see docs/environnement-stripe-test.md §4.4
		 */
		$membership_id = $this->create_sepa_membership( 0, StateMachine::MEMBERSHIP_PENDING );

		$event = $this->event(
			'invoice.payment_failed',
			array(
				'object'         => 'invoice',
				'attempt_count'  => 0,
				'charge'         => null,
				'billing_reason' => 'subscription_create',
				'metadata'       => array( 'rcp_membership_id' => (string) $membership_id ),
			)
		);

		$this->send( $event );

		$this->assertSame(
			StateMachine::MEMBERSHIP_PENDING,
			$this->reload_membership( $membership_id )->get_status()
		);
		$this->assertSame( EventStore::STATUS_SKIPPED, $this->stored_status( $event['id'] ) );
	}

	public function test_un_veritable_impaye_ferme_l_acces(): void {
		$membership_id = $this->create_sepa_membership( 0, StateMachine::MEMBERSHIP_PENDING );

		$this->send(
			$this->event(
				'invoice.payment_failed',
				array(
					'object'         => 'invoice',
					'attempt_count'  => 1,
					'charge'         => 'py_test_123',
					'billing_reason' => 'subscription_create',
					'metadata'       => array( 'rcp_membership_id' => (string) $membership_id ),
				)
			)
		);

		$membership = $this->reload_membership( $membership_id );

		$this->assertSame( StateMachine::MEMBERSHIP_EXPIRED, $membership->get_status() );
		$this->assertFalse( $membership->is_active(), 'Le contenu doit rester fermé.' );
	}

	/**
	 * Le statut ne suffit pas à conclure : c'est `is_active()`, la règle
	 * d'accès de RCP, qui décide si le contenu reste ouvert.
	 */
	public function test_un_litige_revoque_l_adhesion(): void {
		$membership_id = $this->create_sepa_membership( 0, StateMachine::MEMBERSHIP_ACTIVE );

		$this->send(
			$this->event(
				'charge.dispute.created',
				array(
					'object'   => 'dispute',
					'amount'   => 1000,
					'metadata' => array( 'rcp_membership_id' => (string) $membership_id ),
				)
			)
		);

		$membership = $this->reload_membership( $membership_id );

		$this->assertSame( StateMachine::MEMBERSHIP_EXPIRED, $membership->get_status() );
		$this->assertFalse( $membership->is_active(), 'Le contenu doit être refermé.' );
	}

	public function test_l_adhesion_est_resolue_par_l_abonnement_stripe(): void {
		$membership_id = $this->create_sepa_membership( 0, StateMachine::MEMBERSHIP_PENDING );
		$membership    = $this->reload_membership( $membership_id );

		$event = $this->event(
			'invoice.paid',
			array(
				'object'       => 'invoice',
				'subscription' => $membership->get_gateway_subscription_id(),
				'paid'         => true,
				'metadata'     => array(),
			)
		);

		$this->send( $event );

		$this->assertSame(
			StateMachine::MEMBERSHIP_ACTIVE,
			$this->reload_membership( $membership_id )->get_status()
		);
	}

	public function test_chaque_transition_laisse_une_note_sur_l_adhesion(): void {
		$membership_id = $this->create_sepa_membership( 0, StateMachine::MEMBERSHIP_PENDING );

		$this->send(
			$this->event(
				'payment_intent.succeeded',
				array( 'metadata' => array( 'rcp_membership_id' => (string) $membership_id ) )
			)
		);

		$notes = $this->reload_membership( $membership_id )->get_notes();

		$this->assertStringContainsString( 'SEPA Direct Debit', $notes );
		$this->assertStringContainsString( 'payment_intent.succeeded', $notes );
	}
}
