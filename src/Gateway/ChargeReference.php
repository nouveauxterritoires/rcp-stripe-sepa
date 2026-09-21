<?php
/**
 * Lecture de la charge associée à une intention Stripe.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Gateway;

/**
 * Retrouve l'identifiant de charge d'une intention, quelle que soit la version
 * d'API qui a produit la réponse.
 *
 * Le champ a changé de forme : `charges.data[0]` jusqu'à l'API 2020-08-27, que
 * Restrict Content Pro impose globalement, puis `latest_charge` depuis
 * 2022-11-15, que le plugin transmet dans ses propres requêtes. Les deux formes
 * coexistent donc sur un même site, et les webhooks arrivent dans la version du
 * compte, différente des deux précédentes.
 *
 * Les charges d'un prélèvement SEPA portent par ailleurs un identifiant `py_`,
 * et non `ch_` comme pour une carte : tout code qui reconnaîtrait une
 * transaction à son préfixe se tromperait.
 *
 * @see docs/environnement-stripe-test.md §4.1 et §4.2
 */
final class ChargeReference {

	/**
	 * Identifiant de la charge, ou chaîne vide si aucune n'existe encore.
	 *
	 * @param array $intent Intention Stripe, sous forme de tableau.
	 * @return string
	 */
	public static function from_intent( array $intent ): string {
		$latest = $intent['latest_charge'] ?? null;

		if ( is_string( $latest ) && '' !== $latest ) {
			return $latest;
		}

		if ( is_array( $latest ) && isset( $latest['id'] ) && is_string( $latest['id'] ) ) {
			return $latest['id'];
		}

		$first = $intent['charges']['data'][0]['id'] ?? null;

		return is_string( $first ) ? $first : '';
	}
}
