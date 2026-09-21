<?php
/**
 * Persistance des mandats SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Mandate;

/**
 * Enregistre et relit les données de mandat, en métadonnées d'adhésion.
 *
 * Aucune table dédiée : un mandat appartient à une adhésion et disparaît avec
 * elle. Les métadonnées de RCP offrent en outre la suppression en cascade et
 * l'intégration aux outils de confidentialité de WordPress.
 *
 * @see docs/cahier-des-charges.md §7.2
 */
final class MandateRepository {

	/**
	 * Préfixe des clés de métadonnées.
	 */
	public const META_PREFIX = 'rcp_sepa_';

	/**
	 * Champs persistés.
	 *
	 * La liste est explicite : elle empêche qu'un champ ajouté un jour par
	 * Stripe n'entre en base sans avoir été examiné.
	 *
	 * @var string[]
	 */
	public const FIELDS = array(
		'payment_method_id',
		'mandate_id',
		'mandate_reference',
		'mandate_url',
		'mandate_status',
		'iban_last4',
		'country',
		'bank_code',
		'branch_code',
		'account_holder_name',
		'accepted_at',
		'accepted_ip',
	);

	/**
	 * Durée de conservation de l'adresse d'acceptation, en mois.
	 *
	 * Alignée sur la fenêtre de contestation d'un prélèvement non autorisé :
	 * au-delà, cette donnée personnelle n'a plus d'utilité probatoire.
	 */
	public const IP_RETENTION_MONTHS = 13;

	/**
	 * Enregistre un mandat.
	 *
	 * @param int   $membership_id Identifiant d'adhésion.
	 * @param array $data          Données produites par `MandateData`.
	 * @return void
	 */
	public static function save( int $membership_id, array $data ): void {
		if ( $membership_id <= 0 || array() === $data ) {
			return;
		}

		foreach ( self::FIELDS as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}

			rcp_update_membership_meta(
				$membership_id,
				self::META_PREFIX . $field,
				sanitize_text_field( (string) $data[ $field ] )
			);
		}

		/**
		 * Se déclenche après l'enregistrement d'un mandat SEPA.
		 *
		 * @since 0.1.0
		 *
		 * @param int   $membership_id Identifiant d'adhésion.
		 * @param array $data          Données enregistrées.
		 */
		do_action( 'rcp_stripe_sepa_mandate_saved', $membership_id, $data );
	}

	/**
	 * Relit le mandat d'une adhésion.
	 *
	 * @param int $membership_id Identifiant d'adhésion.
	 * @return array Tableau vide si aucun mandat n'est enregistré.
	 */
	public static function find( int $membership_id ): array {
		if ( $membership_id <= 0 ) {
			return array();
		}

		$data = array();

		foreach ( self::FIELDS as $field ) {
			$value = rcp_get_membership_meta( $membership_id, self::META_PREFIX . $field, true );

			$data[ $field ] = is_string( $value ) ? $value : '';
		}

		return '' !== $data['payment_method_id'] ? $data : array();
	}

	/**
	 * Supprime le mandat d'une adhésion.
	 *
	 * @param int $membership_id Identifiant d'adhésion.
	 * @return void
	 */
	public static function forget( int $membership_id ): void {
		foreach ( self::FIELDS as $field ) {
			rcp_delete_membership_meta( $membership_id, self::META_PREFIX . $field );
		}
	}

	/**
	 * Purge les adresses d'acceptation au-delà de la durée de conservation.
	 *
	 * @param int $membership_id Identifiant d'adhésion.
	 * @return bool L'adresse a-t-elle été purgée.
	 */
	public static function maybe_purge_ip( int $membership_id ): bool {
		$accepted_at = rcp_get_membership_meta( $membership_id, self::META_PREFIX . 'accepted_at', true );

		if ( ! is_string( $accepted_at ) || '' === $accepted_at ) {
			return false;
		}

		$deadline = strtotime( $accepted_at . ' +' . self::IP_RETENTION_MONTHS . ' months' );

		if ( false === $deadline || $deadline > time() ) {
			return false;
		}

		rcp_delete_membership_meta( $membership_id, self::META_PREFIX . 'accepted_ip' );

		return true;
	}
}
