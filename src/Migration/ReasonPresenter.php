<?php
/**
 * Messages associés aux décisions d'éligibilité.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Migration;

/**
 * Traduit un motif d'inéligibilité en message destiné à l'adhérent.
 *
 * Les messages restent volontairement peu diserts sur les causes techniques :
 * un adhérent n'a que faire d'un identifiant de client Stripe, et les détails
 * n'ont pas à transiter vers le navigateur.
 */
final class ReasonPresenter {

	/**
	 * Message correspondant à un motif.
	 *
	 * @param string $reason Motif d'inéligibilité.
	 * @return string
	 */
	public static function message( string $reason ): string {
		switch ( $reason ) {
			case Eligibility::REASON_NOT_ACTIVE:
				return __( 'Seule une adhésion active peut basculer sur le prélèvement SEPA.', 'rcp-stripe-sepa' );

			case Eligibility::REASON_NOT_RECURRING:
				return __( 'Cette adhésion n\'a pas d\'échéance à venir : changer de moyen de paiement serait sans effet.', 'rcp-stripe-sepa' );

			case Eligibility::REASON_CURRENCY:
				return __( 'Le prélèvement SEPA n\'est possible que pour les paiements en euros.', 'rcp-stripe-sepa' );

			case Eligibility::REASON_UNSUPPORTED_GATEWAY:
			case Eligibility::REASON_NO_CUSTOMER:
			default:
				return __( 'Le prélèvement SEPA n\'est pas disponible pour cette adhésion.', 'rcp-stripe-sepa' );
		}
	}

	/**
	 * Message lorsqu'une opération échoue sans cause exploitable par l'adhérent.
	 *
	 * @return string
	 */
	public static function generic_failure(): string {
		return __( 'La bascule vers le prélèvement SEPA a échoué. Réessayez, ou contactez-nous si le problème persiste.', 'rcp-stripe-sepa' );
	}

	/**
	 * Message lorsque le mandat n'a pas été confirmé.
	 *
	 * @return string
	 */
	public static function not_confirmed(): string {
		return __( 'Le mandat n\'a pas été confirmé. Reprenez la saisie de vos coordonnées bancaires.', 'rcp-stripe-sepa' );
	}
}
