<?php
/**
 * Capacités de Restrict Content Pro employées par le plugin.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Compat;

/**
 * Nomme en un seul endroit les capacités contrôlées par le plugin.
 *
 * Répétée littéralement, une capacité se retrouve mal orthographiée dans un
 * contrôle d'accès, où l'erreur ouvre un écran au lieu de le fermer et ne se
 * voit pas — `current_user_can()` renvoyant simplement `false` pour une
 * capacité inconnue, jamais une erreur.
 */
final class Capability {

	/**
	 * Administration des réglages de RCP.
	 */
	public const MANAGE_SETTINGS = 'rcp_manage_settings';
}
