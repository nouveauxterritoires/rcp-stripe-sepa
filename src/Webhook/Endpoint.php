<?php
/**
 * Point de terminaison REST des webhooks Stripe.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Webhook;

use RCP_Stripe_Sepa\Logging\Redactor;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Reçoit, authentifie et achemine les webhooks Stripe.
 *
 * Le plugin n'emprunte pas l'écouteur `?listener=stripe` de RCP, qui ne vérifie
 * pas la signature des charges utiles. Il expose sa propre route, authentifiée
 * par HMAC.
 *
 * @see docs/cahier-des-charges.md §8.1
 */
final class Endpoint {

	public const NAMESPACE = 'rcp-stripe-sepa/v1';
	public const ROUTE     = '/webhook';

	/**
	 * Enregistre la route.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),

				// L'authentification est assurée par la signature Stripe, pas par
				// une session WordPress : Stripe n'a ni compte ni cookie. Ouvrir la
				// route est délibéré, et sans effet tant que la signature est invalide.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * URL publique du point de terminaison.
	 *
	 * @return string
	 */
	public static function url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	/**
	 * Traite une requête entrante.
	 *
	 * Les réponses ne divulguent aucune information métier : un appelant non
	 * authentifié ne doit rien apprendre de l'état du site.
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$caller = RateLimiter::identify();

		if ( ! RateLimiter::allow( $caller ) ) {
			self::log( sprintf( 'Limite de débit atteinte pour %s.', $caller ), true );

			return self::respond( 429 );
		}

		$payload = (string) $request->get_body();
		$header  = (string) $request->get_header( SignatureVerifier::HEADER );

		try {
			$event = ( new SignatureVerifier( WebhookSecret::get() ) )->verify( $payload, $header );
		} catch ( SignatureException $exception ) {
			self::log( 'Webhook rejeté : ' . $exception->getMessage(), true );

			return self::respond( 400 );
		}

		$event_id   = (string) $event['id'];
		$event_type = (string) $event['type'];
		$livemode   = (bool) ( $event['livemode'] ?? false );

		if ( WebhookSecret::is_test_mode() === $livemode ) {
			/*
			 * Un événement de production reçu par un site en mode test — ou
			 * l'inverse — trahit un point de terminaison mal configuré. Le
			 * traiter activerait des adhésions sur la foi de paiements qui
			 * n'existent pas dans le mode courant.
			 */
			self::log(
				sprintf( 'Webhook %s ignoré : mode incohérent (livemode=%s).', $event_id, $livemode ? 'true' : 'false' ),
				true
			);

			return self::respond( 202 );
		}

		$claim = EventStore::claim( $event_id, $event_type, $livemode, $payload );

		if ( EventStore::CLAIM_DUPLICATE === $claim ) {
			self::log( sprintf( 'Webhook %s déjà traité, ignoré.', $event_id ) );

			return self::respond( 200 );
		}

		if ( EventStore::CLAIM_RETRY === $claim && EventStore::attempts( $event_id ) > EventStore::MAX_ATTEMPTS ) {
			EventStore::resolve( $event_id, EventStore::STATUS_FAILED, 'Nombre maximal de tentatives atteint.' );

			self::log( sprintf( 'Webhook %s abandonné après %d tentatives.', $event_id, EventStore::MAX_ATTEMPTS ), true );

			/**
			 * Se déclenche lorsqu'un événement est abandonné.
			 *
			 * @since 0.1.0
			 *
			 * @param string $event_id   Identifiant de l'événement.
			 * @param string $event_type Type d'événement.
			 */
			do_action( 'rcp_stripe_sepa_webhook_abandoned', $event_id, $event_type );

			return self::respond( 200 );
		}

		try {
			$result = EventProcessor::process( $event );
		} catch ( Throwable $exception ) {
			EventStore::resolve( $event_id, EventStore::STATUS_FAILED, $exception->getMessage() );

			self::log(
				sprintf( 'Webhook %s en erreur : %s', $event_id, $exception->getMessage() ),
				true
			);

			// Un 500 demande à Stripe de rejouer l'événement plus tard.
			return self::respond( 500 );
		}

		EventStore::resolve( $event_id, $result->status(), $result->note() );

		self::log( sprintf( 'Webhook %s (%s) : %s', $event_id, $event_type, $result->note() ) );

		/**
		 * Se déclenche après le traitement d'un événement authentifié.
		 *
		 * @since 0.1.0
		 *
		 * @param array       $event  Événement Stripe décodé.
		 * @param EventResult $result Issue du traitement.
		 */
		do_action( 'rcp_stripe_sepa_webhook_processed', $event, $result );

		return self::respond( 200 );
	}

	/**
	 * Construit une réponse minimale.
	 *
	 * @param int $status Code HTTP.
	 * @return WP_REST_Response
	 */
	private static function respond( int $status ): WP_REST_Response {
		return new WP_REST_Response( array( 'received' => $status < 400 ), $status );
	}

	/**
	 * Journalise un message expurgé.
	 *
	 * @param string $message Message.
	 * @param bool   $error   S'agit-il d'une erreur.
	 * @return void
	 */
	private static function log( string $message, bool $error = false ): void {
		if ( function_exists( 'rcp_log' ) ) {
			rcp_log( 'Webhook SEPA : ' . Redactor::redact( $message ), $error );
		}
	}
}
