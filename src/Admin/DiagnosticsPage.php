<?php
/**
 * Écran de diagnostic du prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Admin;

use RCP_Stripe_Sepa\Webhook\Endpoint;
use RCP_Stripe_Sepa\Webhook\EventStore;

/**
 * Ajoute un écran « Prélèvement SEPA » au menu de Restrict Content Pro.
 *
 * L'écran rassemble l'état de la configuration, les derniers événements reçus
 * et de quoi en rejouer un. C'est le premier endroit où regarder lorsqu'une
 * adhésion reste en attente.
 */
final class DiagnosticsPage {

	public const PAGE_SLUG        = 'rcp-stripe-sepa';
	public const PARENT_SLUG      = 'rcp-members';
	public const CAPABILITY       = 'rcp_manage_settings';
	public const REPLAY_ACTION    = 'rcp_stripe_sepa_replay_event';
	public const NOTICE_TRANSIENT = 'rcp_stripe_sepa_admin_notice';

	/**
	 * Branche l'écran sur l'administration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ), 20 );
		add_action( 'admin_post_' . self::REPLAY_ACTION, array( self::class, 'handle_replay' ) );
	}

	/**
	 * Déclare l'écran dans le menu de RCP.
	 *
	 * @return void
	 */
	public static function add_page(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Prélèvement SEPA', 'rcp-stripe-sepa' ),
			__( 'Prélèvement SEPA', 'rcp-stripe-sepa' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( self::class, 'render' ),
			12
		);
	}

	/**
	 * URL de l'écran.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Affiche l'écran.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas les droits nécessaires.', 'rcp-stripe-sepa' ) );
		}

		$report   = Diagnostics::report();
		$events   = EventStore::recent( 20 );
		$endpoint = Endpoint::url();
		$notice   = get_transient( self::NOTICE_TRANSIENT );

		delete_transient( self::NOTICE_TRANSIENT );

		require __DIR__ . '/../../templates/admin-diagnostics.php';
	}

	/**
	 * Traite une demande de rejeu.
	 *
	 * @return void
	 */
	public static function handle_replay(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas les droits nécessaires.', 'rcp-stripe-sepa' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::REPLAY_ACTION );

		$event_id = isset( $_POST['event_id'] ) ? sanitize_text_field( wp_unslash( $_POST['event_id'] ) ) : '';

		$result = EventReplay::replay( $event_id );

		set_transient(
			self::NOTICE_TRANSIENT,
			array(
				'type'    => is_wp_error( $result ) ? 'error' : 'success',
				'message' => is_wp_error( $result ) ? $result->get_error_message() : (string) $result,
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( self::url() );
		exit;
	}
}
