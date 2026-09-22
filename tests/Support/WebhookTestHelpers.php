<?php
/**
 * Utilitaires partagés par les tests de webhooks.
 *
 * Placé dans `tests/Support/`, seul répertoire de tests dont la casse
 * corresponde au préfixe PSR-4 : un trait n'est atteignable que par
 * l'autochargement, qui échoue sur un système de fichiers sensible à la casse
 * si le dossier ne s'écrit pas comme l'espace de noms.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Support;

use RCP_Membership;
use RCP_Stripe_Sepa\Webhook\Endpoint;
use RCP_Stripe_Sepa\Webhook\EventStore;
use RCP_Stripe_Sepa\Webhook\RateLimiter;
use RCP_Stripe_Sepa\Webhook\SignatureVerifier;
use RCP_Stripe_Sepa\Webhook\WebhookSecret;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Construit des requêtes de webhook signées et inspecte le journal d'événements.
 */
trait WebhookTestHelpers {

	use RcpFixtures;

	/**
	 * Secret utilisé pour signer les charges utiles de test.
	 *
	 * @var string
	 */
	private $webhook_secret = '';

	/**
	 * Compteur garantissant l'unicité des identifiants d'événement.
	 *
	 * @var int
	 */
	private static $event_sequence = 0;

	/**
	 * Prépare un environnement de webhook complet.
	 *
	 * @return void
	 */
	private function prepare_webhook_environment(): void {
		$this->configure_rcp_stripe();
		$this->redirect_stripe_to_mock();

		$this->webhook_secret = 'whsec_' . str_repeat( 'a1b2c3d4', 6 );

		update_option( WebhookSecret::OPTION_TEST, $this->webhook_secret );

		EventStore::install();

		// La route n'est enregistrée qu'au moment de l'initialisation de l'API.
		do_action( 'rest_api_init' );

		RateLimiter::reset( RateLimiter::identify() );
	}

	/**
	 * Restaure l'environnement entre deux tests.
	 *
	 * @return void
	 */
	private function reset_webhook_environment(): void {
		$this->restore_stripe_api_base();

		RateLimiter::reset( RateLimiter::identify() );

		delete_option( WebhookSecret::OPTION_TEST );
	}

	/**
	 * Fabrique un événement Stripe de test.
	 *
	 * @param string $type            Type d'événement.
	 * @param array  $object_extra    Champs ajoutés à l'objet.
	 * @param array  $event_extra     Champs ajoutés à l'événement.
	 * @param array  $object_override Champs remplaçant ceux de l'objet.
	 * @return array
	 */
	private function event( string $type, array $object_extra = array(), array $event_extra = array(), array $object_override = array() ): array {
		++self::$event_sequence;

		$object = array_merge(
			array(
				'id'                   => 'pi_test_' . self::$event_sequence,
				'object'               => 'payment_intent',
				'amount'               => 1000,
				'currency'             => 'eur',
				'status'               => 'succeeded',
				'payment_method_types' => array( 'sepa_debit' ),
				'metadata'             => array( 'rcp_membership_id' => (string) $this->membership_id() ),
			),
			$object_extra,
			$object_override
		);

		return array_merge(
			array(
				'id'          => 'evt_test_' . self::$event_sequence . '_' . wp_generate_password( 8, false ),
				'object'      => 'event',
				'api_version' => '2020-08-27',
				'created'     => time(),
				'livemode'    => false,
				'type'        => $type,
				'data'        => array( 'object' => $object ),
			),
			$event_extra
		);
	}

	/**
	 * Identifiant d'adhésion utilisé par défaut dans les événements.
	 *
	 * @var int
	 */
	private $default_membership_id = 0;

	/**
	 * Identifiant d'adhésion courant, créé à la demande.
	 *
	 * @return int
	 */
	private function membership_id(): int {
		if ( 0 === $this->default_membership_id ) {
			$this->default_membership_id = $this->create_sepa_membership();
		}

		return $this->default_membership_id;
	}

