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
				return __( 'Only an active membership can switch to SEPA Direct Debit.', 'rcp-stripe-sepa' );

			case Eligibility::REASON_NOT_RECURRING:
				return __( 'This membership has no upcoming renewal, so changing the payment method would have no effect.', 'rcp-stripe-sepa' );

			case Eligibility::REASON_CURRENCY:
				return __( 'SEPA Direct Debit is only available for payments in euros.', 'rcp-stripe-sepa' );

			case Eligibility::REASON_UNSUPPORTED_GATEWAY:
			case Eligibility::REASON_NO_CUSTOMER:
			default:
				return __( 'SEPA Direct Debit is not available for this membership.', 'rcp-stripe-sepa' );
		}
	}

	/**
	 * Message lorsqu'une opération échoue sans cause exploitable par l'adhérent.
	 *
	 * @return string
	 */
	public static function generic_failure(): string {
		return __( 'Switching to SEPA Direct Debit failed. Please try again, or contact us if the problem persists.', 'rcp-stripe-sepa' );
	}

	/**
	 * Message lorsque le mandat n'a pas été confirmé.
	 *
	 * @return string
	 */
	public static function not_confirmed(): string {
		return __( 'The mandate was not confirmed. Please enter your bank details again.', 'rcp-stripe-sepa' );
	}
}
