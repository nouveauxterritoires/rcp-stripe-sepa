<?php
/**
 * Composition des données personnelles liées au prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Privacy;

use RCP_Stripe_Sepa\Mandate\MandateData;

/**
 * Décide ce qu'un mandat expose lorsqu'une personne demande ses données.
 *
 * Un mandat de prélèvement est une donnée personnelle : il désigne un compte
 * bancaire, un titulaire, et porte la preuve horodatée d'un consentement. Ce
 * qui en est restitué doit renseigner la personne sur ce que le site détient,
 * sans y ajouter d'identifiants techniques qui ne lui apprendraient rien.
 *
 * @see docs/cahier-des-charges.md §10.2
 */
final class PersonalData {

	/**
	 * Éléments exportables d'un mandat.
	 *
	 * Le tableau fourni n'est jamais modifié.
	 *
	 * @param array $mandate Données de mandat.
	 * @return array[] Éléments au format attendu par WordPress.
	 */
	public static function mandate_items( array $mandate ): array {
		if ( array() === $mandate ) {
			return array();
		}

		$fields = array(
			__( 'Compte bancaire débité', 'rcp-stripe-sepa' )  => MandateData::masked_iban( $mandate ),
			__( 'Titulaire du compte', 'rcp-stripe-sepa' )     => (string) ( $mandate['account_holder_name'] ?? '' ),
			__( 'Pays du compte', 'rcp-stripe-sepa' )          => (string) ( $mandate['country'] ?? '' ),
			__( 'Référence du mandat', 'rcp-stripe-sepa' )     => (string) ( $mandate['mandate_reference'] ?? '' ),
			__( 'Statut du mandat', 'rcp-stripe-sepa' )        => (string) ( $mandate['mandate_status'] ?? '' ),
			__( 'Mandat signé le', 'rcp-stripe-sepa' )         => (string) ( $mandate['accepted_at'] ?? '' ),
			__( 'Adresse IP de signature', 'rcp-stripe-sepa' ) => (string) ( $mandate['accepted_ip'] ?? '' ),
		);

		$items = array();

		foreach ( $fields as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}

			$items[] = array(
				'name'  => $name,
				'value' => $value,
			);
		}

		return $items;
	}

	/**
	 * Mention à proposer dans la politique de confidentialité du site.
	 *
	 * @return string
	 */
	public static function privacy_policy_content(): string {
		return sprintf(
			'<p>%s</p><p>%s</p><p>%s</p>',
			__(
				'Lorsque vous réglez votre adhésion par prélèvement SEPA, vos coordonnées bancaires sont saisies directement chez Stripe, notre prestataire de paiement, et ne transitent jamais par ce site. Nous ne conservons ni votre IBAN complet ni aucune donnée permettant de débiter votre compte.',
				'rcp-stripe-sepa'
			),
			__(
				'Nous conservons, pour la durée de votre adhésion : les quatre derniers caractères de votre IBAN, le pays du compte, le nom du titulaire, la référence de votre mandat et son statut. Ces informations vous permettent de reconnaître le prélèvement sur votre relevé bancaire.',
				'rcp-stripe-sepa'
			),
			__(
				'La date et l\'adresse IP de signature du mandat constituent la preuve de votre consentement. L\'adresse IP est supprimée au bout de 13 mois, durée au-delà de laquelle un prélèvement ne peut plus être contesté.',
				'rcp-stripe-sepa'
			)
		);
	}
}
