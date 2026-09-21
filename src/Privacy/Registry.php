<?php
/**
 * Intégration aux outils de confidentialité de WordPress.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Privacy;

use RCP_Stripe_Sepa\Mandate\MandateRepository;

/**
 * Expose les mandats aux outils d'export et d'effacement de WordPress.
 *
 * WordPress fournit les formulaires, la vérification d'identité par e-mail et
 * le suivi des demandes : le plugin n'a qu'à déclarer ce qu'il détient et
 * comment l'effacer.
 *
 * @see docs/cahier-des-charges.md §10.2 CNF-05 et CNF-06
 */
final class Registry {

	public const EXPORTER_ID = 'rcp-stripe-sepa-mandates';
	public const ERASER_ID   = 'rcp-stripe-sepa-mandates';

	/**
	 * Tâche planifiée de purge des adresses de signature.
	 */
	public const PURGE_HOOK = 'rcp_stripe_sepa_purge_mandate_ips';

	/**
	 * Branche le plugin sur les outils de WordPress.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'add_eraser' ) );
		add_action( 'admin_init', array( self::class, 'suggest_privacy_policy' ) );
		add_action( self::PURGE_HOOK, array( self::class, 'run_scheduled_purge' ) );

		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	// -- Déclarations ------------------------------------------------------------

	/**
	 * Déclare l'exportateur.
	 *
	 * @param array $exporters Exportateurs existants.
	 * @return array
	 */
	public static function add_exporter( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();

		$exporters[ self::EXPORTER_ID ] = array(
			'exporter_friendly_name' => __( 'SEPA Direct Debit mandates', 'rcp-stripe-sepa' ),
			'callback'               => array( self::class, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Déclare l'effaceur.
	 *
	 * @param array $erasers Effaceurs existants.
	 * @return array
	 */
	public static function add_eraser( $erasers ): array {
		$erasers = is_array( $erasers ) ? $erasers : array();

		$erasers[ self::ERASER_ID ] = array(
			'eraser_friendly_name' => __( 'SEPA Direct Debit mandates', 'rcp-stripe-sepa' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Propose une mention pour la politique de confidentialité.
	 *
	 * @return void
	 */
	public static function suggest_privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'SEPA Direct Debit', 'rcp-stripe-sepa' ),
			wp_kses_post( PersonalData::privacy_policy_content() )
		);
	}

	// -- Export ---------------------------------------------------------------------

	/**
	 * Exporte les mandats d'une personne.
	 *
	 * @param string $email Adresse de la personne concernée.
	 * @param int    $page  Page demandée.
	 * @return array
	 */
	public static function export( $email, $page = 1 ): array {
		$export = array(
			'data' => array(),
			'done' => true,
		);

		foreach ( self::memberships_for( (string) $email ) as $membership ) {
			$items = PersonalData::mandate_items( MandateRepository::find( (int) $membership->get_id() ) );

			if ( array() === $items ) {
				continue;
			}

			$export['data'][] = array(
				'group_id'    => 'rcp-stripe-sepa',
				'group_label' => __( 'SEPA Direct Debit mandates', 'rcp-stripe-sepa' ),
				'item_id'     => 'mandate-' . (int) $membership->get_id(),
				'data'        => $items,
			);
		}

		return $export;
	}

	// -- Effacement -------------------------------------------------------------------

	/**
	 * Efface les mandats d'une personne.
	 *
	 * @param string $email Adresse de la personne concernée.
	 * @param int    $page  Page demandée.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ): array {
		$removed  = false;
		$messages = array();

		foreach ( self::memberships_for( (string) $email ) as $membership ) {
			if ( array() === MandateRepository::find( (int) $membership->get_id() ) ) {
				continue;
			}

			MandateRepository::forget( (int) $membership->get_id() );

			$removed = true;
		}

		if ( $removed ) {
			/*
			 * Les données détenues par Stripe ne sont pas supprimées : elles
			 * relèvent des obligations comptables du site, qui priment sur le
			 * droit à l'effacement pour la durée de conservation légale.
			 * L'administrateur doit en être informé plutôt que de croire
			 * l'effacement complet.
			 */
			$messages[] = __(
				'The mandates held on this site have been deleted. Data held by Stripe falls under your accounting obligations and must be handled from your Stripe dashboard.',
				'rcp-stripe-sepa'
			);
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	// -- Conservation ---------------------------------------------------------------------

	/**
	 * Exécute la purge planifiée.
	 *
	 * Un rappel d'action ne doit rien renvoyer : la valeur utile reste
	 * accessible par `purge_expired_ips()`, que les tests appellent.
	 *
	 * @return void
	 */
	public static function run_scheduled_purge(): void {
		self::purge_expired_ips();
	}

	/**
	 * Supprime les adresses de signature au-delà de la durée de conservation.
	 *
	 * @return int Nombre d'adresses purgées.
	 */
	public static function purge_expired_ips(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'rcp_membershipmeta';
		$key   = MandateRepository::META_PREFIX . 'accepted_at';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$membership_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT rcp_membership_id FROM {$table}
					WHERE meta_key = %s
					AND meta_value < DATE_SUB( %s, INTERVAL %d MONTH )",
				$key,
				current_time( 'mysql', true ),
				MandateRepository::IP_RETENTION_MONTHS
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$purged = 0;

		foreach ( (array) $membership_ids as $membership_id ) {
			if ( MandateRepository::maybe_purge_ip( (int) $membership_id ) ) {
				++$purged;
			}
		}

		return $purged;
	}

	// -- Utilitaires -------------------------------------------------------------------------

	/**
	 * Adhésions rattachées à une adresse électronique.
	 *
	 * @param string $email Adresse de la personne concernée.
	 * @return array
	 */
	private static function memberships_for( string $email ): array {
		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return array();
		}

		$customer = rcp_get_customer_by_user_id( (int) $user->ID );

		if ( empty( $customer ) ) {
			return array();
		}

		return (array) rcp_get_customer_memberships( $customer->get_id() );
	}
}
