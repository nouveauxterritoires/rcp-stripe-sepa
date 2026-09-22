<?php
/**
 * Champ de réglage du secret de webhook.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Admin;

use RCP_Stripe_Sepa\Webhook\WebhookSecret;

/**
 * Saisie du secret de webhook, sans jamais le réafficher.
 *
 * Un champ pré-rempli avec la valeur réelle la livre à quiconque ouvre
 * l'inspecteur du navigateur, la met dans le cache du gestionnaire de mots de
 * passe, et la fait apparaître dans une capture d'écran de support. Le champ
 * reste donc vide : le laisser tel quel conserve la valeur en place, le remplir
 * la remplace.
 *
 * @see docs/cahier-des-charges.md §9.2 SEC-02, SEC-03
 */
final class SecretField {

	public const ACTION = 'rcp_stripe_sepa_save_secret';
	public const FIELD  = 'rcp_stripe_sepa_webhook_secret';

	/**
	 * Attributs communs au champ, quel que soit son état.
	 *
	 * Ils ne relèvent pas de la présentation : `password` empêche l'affichage
	 * en clair, `off` empêche la mémorisation par le navigateur.
	 *
	 * @return array<string, string>
	 */
	public static function attributes(): array {
		return array(
			'type'         => 'password',
			'autocomplete' => 'off',
			'spellcheck'   => 'false',
			'value'        => '',
		);
	}

	/**
	 * Le champ est-il en lecture seule ?
	 *
	 * Une constante de `wp-config.php` prévaut sur l'option : la modifier
	 * depuis l'administration n'aurait aucun effet, et laisser croire le
	 * contraire serait pire que refuser la saisie.
	 *
	 * @return bool
	 */
	public static function is_locked(): bool {
		return WebhookSecret::is_locked();
	}

	/**
	 * Le secret du mode courant est-il renseigné ?
	 *
	 * @return bool
	 */
	public static function is_set(): bool {
		return '' !== WebhookSecret::get();
	}

	/**
	 * Option du mode courant.
	 *
	 * @return string
	 */
	public static function option_name(): string {
		return WebhookSecret::is_test_mode() ? WebhookSecret::OPTION_TEST : WebhookSecret::OPTION_LIVE;
	}

	/**
	 * Nom de la constante qui prévaudrait sur l'option.
	 *
	 * @return string
	 */
	public static function constant_name(): string {
		return WebhookSecret::is_test_mode() ? WebhookSecret::CONSTANT_TEST : WebhookSecret::CONSTANT_LIVE;
	}

	/**
	 * Indication affichée à la place de la valeur.
	 *
	 * @return string
	 */
	public static function placeholder(): string {
		return self::is_set()
			? __( 'Defined — leave blank to keep it', 'rcp-stripe-sepa' )
			: __( 'whsec_…', 'rcp-stripe-sepa' );
	}

	/**
	 * Enregistre le traitement du formulaire.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Enregistre le secret soumis.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( DiagnosticsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'rcp-stripe-sepa' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		$submitted = isset( $_POST[ self::FIELD ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) ) : '';

		set_transient(
			DiagnosticsPage::NOTICE_TRANSIENT,
			self::save( $submitted ),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( DiagnosticsPage::url() );
		exit;
	}

	/**
	 * Applique une valeur soumise et décrit le résultat.
	 *
	 * Séparé de `handle()` pour être éprouvable sans requête HTTP.
	 *
	 * @param string    $submitted Valeur soumise, déjà assainie.
	 * @param bool|null $locked    Verrouillage à considérer ; lu dans la
	 *                             configuration si omis. Une constante ne
	 *                             pouvant être défaite, l'injecter est le seul
	 *                             moyen d'éprouver les deux branches.
	 * @return array{type: string, message: string}
	 */
	public static function save( string $submitted, ?bool $locked = null ): array {
		if ( null === $locked ? self::is_locked() : $locked ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: PHP constant name. */
					__( 'The webhook secret is defined by the %s constant and cannot be changed here.', 'rcp-stripe-sepa' ),
					self::constant_name()
				),
			);
		}

		if ( '' === $submitted ) {
			return array(
				'type'    => 'info',
				'message' => __( 'Webhook secret left unchanged.', 'rcp-stripe-sepa' ),
			);
		}

		if ( 0 !== strpos( $submitted, 'whsec_' ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'A Stripe webhook secret starts with “whsec_”.', 'rcp-stripe-sepa' ),
			);
		}

		update_option( self::option_name(), $submitted, false );

		return array(
			'type'    => 'success',
			'message' => __( 'Webhook secret saved.', 'rcp-stripe-sepa' ),
		);
	}
}
