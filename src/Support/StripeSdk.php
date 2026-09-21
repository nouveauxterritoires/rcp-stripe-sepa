<?php
/**
 * Accès au SDK Stripe fourni par Restrict Content Pro.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Support;

use RCP_Stripe_Sepa\Compat\RcpEnvironment;

/**
 * Charge le SDK Stripe embarqué par RCP et fabrique les options de requête.
 *
 * RCP n'inclut son SDK qu'au premier appel de
 * `RCP_Payment_Gateway_Stripe::init()`. Un webhook peut arriver sans qu'aucune
 * passerelle n'ait été instanciée : le SDK doit donc pouvoir être chargé à la
 * demande, exactement comme RCP le fait.
 *
 * RCP fixe par ailleurs la version d'API globalement, via
 * `\Stripe\Stripe::setApiVersion()`. Ce réglage est statique : le modifier
 * casserait la passerelle carte. Le plugin ne le touche jamais et transmet sa
 * version dans les options de chaque requête.
 *
 * @see docs/cahier-des-charges.md §3.3 règles R-API-1 à R-API-3
 */
final class StripeSdk {

	/**
	 * Charge le SDK s'il ne l'est pas déjà.
	 *
	 * @return bool Le SDK est-il disponible.
	 */
	public static function ensure_loaded(): bool {
		if ( class_exists( '\Stripe\Stripe' ) ) {
			return true;
		}

		$path = RcpEnvironment::detect()->stripe_sdk_path();

		if ( null === $path ) {
			return false;
		}

		require_once $path;

		return class_exists( '\Stripe\Stripe' );
	}

	/**
	 * Version d'API que le plugin transmet dans ses requêtes.
	 *
	 * @return string
	 */
	public static function api_version(): string {
		return defined( 'RCP_SEPA_STRIPE_API_VERSION' )
			? (string) constant( 'RCP_SEPA_STRIPE_API_VERSION' )
			: '2022-11-15';
	}

	/**
	 * Options à joindre à chaque requête Stripe.
	 *
	 * @param array $extra Options supplémentaires (clé d'idempotence…).
	 * @return array
	 */
	public static function request_options( array $extra = array() ): array {
		return array_merge( array( 'stripe_version' => self::api_version() ), $extra );
	}
}
