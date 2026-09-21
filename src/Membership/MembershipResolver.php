<?php
/**
 * Résolution de l'adhésion concernée par un événement Stripe.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Membership;

use RCP_Membership;

/**
 * Retrouve l'adhésion RCP visée par un événement.
 *
 * Les événements Stripe ne portent pas tous la même information : une facture
 * référence un abonnement, un PaymentIntent porte des métadonnées, un litige ne
 * connaît qu'une charge. Plusieurs pistes sont donc suivies, de la plus fiable
 * à la plus indirecte.
 */
final class MembershipResolver {

	/**
	 * Métadonnée posée par RCP sur les objets Stripe.
	 */
	public const METADATA_KEY = 'rcp_membership_id';

	/**
	 * Retrouve l'adhésion visée par un événement.
	 *
	 * @param array $stripe_object Objet porté par l'événement.
	 * @return RCP_Membership|null
	 */
	public static function resolve( array $stripe_object ): ?RCP_Membership {
		foreach ( self::strategies( $stripe_object ) as $strategy ) {
			$membership = $strategy();

			if ( $membership instanceof RCP_Membership && $membership->get_id() > 0 ) {
				return $membership;
			}
		}

		return null;
	}

	/**
	 * Pistes de résolution, par ordre de fiabilité décroissante.
	 *
	 * @param array $stripe_object Objet porté par l'événement.
	 * @return callable[]
	 */
	private static function strategies( array $stripe_object ): array {
		return array(
			static function () use ( $stripe_object ) {
				$id = self::membership_id_from_metadata( $stripe_object );

				return $id > 0 ? rcp_get_membership( $id ) : null;
			},
			static function () use ( $stripe_object ) {
				$subscription = self::subscription_id( $stripe_object );

				return '' !== $subscription
					? rcp_get_membership_by( 'gateway_subscription_id', $subscription )
					: null;
			},
			static function () use ( $stripe_object ) {
				$customer = self::scalar( $stripe_object, 'customer' );

				return '' !== $customer
					? rcp_get_membership_by( 'gateway_customer_id', $customer )
					: null;
			},
		);
	}

	/**
	 * Identifiant d'adhésion porté par les métadonnées.
	 *
	 * Les métadonnées peuvent se trouver sur l'objet lui-même ou sur l'objet
	 * imbriqué qui l'a produit — un litige porte la charge, une facture porte
	 * les lignes de l'abonnement.
	 *
	 * @param array $stripe_object Objet porté par l'événement.
	 * @return int
	 */
	private static function membership_id_from_metadata( array $stripe_object ): int {
		$candidates = array(
			$stripe_object['metadata'][ self::METADATA_KEY ] ?? null,
			$stripe_object['lines']['data'][0]['metadata'][ self::METADATA_KEY ] ?? null,
			$stripe_object['subscription_details']['metadata'][ self::METADATA_KEY ] ?? null,
		);

		foreach ( $candidates as $candidate ) {
			if ( null !== $candidate && '' !== $candidate ) {
				return (int) $candidate;
			}
		}

		return 0;
	}

	/**
	 * Identifiant d'abonnement Stripe porté par l'objet.
	 *
	 * @param array $stripe_object Objet porté par l'événement.
	 * @return string
	 */
	private static function subscription_id( array $stripe_object ): string {
		// Un événement d'abonnement porte l'identifiant sur l'objet lui-même.
		if ( 'subscription' === ( $stripe_object['object'] ?? '' ) ) {
			return self::scalar( $stripe_object, 'id' );
		}

		return self::scalar( $stripe_object, 'subscription' );
	}

	/**
	 * Lit une valeur scalaire, qu'elle soit développée ou non.
	 *
	 * Un champ Stripe peut contenir un identifiant ou l'objet complet selon les
	 * paramètres d'expansion de la requête d'origine.
	 *
	 * @param array  $stripe_object Objet porté par l'événement.
	 * @param string $key    Clé recherchée.
	 * @return string
	 */
	private static function scalar( array $stripe_object, string $key ): string {
		$value = $stripe_object[ $key ] ?? null;

		if ( is_string( $value ) ) {
			return $value;
		}

		if ( is_array( $value ) && isset( $value['id'] ) && is_string( $value['id'] ) ) {
			return $value['id'];
		}

		return '';
	}
}
