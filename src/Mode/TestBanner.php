<?php
/**
 * Bandeau permanent signalant le mode test de Stripe.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Mode;

use RCP_Stripe_Sepa\Compat\Capability;

/**
 * Affiche, en mode test, un avertissement que rien ne peut masquer.
 *
 * Un formulaire de paiement en mode test est indiscernable d'un formulaire réel
 * : même apparence, même parcours, même confirmation. Un adhérent peut croire
 * avoir payé, un administrateur croire le site en production. Le bandeau n'est
 * donc ni masquable ni temporisé — il disparaît en quittant le mode test, et
 * pas autrement.
 *
 * @see docs/cahier-des-charges.md §9.6 SEC-23
 */
final class TestBanner {

	/**
	 * Branche le bandeau côté membre et côté administration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render_admin_notice' ) );
	}

	/**
	 * Le bandeau doit-il s'afficher ?
	 *
	 * @return bool
	 */
	public static function is_needed(): bool {
		return ModeGuard::is_test_mode();
	}

	/**
	 * Texte du bandeau.
	 *
	 * @return string
	 */
	public static function message(): string {
		return __( 'Stripe test mode — no real payment will be collected.', 'rcp-stripe-sepa' );
	}

	/**
	 * Bandeau destiné au formulaire d'inscription.
	 *
	 * @return string HTML échappé, ou chaîne vide hors mode test.
	 */
	public static function render(): string {
		if ( ! self::is_needed() ) {
			return '';
		}

		return sprintf(
			'<p class="rcp-stripe-sepa-test-mode" role="status">%s</p>',
			esc_html( self::message() )
		);
	}

	/**
	 * Bandeau destiné à l'administration.
	 *
	 * Aucune classe `is-dismissible` : l'avis ne doit pas pouvoir être écarté.
	 *
	 * @return void
	 */
	public static function render_admin_notice(): void {
		if ( ! self::is_needed() || ! current_user_can( Capability::MANAGE_SETTINGS ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning rcp-stripe-sepa-test-mode"><p>%s</p></div>',
			esc_html( self::message() )
		);
	}
}
