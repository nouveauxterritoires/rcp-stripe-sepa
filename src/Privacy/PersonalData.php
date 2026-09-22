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
			__( 'Bank account debited', 'rcp-stripe-sepa' ) => MandateData::masked_iban( $mandate ),
			__( 'Account holder', 'rcp-stripe-sepa' )    => (string) ( $mandate['account_holder_name'] ?? '' ),
			__( 'Account country', 'rcp-stripe-sepa' )   => (string) ( $mandate['country'] ?? '' ),
			__( 'Mandate reference', 'rcp-stripe-sepa' ) => (string) ( $mandate['mandate_reference'] ?? '' ),
			__( 'Mandate status', 'rcp-stripe-sepa' )    => (string) ( $mandate['mandate_status'] ?? '' ),
			__( 'Mandate signed on', 'rcp-stripe-sepa' ) => (string) ( $mandate['accepted_at'] ?? '' ),
			__( 'Signature IP address', 'rcp-stripe-sepa' ) => (string) ( $mandate['accepted_ip'] ?? '' ),
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
				'When you pay for your membership by SEPA Direct Debit, your bank details are entered directly with Stripe, our payment provider, and never pass through this site. We store neither your full IBAN nor anything else that would allow your account to be debited.',
				'rcp-stripe-sepa'
			),
			__(
				'For as long as your membership lasts, we keep the last four characters of your IBAN, the country of the account, the account holder’s name, your mandate reference and its status. These let you recognise the debit on your bank statement.',
				'rcp-stripe-sepa'
			),
			__(
				'The date and IP address recorded when you signed the mandate are the evidence of your consent. The IP address is deleted after 13 months, beyond which a debit can no longer be disputed.',
				'rcp-stripe-sepa'
			)
		);
	}
}
