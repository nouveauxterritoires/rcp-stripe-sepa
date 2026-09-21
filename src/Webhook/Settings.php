<?php
/**
 * Réglages du plugin.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Webhook;

use RCP_Stripe_Sepa\Membership\StateMachine;

/**
 * Lecture des réglages, avec des valeurs par défaut prudentes.
 *
 * Les défauts protègent le site : accès au contenu seulement après encaissement
 * effectif, révocation en cas de litige.
 */
final class Settings {

	public const OPTION = 'rcp_stripe_sepa_settings';

	/**
	 * Politique d'accès au contenu pendant le traitement du prélèvement.
	 *
	 * @return string
	 */
	public static function access_policy(): string {
		$value = self::get( 'access_policy', StateMachine::ACCESS_STRICT );

		return StateMachine::ACCESS_OPTIMISTIC === $value
			? StateMachine::ACCESS_OPTIMISTIC
			: StateMachine::ACCESS_STRICT;
	}

	/**
	 * Conduite à tenir en cas de litige.
	 *
	 * @return string
	 */
	public static function dispute_policy(): string {
		$value = self::get( 'dispute_policy', StateMachine::DISPUTE_REVOKE );

		return StateMachine::DISPUTE_NOTIFY === $value
			? StateMachine::DISPUTE_NOTIFY
			: StateMachine::DISPUTE_REVOKE;
	}

	/**
	 * Délai au-delà duquel un prélèvement en attente est signalé, en jours.
	 *
	 * @return int
	 */
	public static function pending_alert_days(): int {
		return max( 1, (int) self::get( 'pending_alert_days', 14 ) );
	}

	/**
	 * Lit un réglage.
	 *
	 * @param string $key      Clé du réglage.
	 * @param mixed  $fallback Valeur par défaut.
	 * @return mixed
	 */
	private static function get( string $key, $fallback ) {
		$settings = get_option( self::OPTION, array() );

		if ( ! is_array( $settings ) || ! isset( $settings[ $key ] ) ) {
			return $fallback;
		}

		return $settings[ $key ];
	}
}
