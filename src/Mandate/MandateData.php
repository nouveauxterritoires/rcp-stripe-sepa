<?php
/**
 * Extraction des données de mandat SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Mandate;

/**
 * Retient d'un mandat SEPA ce qui est utile, et rien de plus.
 *
 * Un mandat est une donnée personnelle au sens du RGPD, et l'IBAN qui le
 * sous-tend est une donnée bancaire. Cette classe décide de ce qui entre en
 * base : les identifiants opaques de Stripe, les éléments nécessaires à la
 * reconnaissance du compte par son titulaire, et la preuve du consentement.
 *
 * Ce qui n'entre jamais : l'IBAN complet, qui n'atteint de toute façon pas le
 * serveur, et l'empreinte bancaire, identifiant durable sans utilité locale.
 *
 * @see docs/cahier-des-charges.md §7.2 et §9.4
 */
final class MandateData {

	/**
	 * Type de moyen de paiement attendu.
	 */
	public const PAYMENT_METHOD_TYPE = 'sepa_debit';

	/**
	 * Extrait les données d'un moyen de paiement et de son mandat.
	 *
	 * Les tableaux fournis ne sont jamais modifiés.
	 *
	 * @param array $payment_method Moyen de paiement Stripe.
	 * @param array $mandate        Mandat Stripe, éventuellement vide.
	 * @return array Tableau vide si le moyen de paiement n'est pas un prélèvement SEPA.
	 */
	public static function from_stripe( array $payment_method, array $mandate = array() ): array {
		if ( self::PAYMENT_METHOD_TYPE !== ( $payment_method['type'] ?? '' ) ) {
			return array();
		}

		$sepa    = (array) ( $payment_method['sepa_debit'] ?? array() );
		$details = (array) ( $mandate['payment_method_details']['sepa_debit'] ?? array() );

		return array(
			'payment_method_id'   => (string) ( $payment_method['id'] ?? '' ),
			'mandate_id'          => (string) ( $mandate['id'] ?? '' ),
			'mandate_reference'   => (string) ( $details['reference'] ?? '' ),
			'mandate_url'         => (string) ( $details['url'] ?? '' ),
			'mandate_status'      => (string) ( $mandate['status'] ?? '' ),
			'iban_last4'          => (string) ( $sepa['last4'] ?? '' ),
			'country'             => (string) ( $sepa['country'] ?? '' ),
			'bank_code'           => (string) ( $sepa['bank_code'] ?? '' ),
			'branch_code'         => (string) ( $sepa['branch_code'] ?? '' ),
			'account_holder_name' => (string) ( $payment_method['billing_details']['name'] ?? '' ),
		);
	}

	/**
	 * Ajoute la preuve du consentement au mandat.
	 *
	 * La soumission du formulaire vaut signature électronique : la date, l'heure
	 * et l'adresse de l'auteur constituent la preuve exigible en cas de
	 * contestation.
	 *
	 * @param array  $data        Données de mandat.
	 * @param string $ip_address  Adresse de l'auteur de l'acceptation.
	 * @param string $accepted_at Horodatage UTC, au format MySQL.
	 * @return array
	 */
	public static function with_acceptance( array $data, string $ip_address, string $accepted_at ): array {
		return array_merge(
			$data,
			array(
				'accepted_at' => $accepted_at,
				'accepted_ip' => $ip_address,
			)
		);
	}

	/**
	 * Représentation masquée de l'IBAN, destinée à l'affichage.
	 *
	 * Suffisamment précise pour que le titulaire reconnaisse son compte, trop
	 * imprécise pour identifier le compte de quelqu'un d'autre.
	 *
	 * @param array $data Données de mandat.
	 * @return string
	 */
	public static function masked_iban( array $data ): string {
		$last4   = (string) ( $data['iban_last4'] ?? '' );
		$country = strtoupper( (string) ( $data['country'] ?? '' ) );

		if ( '' === $last4 ) {
			return '';
		}

		$prefix = '' !== $country ? $country . '••' : '••••';

		return $prefix . ' •••• •••• ' . $last4;
	}
}
