<?php
/**
 * Journal des événements de webhook reçus.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Webhook;

/**
 * Assure l'idempotence du traitement des webhooks.
 *
 * Stripe rejoue un événement tant qu'il n'a pas reçu de réponse 2xx, et peut
 * le livrer plusieurs fois même après succès. Sans verrou, un rejeu créerait un
 * second enregistrement de paiement et fausserait la comptabilité.
 *
 * Le verrou repose sur l'unicité de `event_id` en base : deux requêtes
 * concurrentes portant le même événement ne peuvent pas le réclamer toutes les
 * deux, l'insertion de la seconde échouant sur la contrainte.
 *
 * @see docs/cahier-des-charges.md §7.3 et §8.4
 */
final class EventStore {

	public const STATUS_RECEIVED  = 'received';
	public const STATUS_PROCESSED = 'processed';
	public const STATUS_SKIPPED   = 'skipped';
	public const STATUS_FAILED    = 'failed';

	public const CLAIM_GRANTED   = 'granted';
	public const CLAIM_DUPLICATE = 'duplicate';
	public const CLAIM_RETRY     = 'retry';

	/**
	 * Version du schéma, incrémentée à chaque modification de la table.
	 */
	public const SCHEMA_VERSION = '1';

	public const OPTION_SCHEMA_VERSION = 'rcp_stripe_sepa_db_version';

	/**
	 * Nombre de tentatives au-delà duquel l'événement est abandonné.
	 */
	public const MAX_ATTEMPTS = 5;

	/*
	 * Les requêtes de cette classe interpolent le nom de la table, construit à
	 * partir de `$wpdb->prefix` et d'un littéral : il ne provient d'aucune
	 * entrée. Toutes les valeurs, elles, passent par `$wpdb->prepare()`. Le
	 * journal n'est par ailleurs jamais mis en cache : son intérêt tient
	 * précisément à sa fraîcheur.
	 */
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange

	/**
	 * Nom complet de la table.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'rcp_sepa_webhook_events';
	}

	/**
	 * Crée ou met à jour la table.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		if ( (string) get_option( self::OPTION_SCHEMA_VERSION, '' ) === self::SCHEMA_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id varchar(255) NOT NULL,
				event_type varchar(100) NOT NULL,
				livemode tinyint(1) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'received',
				attempts smallint(5) unsigned NOT NULL DEFAULT 1,
				payload_digest char(64) NOT NULL DEFAULT '',
				note text NULL,
				created_at datetime NOT NULL,
				processed_at datetime NULL,
				PRIMARY KEY (id),
				UNIQUE KEY event_id (event_id),
				KEY event_type_created (event_type, created_at),
				KEY status (status)
			) {$collate};"
		);

		update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Tente de réserver le traitement d'un événement.
	 *
	 * @param string $event_id   Identifiant Stripe de l'événement.
	 * @param string $event_type Type d'événement.
	 * @param bool   $livemode   L'événement provient-il du mode production.
	 * @param string $payload    Charge utile brute, dont seule l'empreinte est conservée.
	 * @return string CLAIM_GRANTED, CLAIM_DUPLICATE ou CLAIM_RETRY.
	 */
	public static function claim( string $event_id, string $event_type, bool $livemode, string $payload ): string {
		global $wpdb;

		$table = self::table();

		// `INSERT IGNORE` échoue silencieusement sur la contrainte d'unicité :
		// c'est ce qui rend la réservation sûre entre requêtes concurrentes.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
					( event_id, event_type, livemode, status, attempts, payload_digest, created_at )
					VALUES ( %s, %s, %d, %s, 1, %s, %s )",
				$event_id,
				$event_type,
				$livemode ? 1 : 0,
				self::STATUS_RECEIVED,
				hash( 'sha256', $payload ),
				current_time( 'mysql', true )
			)
		);

		if ( 1 === (int) $inserted ) {
			return self::CLAIM_GRANTED;
		}

		$status = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$table} WHERE event_id = %s", $event_id )
		);

		if ( self::STATUS_PROCESSED === $status || self::STATUS_SKIPPED === $status ) {
			return self::CLAIM_DUPLICATE;
		}

		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE event_id = %s", $event_id )
		);

		return self::CLAIM_RETRY;
	}

	/**
	 * Enregistre l'issue du traitement.
	 *
	 * @param string $event_id Identifiant de l'événement.
	 * @param string $status   Statut final.
	 * @param string $note     Note lisible.
	 * @return void
	 */
	public static function resolve( string $event_id, string $status, string $note = '' ): void {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'status'       => $status,
				'note'         => mb_substr( $note, 0, 1000 ),
				'processed_at' => current_time( 'mysql', true ),
			),
			array( 'event_id' => $event_id ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Nombre de tentatives enregistrées pour un événement.
	 *
	 * @param string $event_id Identifiant de l'événement.
	 * @return int
	 */
	public static function attempts( string $event_id ): int {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT attempts FROM {$table} WHERE event_id = %s", $event_id )
		);
	}

	/**
	 * Most recent events, pour l'écran de diagnostic.
	 *
	 * @param int $limit Nombre de lignes.
	 * @return array[]
	 */
	public static function recent( int $limit = 20 ): array {
		global $wpdb;

		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, $limit ) ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Supprime les événements traités au-delà de la durée de conservation.
	 *
	 * @param int $days Durée de conservation, en jours.
	 * @return int Nombre de lignes supprimées.
	 */
	public static function purge( int $days = 90 ): int {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
					WHERE status IN ( %s, %s )
					AND created_at < DATE_SUB( %s, INTERVAL %d DAY )",
				self::STATUS_PROCESSED,
				self::STATUS_SKIPPED,
				current_time( 'mysql', true ),
				max( 1, $days )
			)
		);
	}

	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
}