	/**
	 * Crée une adhésion RCP réglée par prélèvement SEPA.
	 *
	 * @param int    $existing Identifiant à réutiliser, le cas échéant.
	 * @param string $status   Statut initial.
	 * @return int
	 */
	private function create_sepa_membership( int $existing = 0, string $status = 'pending' ): int {
		if ( $existing > 0 ) {
			return $existing;
		}

		$user_id = $this->create_user();

		return $this->create_membership(
			array(
				'user_id'                 => $user_id,
				'status'                  => $status,
				'gateway'                 => 'stripe_sepa',
				'gateway_customer_id'     => 'cus_test_' . $user_id,
				'gateway_subscription_id' => 'sub_test_' . $user_id,
			)
		);
	}

	/**
	 * Calcule un en-tête Stripe-Signature.
	 *
	 * @param string   $payload   Charge utile brute.
	 * @param string   $secret    Secret à utiliser.
	 * @param int|null $timestamp Horodatage de signature.
	 * @return string
	 */
	private function sign( string $payload, string $secret = '', ?int $timestamp = null ): string {
		$secret    = '' !== $secret ? $secret : $this->webhook_secret;
		$timestamp = $timestamp ?? time();

		return sprintf(
			't=%d,v1=%s',
			$timestamp,
			hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret )
		);
	}

	/**
	 * Envoie un événement au point de terminaison.
	 *
	 * @param array $event   Événement à envoyer.
	 * @param array $options `secret`, `age` ou `signature` (null pour l'omettre).
	 * @return WP_REST_Response
	 */
	private function send( array $event, array $options = array() ): WP_REST_Response {
		$payload = (string) wp_json_encode( $event );

		$request = new WP_REST_Request( 'POST', '/' . Endpoint::NAMESPACE . Endpoint::ROUTE );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( $payload );

		$omit_signature = array_key_exists( 'signature', $options ) && null === $options['signature'];

		if ( ! $omit_signature ) {
			$request->set_header(
				SignatureVerifier::HEADER,
				$this->sign(
					$payload,
					(string) ( $options['secret'] ?? '' ),
					time() - (int) ( $options['age'] ?? 0 )
				)
			);
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Nombre de lignes enregistrées pour un événement.
	 *
	 * @param string $event_id Identifiant de l'événement.
	 * @return int
	 */
	private function stored_rows( string $event_id ): int {
		global $wpdb;

		$table = EventStore::table();

		// phpcs:ignore WordPress.DB
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %s", $event_id )
		);
	}

	/**
	 * Statut enregistré pour un événement.
	 *
	 * @param string $event_id Identifiant de l'événement.
	 * @return string
	 */
	private function stored_status( string $event_id ): string {
		global $wpdb;

		$table = EventStore::table();

		// phpcs:ignore WordPress.DB
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$table} WHERE event_id = %s", $event_id )
		);
	}

	/**
	 * Note enregistrée pour un événement.
	 *
	 * @param string $event_id Identifiant de l'événement.
	 * @return string
	 */
	private function stored_note( string $event_id ): string {
		global $wpdb;

		$table = EventStore::table();

		// phpcs:ignore WordPress.DB
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT note FROM {$table} WHERE event_id = %s", $event_id )
		);
	}

	/**
	 * Vérifie qu'aucune trace de l'événement n'a été enregistrée.
	 *
	 * @param string $event_id Identifiant de l'événement.
	 * @return void
	 */
	private function assertNoEventStored( string $event_id ): void {
		$this->assertSame(
			0,
			$this->stored_rows( $event_id ),
			'Une requête rejetée ne doit laisser aucune trace exploitable.'
		);
	}

	/**
	 * Recharge une adhésion depuis la base.
	 *
	 * @param int $membership_id Identifiant d'adhésion.
	 * @return RCP_Membership
	 */
	private function reload_membership( int $membership_id ): RCP_Membership {
		$membership = rcp_get_membership( $membership_id );

		$this->assertInstanceOf(
			RCP_Membership::class,
			$membership,
			sprintf( 'Adhésion #%d introuvable.', $membership_id )
		);

		return $membership;
	}
}
