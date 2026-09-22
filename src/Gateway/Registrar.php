<?php
/**
 * Enregistrement de la passerelle auprès de Restrict Content Pro.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Gateway;

use RCP_Stripe_Sepa\Mode\ModeGuard;

/**
 * Déclare la passerelle SEPA à RCP et adapte les arguments partagés.
 *
 * L'enregistrement passe par le filtre `rcp_payment_gateways`, seul point
 * d'extension prévu par RCP. La passerelle carte native reste intacte : les
 * deux coexistent sur le même site, avec les mêmes clés API.
 */
final class Registrar {

	/**
	 * Branche le plugin sur les points d'extension de RCP.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rcp_payment_gateways', array( self::class, 'add_gateway' ) );
		add_filter( 'rcp_stripe_create_subscription_args', array( self::class, 'adapt_subscription_args' ), 10, 2 );
	}

	/**
	 * Ajoute la passerelle au registre de RCP.
	 *
	 * @param array $gateways Gateways déclarées.
	 * @return array
	 */
	public static function add_gateway( $gateways ): array {
		$gateways = is_array( $gateways ) ? $gateways : array();

		/*
		 * Mieux vaut une passerelle absente qu'une passerelle qui prélève dans
		 * le mauvais mode. L'administrateur en est averti (SEC-24).
		 */
		if ( ! ModeGuard::allows_gateway() ) {
			return $gateways;
		}

		$gateways[ GatewayDefinition::ID ] = GatewayDefinition::registry_entry();

		return $gateways;
	}

	/**
	 * Restreint au prélèvement SEPA les abonnements créés par cette passerelle.
	 *
	 * Le filtre est partagé avec la passerelle carte : l'adaptation est
	 * conditionnée à l'origine de l'appel, faute de quoi elle casserait les
	 * abonnements par carte du même site.
	 *
	 * @param array  $args    Arguments construits par RCP.
	 * @param object $gateway Gateway à l'origine de l'appel.
	 * @return array
	 */
	public static function adapt_subscription_args( $args, $gateway = null ): array {
		$args = is_array( $args ) ? $args : array();

		return $gateway instanceof Gateway ? SubscriptionArgs::for_sepa( $args ) : $args;
	}
}
