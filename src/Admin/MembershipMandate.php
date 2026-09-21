<?php
/**
 * Affichage du mandat sur la fiche d'adhésion, côté administration.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Admin;

use RCP_Membership;
use RCP_Stripe_Sepa\Gateway\GatewayDefinition;
use RCP_Stripe_Sepa\Mandate\MandateData;
use RCP_Stripe_Sepa\Mandate\MandateRepository;
use RCP_Stripe_Sepa\Webhook\WebhookSecret;

/**
 * Montre à l'administrateur l'état du mandat d'une adhésion.
 *
 * Sans cet affichage, répondre à « pourquoi ce prélèvement n'est-il pas
 * passé ? » suppose d'ouvrir le tableau de bord Stripe et d'y retrouver le
 * client à la main.
 */
final class MembershipMandate {

	/**
	 * Branche l'affichage sur la fiche d'adhésion.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rcp_edit_membership_after', array( self::class, 'render' ) );
	}

	/**
	 * Affiche le mandat, s'il y en a un.
	 *
	 * @param mixed $membership Adhésion en cours d'édition.
	 * @return void
	 */
	public static function render( $membership = null ): void {
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

		$rows  = self::rows( $mandate );
		$links = self::stripe_links( $membership, $mandate );

		require __DIR__ . '/../../templates/admin-membership-mandate.php';
	}

	/**
	 * Lignes à présenter.
	 *
	 * @param array $mandate Données de mandat.
	 * @return array<string, string>
	 */
	private static function rows( array $mandate ): array {
		return array_filter(
			array(
				__( 'IBAN', 'rcp-stripe-sepa' )       => MandateData::masked_iban( $mandate ),
				__( 'Account holder', 'rcp-stripe-sepa' )  => (string) $mandate['account_holder_name'],
				__( 'Mandate reference', 'rcp-stripe-sepa' ) => (string) $mandate['mandate_reference'],
				__( 'Mandate status', 'rcp-stripe-sepa' ) => (string) $mandate['mandate_status'],
				__( 'Accepted on', 'rcp-stripe-sepa' ) => (string) $mandate['accepted_at'],
			),
			static function ( string $value ): bool {
				return '' !== $value;
			}
		);
	}

	/**
	 * Liens vers le tableau de bord Stripe, dans le bon mode.
	 *
	 * @param RCP_Membership $membership Adhésion concernée.
	 * @param array          $mandate    Données de mandat.
	 * @return array<string, string>
	 */
	private static function stripe_links( RCP_Membership $membership, array $mandate ): array {
		$base = 'https://dashboard.stripe.com/' . ( WebhookSecret::is_test_mode() ? 'test/' : '' );

		$links = array();

		if ( '' !== (string) $membership->get_gateway_customer_id() ) {
			$links[ __( 'Stripe customer', 'rcp-stripe-sepa' ) ] = $base . 'customers/' . $membership->get_gateway_customer_id();
		}

		if ( '' !== (string) $membership->get_gateway_subscription_id() ) {
			$links[ __( 'Stripe subscription', 'rcp-stripe-sepa' ) ] = $base . 'subscriptions/' . $membership->get_gateway_subscription_id();
		}

		if ( '' !== (string) $mandate['mandate_url'] ) {
			$links[ __( 'Signed mandate', 'rcp-stripe-sepa' ) ] = (string) $mandate['mandate_url'];
		}

		return $links;
	}
}
