<?php
/**
 * Avis d'administration signalant une incohérence de mode.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Mode;

use RCP_Stripe_Sepa\Compat\Capability;

/**
 * Explique pourquoi la passerelle SEPA a disparu des réglages.
 *
 * Sans cet avis, `ModeGuard` retirerait la passerelle en silence : le symptôme
 * — une option qui n'apparaît plus — n'indique en rien sa cause.
 *
 * @see docs/cahier-des-charges.md §9.6 SEC-24
 */
final class ModeNotice {

	/**
	 * Branche l'avis.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	/**
	 * Affiche l'avis lorsque le mode et la clé se contredisent.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Capability::MANAGE_SETTINGS ) ) {
			return;
		}

		$message = ModeGuard::message();

		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
