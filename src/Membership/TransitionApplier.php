<?php
/**
 * Application d'une transition à une adhésion RCP.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Membership;

use RCP_Membership;
use RCP_Payments;

/**
 * Applique à RCP la transition déduite d'un événement.
 *
 * La décision est prise par la machine à états ; cette classe se borne à
 * l'exécuter, à la tracer dans les notes de l'adhésion et à exposer des points
 * d'extension.
 */
final class TransitionApplier {

	/**
	 * Applique une transition.
	 *
	 * @param RCP_Membership $membership Adhésion visée.
	 * @param Transition     $transition Transition à appliquer.
	 * @param array          $event      Événement Stripe complet.
	 * @return string[] Changements effectués, pour le journal.
	 */
	public static function apply( RCP_Membership $membership, Transition $transition, array $event ): array {
		if ( $transition->is_skipped() ) {
			return array();
		}

		$changes = array_merge(
			self::apply_payment_status( $membership, $transition, $event ),
			self::apply_membership_status( $membership, $transition )
		);

		$membership->add_note(
			sprintf(
				/* translators: 1: raison de la transition, 2: type d'événement Stripe. */
				__( 'Prélèvement SEPA — %1$s (événement %2$s)', 'rcp-stripe-sepa' ),
				$transition->reason(),
				(string) ( $event['type'] ?? '?' )
			)
		);

		/**
		 * Se déclenche après l'application d'une transition à une adhésion.
		 *
		 * @since 0.1.0
		 *
		 * @param RCP_Membership $membership Adhésion visée.
		 * @param Transition     $transition Transition appliquée.
		 * @param array          $event      Événement Stripe complet.
		 * @param string[]       $changes    Changements effectués.
		 */
		do_action( 'rcp_stripe_sepa_transition_applied', $membership, $transition, $event, $changes );

		return $changes;
	}

	/**
	 * Met à jour le statut de l'adhésion.
	 *
	 * @param RCP_Membership $membership Adhésion visée.
	 * @param Transition     $transition Transition à appliquer.
	 * @return string[]
	 */
	private static function apply_membership_status( RCP_Membership $membership, Transition $transition ): array {
		$target = $transition->membership_status();

		if ( null === $target ) {
			return array();
		}

		$current = (string) $membership->get_status();

		if ( $current === $target ) {
			return array();
		}

		$membership->set_status( $target );

		return array( sprintf( 'adhésion %s → %s', $current, $target ) );
	}

	/**
	 * Met à jour le paiement RCP associé, lorsqu'il peut être retrouvé.
	 *
	 * @param RCP_Membership $membership Adhésion visée.
	 * @param Transition     $transition Transition à appliquer.
	 * @param array          $event      Événement Stripe complet.
	 * @return string[]
	 */
	private static function apply_payment_status( RCP_Membership $membership, Transition $transition, array $event ): array {
		$target = $transition->payment_status();

		if ( null === $target ) {
			return array();
		}

		$payment = self::find_payment( $membership, $event );

		if ( null === $payment ) {
			return array( sprintf( 'paiement introuvable, statut %s non appliqué', $target ) );
		}

		if ( (string) $payment->status === $target ) {
			return array();
		}

		( new RCP_Payments() )->update( (int) $payment->id, array( 'status' => $target ) );

		return array( sprintf( 'paiement #%d %s → %s', (int) $payment->id, (string) $payment->status, $target ) );
	}

	/**
	 * Retrouve le paiement RCP correspondant à l'événement.
	 *
	 * @param RCP_Membership $membership Adhésion visée.
	 * @param array          $event      Événement Stripe complet.
	 * @return object|null
	 */
	private static function find_payment( RCP_Membership $membership, array $event ) {
		$payments = new RCP_Payments();
		$object   = $event['data']['object'] ?? array();

		$payment_id = (int) ( $object['metadata']['rcp_payment_id'] ?? 0 );

		if ( $payment_id > 0 ) {
			$payment = $payments->get_payment( $payment_id );

			if ( ! empty( $payment ) ) {
				return $payment;
			}
		}

		$found = $payments->get_payments(
			array(
				'subscription_key' => $membership->get_subscription_key(),
				'number'           => 1,
				'orderby'          => 'id',
				'order'            => 'DESC',
			)
		);

		return ! empty( $found ) ? reset( $found ) : null;
	}
}
