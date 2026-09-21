<?php
/**
 * Tests du point de terminaison des webhooks.
 *
 * Couvre le transport : authentification, cloisonnement des modes,
 * idempotence, limitation de débit et codes de réponse.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Webhooks;

use RCP_Stripe_Sepa\Webhook\Endpoint;
use RCP_Stripe_Sepa\Webhook\EventStore;
use RCP_Stripe_Sepa\Webhook\RateLimiter;
use RCP_Stripe_Sepa\Webhook\SignatureVerifier;
use RCP_Stripe_Sepa\Webhook\WebhookSecret;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Webhook\Endpoint
 * @covers \RCP_Stripe_Sepa\Webhook\EventStore
 * @covers \RCP_Stripe_Sepa\Webhook\SignatureVerifier
 * @covers \RCP_Stripe_Sepa\Webhook\RateLimiter
 */
final class WebhookEndpointTest extends WP_UnitTestCase {

	use WebhookTestHelpers;

	public function set_up(): void {
		parent::set_up();

		$this->prepare_webhook_environment();
	}

	public function tear_down(): void {
		$this->reset_webhook_environment();

		parent::tear_down();
	}

	// -- Authentification -------------------------------------------------------

	public function test_une_charge_utile_signee_est_acceptee(): void {
		$response = $this->send( $this->event( 'payment_intent.succeeded' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_une_requete_sans_signature_est_rejetee(): void {
		// SEC-06.
		$event    = $this->event( 'payment_intent.succeeded' );
		$response = $this->send( $event, array( 'signature' => null ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNoEventStored( $event['id'] );
	}

	public function test_une_signature_calculee_avec_un_mauvais_secret_est_rejetee(): void {
		// SEC-06.
		$event    = $this->event( 'payment_intent.succeeded' );
		$response = $this->send( $event, array( 'secret' => 'whsec_' . str_repeat( '0', 48 ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNoEventStored( $event['id'] );
	}

	public function test_une_charge_utile_alteree_apres_signature_est_rejetee(): void {
		$event   = $this->event( 'payment_intent.succeeded' );
		$payload = (string) wp_json_encode( $event );
		$header  = $this->sign( $payload );

		$altered = str_replace( '"livemode":false', '"livemode":true', $payload );

		$request = new WP_REST_Request( 'POST', '/' . Endpoint::NAMESPACE . Endpoint::ROUTE );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( SignatureVerifier::HEADER, $header );
		$request->set_body( $altered );

		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_une_signature_antidatee_est_rejetee(): void {
		// SEC-07 : tolérance de 300 secondes.
		$event    = $this->event( 'payment_intent.succeeded' );
		$response = $this->send( $event, array( 'age' => SignatureVerifier::TOLERANCE + 60 ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNoEventStored( $event['id'] );
	}

	public function test_une_signature_recente_est_acceptee_dans_la_tolerance(): void {
		$response = $this->send( $this->event( 'payment_intent.succeeded' ), array( 'age' => 60 ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_sans_secret_configure_toute_requete_est_rejetee(): void {
		delete_option( WebhookSecret::OPTION_TEST );

		$response = $this->send( $this->event( 'payment_intent.succeeded' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_la_reponse_ne_divulgue_aucune_information(): void {
		// SEC-10.
		$response = $this->send( $this->event( 'payment_intent.succeeded' ), array( 'signature' => null ) );

		$body = (string) wp_json_encode( $response->get_data() );

		$this->assertStringNotContainsString( 'secret', $body );
		$this->assertStringNotContainsString( 'signature', strtolower( $body ) );
		$this->assertLessThan( 120, strlen( $body ), 'La réponse doit rester minimale.' );
	}

	// -- Cloisonnement des modes ------------------------------------------------

	public function test_un_evenement_de_production_est_ignore_en_mode_test(): void {
		// SEC-09 / I-4 : un webhook de production ne doit rien activer ici.
		$event = $this->event( 'payment_intent.succeeded', array(), array( 'livemode' => true ) );

		$response = $this->send( $event );

		$this->assertSame( 202, $response->get_status() );
		$this->assertNoEventStored( $event['id'] );
	}

	// -- Idempotence -------------------------------------------------------------

	public function test_un_evenement_rejoue_n_est_traite_qu_une_fois(): void {
		// I-1 : cinq livraisons du même événement, un seul traitement.
		$event     = $this->event( 'payment_intent.succeeded' );
		$processed = 0;

		add_action(
			'rcp_stripe_sepa_webhook_processed',
			static function () use ( &$processed ) {
				++$processed;
			}
		);

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( 200, $this->send( $event )->get_status(), 'Livraison ' . ( $i + 1 ) );
		}

		$this->assertSame( 1, $processed, 'Le traitement doit être unique.' );
		$this->assertSame( 1, $this->stored_rows( $event['id'] ), 'Une seule ligne par événement.' );
	}

	public function test_un_evenement_distinct_est_traite_a_son_tour(): void {
		$this->send( $this->event( 'payment_intent.succeeded' ) );
		$second = $this->event( 'payment_intent.processing' );

		$this->assertSame( 200, $this->send( $second )->get_status() );
		$this->assertSame( 1, $this->stored_rows( $second['id'] ) );
	}

	public function test_un_evenement_en_erreur_repetee_est_abandonne(): void {
		// I-5 : au-delà du nombre maximal de tentatives, l'événement est
		// abandonné plutôt que rejoué indéfiniment.
		$event = $this->event( 'payment_intent.succeeded' );

		add_action(
			'rcp_stripe_sepa_transition_applied',
			static function () {
				throw new \RuntimeException( 'Panne simulée.' );
			}
		);

		$abandoned = false;

		add_action(
			'rcp_stripe_sepa_webhook_abandoned',
			static function () use ( &$abandoned ) {
				$abandoned = true;
			}
		);

		for ( $i = 0; $i < EventStore::MAX_ATTEMPTS + 1; $i++ ) {
			$this->send( $event );
		}

		$this->assertTrue( $abandoned, 'L\'événement aurait dû être abandonné.' );
		$this->assertSame( EventStore::STATUS_FAILED, $this->stored_status( $event['id'] ) );
	}

	// -- Événements non gérés ----------------------------------------------------

	public function test_un_evenement_non_gere_est_acquitte(): void {
		// Répondre en erreur ferait rejouer l'événement indéfiniment.
		$event    = $this->event( 'capability.updated' );
		$response = $this->send( $event );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( EventStore::STATUS_SKIPPED, $this->stored_status( $event['id'] ) );
	}

	public function test_un_evenement_sans_adhesion_correspondante_est_acquitte(): void {
		$event = $this->event( 'payment_intent.succeeded', array(), array(), array( 'metadata' => array() ) );

		$this->assertSame( 200, $this->send( $event )->get_status() );
		$this->assertSame( EventStore::STATUS_SKIPPED, $this->stored_status( $event['id'] ) );
	}

	// -- Limitation de débit ------------------------------------------------------

	public function test_la_limitation_de_debit_protege_le_point_de_terminaison(): void {
		// SEC-11.
		add_filter( 'rcp_stripe_sepa_webhook_rate_limit', static fn() => 3 );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertNotSame( 429, $this->send( $this->event( 'capability.updated' ) )->get_status() );
		}

		$this->assertSame( 429, $this->send( $this->event( 'capability.updated' ) )->get_status() );
	}

	public function test_la_limitation_de_debit_peut_etre_desactivee(): void {
		add_filter( 'rcp_stripe_sepa_webhook_rate_limit', static fn() => 0 );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertNotSame( 429, $this->send( $this->event( 'capability.updated' ) )->get_status() );
		}
	}

	// -- Route ---------------------------------------------------------------------

	public function test_la_route_est_enregistree(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/' . Endpoint::NAMESPACE . Endpoint::ROUTE, $routes );
	}

	public function test_la_route_refuse_les_requetes_get(): void {
		$request  = new WP_REST_Request( 'GET', '/' . Endpoint::NAMESPACE . Endpoint::ROUTE );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_le_point_de_terminaison_n_est_pas_celui_de_rcp(): void {
		// SEC-08 : RCP ne vérifie pas la signature ; sa route n'est pas utilisée.
		$this->assertStringContainsString( 'rcp-stripe-sepa/v1/webhook', Endpoint::url() );
		$this->assertStringNotContainsString( 'listener=stripe', Endpoint::url() );
	}
}
