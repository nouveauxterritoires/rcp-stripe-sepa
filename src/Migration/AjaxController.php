<?php
/**
 * Requêtes AJAX de migration vers le prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Migration;

use RCP_Membership;
use WP_Error;

/**
 * Point d'entrée AJAX de la bascule.
 *
 * Chaque requête est authentifiée trois fois : session WordPress, nonce, et
 * vérification que l'adhésion visée appartient bien à l'utilisateur. Cette
 * dernière est la plus importante — sans elle, un adhérent pourrait modifier
 * le moyen de paiement de l'adhésion d'un autre.
 *
 * @see docs/cahier-des-charges.md §9.5 SEC-17
 */
final class AjaxController {

	public const ACTION_START    = 'rcp_stripe_sepa_start_migration';
	public const ACTION_COMPLETE = 'rcp_stripe_sepa_complete_migration';

	/**
	 * Enregistre les points d'entrée.
	 *
	 * Seule la variante authentifiée est déclarée : la migration n'a aucun sens
	 * pour un visiteur non connecté.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION_START, array( self::class, 'start' ) );
		add_action( 'wp_ajax_' . self::ACTION_COMPLETE, array( self::class, 'complete' ) );
	}

	/**
	 * Prépare la collecte d'un nouveau mandat.
	 *
	 * @return void
	 */
	public static function start(): void {
		$membership = self::authorize();

		if ( is_wp_error( $membership ) ) {
			wp_send_json_error( array( 'message' => $membership->get_error_message() ), 403 );
		}

		$result = Migrator::start( $membership );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Applique le mandat accepté.
	 *
	 * @return void
	 */
	public static function complete(): void {
		$membership = self::authorize();

		if ( is_wp_error( $membership ) ) {
			wp_send_json_error( array( 'message' => $membership->get_error_message() ), 403 );
		}

		/*
		 * Le nonce et la propriété de l'adhésion ont été vérifiés par
		 * `authorize()` ci-dessus : la requête est authentifiée avant que la
		 * moindre donnée ne soit lue.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$setup_intent = isset( $_POST['setup_intent_id'] )
			? sanitize_text_field( wp_unslash( $_POST['setup_intent_id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $setup_intent ) {
			wp_send_json_error( array( 'message' => ReasonPresenter::not_confirmed() ), 400 );
		}

		$result = Migrator::complete( $membership, $setup_intent );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Votre adhésion est désormais réglée par prélèvement SEPA.', 'rcp-stripe-sepa' ),
			)
		);
	}

	/**
	 * Vérifie la session, le nonce et la propriété de l'adhésion.
	 *
	 * @return RCP_Membership|WP_Error
	 */
	private static function authorize() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rcp_stripe_sepa_not_logged_in', ReasonPresenter::generic_failure() );
		}

		if ( ! check_ajax_referer( AccountPage::NONCE_ACTION, 'nonce', false ) ) {
			return new WP_Error( 'rcp_stripe_sepa_bad_nonce', ReasonPresenter::generic_failure() );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Vérifié juste au-dessus.
		$membership_id = isset( $_POST['membership_id'] ) ? absint( wp_unslash( $_POST['membership_id'] ) ) : 0;
		$membership    = $membership_id > 0 ? rcp_get_membership( $membership_id ) : false;

		if ( ! $membership instanceof RCP_Membership ) {
			return new WP_Error( 'rcp_stripe_sepa_unknown_membership', ReasonPresenter::generic_failure() );
		}

		/*
		 * Contrôle de propriété. Le message renvoyé est le même que pour une
		 * adhésion inexistante : distinguer les deux cas permettrait d'énumérer
		 * les identifiants d'adhésion existants.
		 */
		if ( (int) $membership->get_user_id() !== get_current_user_id() ) {
			rcp_log(
				sprintf(
					'Migration SEPA : utilisateur #%d a tenté d\'agir sur l\'adhésion #%d qui ne lui appartient pas.',
					get_current_user_id(),
					$membership_id
				),
				true
			);

			return new WP_Error( 'rcp_stripe_sepa_forbidden', ReasonPresenter::generic_failure() );
		}

		return $membership;
	}
}
