<?php
/**
 * Tests du journal des événements de webhook.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Webhooks;

use RCP_Stripe_Sepa\Webhook\EventStore;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Webhook\EventStore
 */
final class EventStoreTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		EventStore::install();
	}

	public function test_la_table_est_creee(): void {
		global $wpdb;

		$table = EventStore::table();

		// phpcs:ignore WordPress.DB
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$this->assertSame( $table, $found );
	}

	public function test_l_installation_est_idempotente(): void {
		$before = get_option( EventStore::OPTION_SCHEMA_VERSION );

		EventStore::install();
		EventStore::install();

		$this->assertSame( $before, get_option( EventStore::OPTION_SCHEMA_VERSION ) );
	}

	public function test_une_premiere_reception_est_accordee(): void {
		$this->assertSame(
			EventStore::CLAIM_GRANTED,
			EventStore::claim( 'evt_store_1', 'payment_intent.succeeded', false, '{}' )
		);
		$this->assertSame( 1, EventStore::attempts( 'evt_store_1' ) );
	}

	/**
	 * @group I-1
	 */
	public function test_une_reception_deja_traitee_est_un_doublon(): void {
		EventStore::claim( 'evt_store_2', 'payment_intent.succeeded', false, '{}' );
		EventStore::resolve( 'evt_store_2', EventStore::STATUS_PROCESSED, 'ok' );

		$this->assertSame(
			EventStore::CLAIM_DUPLICATE,
			EventStore::claim( 'evt_store_2', 'payment_intent.succeeded', false, '{}' )
		);
	}

	public function test_un_evenement_ignore_est_aussi_un_doublon_au_rejeu(): void {
		// Un événement délibérément sans effet ne doit pas être retraité.
		EventStore::claim( 'evt_store_3', 'capability.updated', false, '{}' );
		EventStore::resolve( 'evt_store_3', EventStore::STATUS_SKIPPED, 'non géré' );

		$this->assertSame(
			EventStore::CLAIM_DUPLICATE,
			EventStore::claim( 'evt_store_3', 'capability.updated', false, '{}' )
		);
	}

	public function test_une_reception_en_echec_autorise_une_nouvelle_tentative(): void {
		EventStore::claim( 'evt_store_4', 'invoice.paid', false, '{}' );
		EventStore::resolve( 'evt_store_4', EventStore::STATUS_FAILED, 'panne' );

		$this->assertSame(
			EventStore::CLAIM_RETRY,
			EventStore::claim( 'evt_store_4', 'invoice.paid', false, '{}' )
		);
		$this->assertSame( 2, EventStore::attempts( 'evt_store_4' ) );
	}

	public function test_les_tentatives_s_accumulent(): void {
		EventStore::claim( 'evt_store_5', 'invoice.paid', false, '{}' );

		for ( $i = 0; $i < 4; $i++ ) {
			EventStore::claim( 'evt_store_5', 'invoice.paid', false, '{}' );
		}

		$this->assertSame( 5, EventStore::attempts( 'evt_store_5' ) );
	}

	public function test_seuls_les_evenements_traites_et_anciens_sont_purges(): void {
		global $wpdb;

		$table = EventStore::table();

		$rows = array(
			array( 'evt_old_processed', EventStore::STATUS_PROCESSED, '-120 days' ),
			array( 'evt_old_skipped', EventStore::STATUS_SKIPPED, '-120 days' ),
			array( 'evt_old_failed', EventStore::STATUS_FAILED, '-120 days' ),
			array( 'evt_recent_processed', EventStore::STATUS_PROCESSED, '-2 days' ),
		);

		foreach ( $rows as $row ) {
			list( $event_id, $status, $age ) = $row;

			EventStore::claim( $event_id, 'payment_intent.succeeded', false, '{}' );
			EventStore::resolve( $event_id, $status, '' );

			// phpcs:ignore WordPress.DB
			$wpdb->update(
				$table,
				array( 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( $age ) ) ),
				array( 'event_id' => $event_id )
			);
		}

		$deleted = EventStore::purge( 90 );

		$this->assertSame( 2, $deleted, 'Seuls les événements traités et anciens sont purgés.' );
		$this->assertSame( 0, EventStore::attempts( 'evt_old_processed' ) );
		$this->assertSame( 0, EventStore::attempts( 'evt_old_skipped' ) );
		$this->assertSame(
			1,
			EventStore::attempts( 'evt_old_failed' ),
			'Un échec ancien est conservé : il reste à diagnostiquer.'
		);
		$this->assertSame( 1, EventStore::attempts( 'evt_recent_processed' ) );
	}

	public function test_les_derniers_evenements_sont_listes_du_plus_recent_au_plus_ancien(): void {
		foreach ( array( 'evt_r1', 'evt_r2', 'evt_r3' ) as $event_id ) {
			EventStore::claim( $event_id, 'invoice.paid', false, '{}' );
		}

		$recent = EventStore::recent( 2 );

		$this->assertCount( 2, $recent );
		$this->assertSame( 'evt_r3', $recent[0]['event_id'] );
		$this->assertSame( 'evt_r2', $recent[1]['event_id'] );
	}

	public function test_seule_l_empreinte_de_la_charge_utile_est_conservee(): void {
		// La charge utile contient des données personnelles : seule son
		// empreinte est stockée, pour l'audit.
		$payload = '{"iban":"FR1420041010050500013M02606"}';

		EventStore::claim( 'evt_digest', 'payment_intent.succeeded', false, $payload );

		$row = EventStore::recent( 1 )[0];

		$this->assertSame( hash( 'sha256', $payload ), $row['payload_digest'] );
		$this->assertStringNotContainsString( 'FR14', (string) wp_json_encode( $row ) );
	}

	/**
	 * @group SEC-22
	 */
	public function test_le_mode_de_l_evenement_est_conserve(): void {
		EventStore::claim( 'evt_livemode', 'invoice.paid', true, '{}' );

		$this->assertSame( '1', (string) EventStore::recent( 1 )[0]['livemode'] );
	}
}
