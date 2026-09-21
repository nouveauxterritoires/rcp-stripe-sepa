<?php
/**
 * Identité et capacités de la passerelle SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Gateway;

/**
 * Décrit la passerelle sans dépendre de Restrict Content Pro.
 *
 * La classe `Gateway` hérite de `RCP_Payment_Gateway_Stripe`, qui n'existe
 * qu'une fois RCP chargé. Isoler ici ce qui relève de la simple description
 * rend ces valeurs utilisables par le registre, par les tests unitaires et par
 * l'écran de diagnostic sans rien instancier.
 */
final class GatewayDefinition {

	/**
	 * Identifiant de la passerelle dans RCP.
	 */
	public const ID = 'stripe_sepa';

	/**
	 * Seule devise acceptée par le prélèvement SEPA.
	 */
	public const CURRENCY = 'EUR';

	/**
	 * Capacités déclarées à RCP.
	 *
	 * Reprend celles de la passerelle carte, moins `card-updates` : la mise à
	 * jour d'une carte n'a pas de sens pour un mandat, qui se remplace.
	 *
	 * @return string[]
	 */
	public static function supports(): array {
		return array(
			'one-time',
			'recurring',
			'fees',
			'gateway-submits-form',
			'trial',
			'price-changes',
			'renewal-date-changes',
			'subscription-creation',
			'ajax-payment',
			'off-site-subscription-creation',
			'expiration-extension-on-renewals',
		);
	}

	/**
	 * Description destinée au registre des passerelles de RCP.
	 *
	 * @return array
	 */
	public static function registry_entry(): array {
		return array(
			'label'       => __( 'SEPA Direct Debit', 'rcp-stripe-sepa' ),
			'admin_label' => __( 'Stripe — SEPA Direct Debit', 'rcp-stripe-sepa' ),
			'class'       => Gateway::class,
		);
	}
}
