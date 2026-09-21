<?php
/**
 * Traitement métier d'un événement authentifié.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Webhook;

use RCP_Stripe_Sepa\Membership\MembershipResolver;
use RCP_Stripe_Sepa\Membership\StateMachine;
use RCP_Stripe_Sepa\Membership\TransitionApplier;

/**
 * Applique un événement authentifié au cycle de vie d'une adhésion.
 *
 * La séparation est délibérée : l'authentification et l'idempotence relèvent du
 * transport, la décision relève de la machine à états, l'écriture relève de
 * l'applicateur. Cette classe ne fait que les enchaîner.
 */
final class EventProcessor {

	/**
	 * Traite un événement.
	 *
	 * @param array $event Événement Stripe décodé.
	 * @return EventResult
	 */
	public static function process( array $event ): EventResult {
		$type          = (string) ( $event['type'] ?? '' );
		$stripe_object = (array) ( $event['data']['object'] ?? array() );

		if ( ! in_array( $type, StateMachine::handled_events(), true ) ) {
			return EventResult::skipped( sprintf( 'Événement non géré : %s.', $type ) );
		}

		$membership = MembershipResolver::resolve( $stripe_object );

		if ( null === $membership ) {
			/*
			 * Un événement sans adhésion correspondante n'est pas une erreur :
			 * le compte Stripe peut servir à d'autres usages, et le webhook
			 * reçoit tout ce à quoi il est abonné. Répondre en échec ferait
			 * rejouer l'événement indéfiniment.
			 */
			return EventResult::skipped( 'Aucune adhésion SEPA ne correspond à cet événement.' );
		}

		$transition = StateMachine::resolve(
			$type,
			$stripe_object,
			(string) $membership->get_status(),
			Settings::access_policy(),
			Settings::dispute_policy()
		);

		if ( $transition->is_skipped() ) {
			return EventResult::skipped( $transition->reason() );
		}

		$changes = TransitionApplier::apply( $membership, $transition, $event );

		return EventResult::processed(
			trim(
				sprintf(
					'%s %s',
					$transition->reason(),
					array() === $changes ? '(aucun changement)' : '[' . implode( ' ; ', $changes ) . ']'
				)
			)
		);
	}
}
