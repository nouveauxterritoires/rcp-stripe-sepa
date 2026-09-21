<?php
/**
 * Adaptation des arguments d'abonnement Stripe au prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Gateway;

/**
 * Restreint un abonnement Stripe au prélèvement SEPA.
 *
 * Restrict Content Pro construit les arguments d'abonnement dans sa passerelle
 * carte et les expose par le filtre `rcp_stripe_create_subscription_args`. Ce
 * filtre est partagé par les deux passerelles : l'adaptation ne doit donc
 * s'appliquer qu'aux abonnements créés par celle-ci.
 */
final class SubscriptionArgs {

	/**
	 * Restreint les arguments au prélèvement SEPA.
	 *
	 * Le tableau fourni n'est jamais modifié.
	 *
	 * @param array $args Arguments construits par RCP.
	 * @return array
	 */
	public static function for_sepa( array $args ): array {
		$settings = (array) ( $args['payment_settings'] ?? array() );

		$settings['payment_method_types'] = array( IntentFactory::PAYMENT_METHOD_TYPE );

		return array_merge( $args, array( 'payment_settings' => $settings ) );
	}

	/**
	 * N'adapte les arguments que si la passerelle est celle du plugin.
	 *
	 * @param array  $args    Arguments construits par RCP.
	 * @param string $gateway Identifiant de la passerelle à l'origine de l'appel.
	 * @return array
	 */
	public static function maybe_for_sepa( array $args, string $gateway ): array {
		return GatewayDefinition::ID === $gateway ? self::for_sepa( $args ) : $args;
	}
}
