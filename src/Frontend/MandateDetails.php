<?php
/**
 * Affichage du mandat sur la page « Mon compte ».
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Frontend;

use RCP_Membership;
use RCP_Stripe_Sepa\Gateway\GatewayDefinition;
use RCP_Stripe_Sepa\Mandate\MandateData;
use RCP_Stripe_Sepa\Mandate\MandateRepository;

/**
 * Montre à l'adhérent le mandat qui règle son adhésion.
 *
 * Un prélèvement paraît opaque : on ne sait ni quel compte est débité, ni au
 * nom de quoi. Rappeler l'IBAN masqué, la référence du mandat et la date de
 * signature réduit les contestations — et donne à l'adhérent de quoi se
 * reconnaître sur son relevé bancaire.
 */
final class MandateDetails {

	/**
	 * Branche l'affichage sur le gabarit de RCP.
	 *
	 * La priorité est inférieure à celle du formulaire de migration : le
	 * mandat en vigueur se lit avant qu'on ne propose d'en changer.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rcp_subscription_details_bottom', array( self::class, 'render' ), 5 );
	}

	/**
	 * Affiche les mandats des adhésions de l'utilisateur courant.
	 *
	 * @return void
	 */
	public static function render(): void {
		$customer = rcp_get_customer_by_user_id( get_current_user_id() );

		if ( empty( $customer ) ) {
			return;
		}

		foreach ( (array) rcp_get_customer_memberships( $customer->get_id() ) as $membership ) {
			self::render_one( $membership );
		}
	}

	/**
	 * Affiche le mandat d'une adhésion.
	 *
	 * @param mixed $membership Adhésion concernée.
	 * @return void
	 */
	private static function render_one( $membership ): void {
		if ( ! $membership instanceof RCP_Membership ) {
			return;
		}

		if ( GatewayDefinition::ID !== (string) $membership->get_gateway() ) {
			return;
		}

		$mandate = MandateRepository::find( (int) $membership->get_id() );

		if ( array() === $mandate ) {
			return;
		}

		$masked_iban = MandateData::masked_iban( $mandate );

		require __DIR__ . '/../../templates/account-mandate.php';
	}
}
