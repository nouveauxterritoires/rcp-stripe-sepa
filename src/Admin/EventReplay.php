<?php
/**
 * Rejeu manuel d'un événement de webhook.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Admin;

use Exception;
use RCP_Stripe_Sepa\Logging\Redactor;
use RCP_Stripe_Sepa\Support\StripeSdk;
use RCP_Stripe_Sepa\Webhook\EventProcessor;
use RCP_Stripe_Sepa\Webhook\EventStore;
use RCP_Stripe_Sepa\Webhook\WebhookSecret;
use WP_Error;

/**
 * Rejoue un événement déjà reçu, à la demande d'un administrateur.
 *
 * Un événement peut avoir échoué pour une raison transitoire — base
 * indisponible, adhésion créée après coup — et Stripe cesse de le rejouer au
 * bout de quelques jours. Le rejeu manuel évite d'avoir à corriger la base à
 * la main.
 *
 * La charge utile est **relue auprès de Stripe**, et non reprise d'une copie
 * locale : le journal ne conserve qu'une empreinte, et une source
 * authentifiée vaut mieux qu'un enregistrement local altérable.
 */
final class EventReplay {

	/**
	 * Rejoue un événement.
	 *
	 * @param string $event_id Identifiant Stripe de l'événement.
	 * @return string|WP_Error Note décrivant l'issue.
	 */
	public static function replay( string $event_id ) {
		if ( '' === $event_id || 0 !== strpos( $event_id, 'evt_' ) ) {
			return new WP_Error(
				'rcp_stripe_sepa_invalid_event',
				__( 'Invalid event identifier.', 'rcp-stripe-sepa' )
			);
		}

		if ( ! StripeSdk::ensure_loaded() ) {
			return new WP_Error(
				'rcp_stripe_sepa_sdk_missing',
				__( 'The Stripe SDK is unavailable.', 'rcp-stripe-sepa' )
			);
		}

		try {
			$event = \Stripe\Event::retrieve( $event_id, StripeSdk::request_options() )->toArray();
		} catch ( Exception $exception ) {
			rcp_log( 'Rejeu SEPA : ' . Redactor::redact( $exception->getMessage() ), true );

			return new WP_Error(
				'rcp_stripe_sepa_event_unreadable',
				__( 'This event could not be found in Stripe.', 'rcp-stripe-sepa' )
			);
		}

		$livemode = (bool) ( $event['livemode'] ?? false );

		if ( WebhookSecret::is_test_mode() === $livemode ) {
			return new WP_Error(
				'rcp_stripe_sepa_mode_mismatch',
				__( 'This event belongs to the other mode: replay refused.', 'rcp-stripe-sepa' )
			);
		}

		$result = EventProcessor::process( $event );

		EventStore::claim( $event_id, (string) ( $event['type'] ?? '' ), $livemode, '' );
		EventStore::resolve( $event_id, $result->status(), $result->note() );

		rcp_log( sprintf( 'Rejeu SEPA de %s : %s', $event_id, $result->note() ) );

		return $result->note();
	}
}
