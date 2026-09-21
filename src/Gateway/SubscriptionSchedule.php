<?php
/**
 * Programmation de la première échéance d'un abonnement.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Gateway;

/**
 * Choisit entre ancrage du cycle de facturation et fin de période d'essai.
 *
 * Le premier versement d'une adhésion est encaissé comme paiement ponctuel :
 * l'abonnement ne doit donc facturer qu'à l'expiration de la période ainsi
 * réglée. Deux mécanismes Stripe permettent ce report, et ils ne sont pas
 * interchangeables.
 *
 * `billing_cycle_anchor` est préférable — il préserve le calcul du revenu
 * récurrent — mais Stripe le refuse au-delà d'un cycle de facturation. Passé
 * cette limite, seule une fin d'essai permet de différer la facturation.
 */
final class SubscriptionSchedule {

	/**
	 * Applique le report à des arguments d'abonnement.
	 *
	 * Le tableau fourni n'est jamais modifié.
	 *
	 * @param array $args       Arguments d'abonnement.
	 * @param int   $start_date Date de première échéance, en secondes Unix.
	 * @param int   $max_anchor Ancrage le plus lointain accepté par Stripe.
	 * @param bool  $is_trial   L'adhésion comporte-t-elle une période d'essai.
	 * @return array
	 */
	public static function apply( array $args, int $start_date, int $max_anchor, bool $is_trial ): array {
		if ( $is_trial || $start_date > $max_anchor ) {
			return array_merge( $args, array( 'trial_end' => $start_date ) );
		}

		return array_merge( $args, array( 'billing_cycle_anchor' => $start_date ) );
	}

	/**
	 * Le report passe-t-il par une fin de période d'essai ?
	 *
	 * @param array $args Arguments produits par `apply()`.
	 * @return bool
	 */
	public static function uses_trial( array $args ): bool {
		return isset( $args['trial_end'] );
	}
}
