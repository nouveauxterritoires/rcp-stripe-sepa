<?php
/**
 * Bascule d'une adhésion vers le prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Migration;

use Exception;
use RCP_Membership;
use RCP_Stripe_Sepa\Gateway\GatewayDefinition;
use RCP_Stripe_Sepa\Gateway\IntentFactory;
use RCP_Stripe_Sepa\Logging\Redactor;
use RCP_Stripe_Sepa\Mandate\MandateData;
use RCP_Stripe_Sepa\Mandate\MandateRepository;
use RCP_Stripe_Sepa\Support\StripeSdk;
use WP_Error;

/**
 * Remplace le moyen de paiement d'une adhésion par un mandat SEPA.
 *
 * L'opération se déroule en deux temps, parce que l'acceptation du mandat a
 * lieu dans le navigateur : le serveur prépare une intention d'enregistrement,
 * puis applique le résultat une fois le mandat signé.
 *
 * Contrairement à un encaissement, un SetupIntent SEPA aboutit immédiatement —
 * aucun fonds ne circule. La bascule est donc synchrone, et l'adhérent en voit
 * l'effet sans délai.
 *
 * Ni le prix ni la date de prochaine échéance ne sont modifiés.
 *
 * @see docs/cahier-des-charges.md §6.3, règle RG-06
 */
final class Migrator {

	/**
	 * Prépare la collecte d'un nouveau mandat.
	 *
	 * @param RCP_Membership $membership Adhésion à migrer.
	 * @return array|WP_Error Secret client et identifiant de l'intention.
	 */
	public static function start( RCP_Membership $membership ) {
		$eligibility = Eligibility::assess( self::snapshot( $membership ) );

		if ( ! $eligibility->is_allowed() ) {
			return new WP_Error( 'rcp_stripe_sepa_not_eligible', ReasonPresenter::message( $eligibility->reason() ) );
		}

		if ( ! StripeSdk::ensure_loaded() ) {
			return new WP_Error( 'rcp_stripe_sepa_sdk_missing', ReasonPresenter::generic_failure() );
		}

		$args = IntentFactory::setup_intent(
			array(
				'customer_id' => $membership->get_gateway_customer_id(),
				'description' => $membership->get_membership_level_name(),
				'metadata'    => array(
					'rcp_membership_id' => (string) $membership->get_id(),
					'rcp_sepa_purpose'  => 'migration',
				),
			)
		);

		try {
			$intent = \Stripe\SetupIntent::create(
				$args,
				StripeSdk::request_options(
					array( 'idempotency_key' => rcp_stripe_generate_idempotency_key( $args ) )
				)
			);
		} catch ( Exception $exception ) {
			self::log( 'Préparation de la migration impossible : ' . $exception->getMessage(), true );

			return new WP_Error( 'rcp_stripe_sepa_setup_failed', ReasonPresenter::generic_failure() );
		}

		return array(
			'client_secret'   => (string) $intent->client_secret,
			'setup_intent_id' => (string) $intent->id,
		);
	}

	/**
	 * Applique le mandat accepté à l'adhésion et à son abonnement Stripe.
	 *
	 * @param RCP_Membership $membership      Adhésion à migrer.
	 * @param string         $setup_intent_id Intention confirmée côté navigateur.
	 * @return true|WP_Error
	 */
	public static function complete( RCP_Membership $membership, string $setup_intent_id ) {
		if ( ! StripeSdk::ensure_loaded() ) {
			return new WP_Error( 'rcp_stripe_sepa_sdk_missing', ReasonPresenter::generic_failure() );
		}

		try {
			$intent = \Stripe\SetupIntent::retrieve( $setup_intent_id, StripeSdk::request_options() );
		} catch ( Exception $exception ) {
			self::log( 'Intention de migration illisible : ' . $exception->getMessage(), true );

			return new WP_Error( 'rcp_stripe_sepa_intent_unreadable', ReasonPresenter::generic_failure() );
		}

		$guard = self::guard( $membership, $intent );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		try {
			$payment_method = \Stripe\PaymentMethod::retrieve(
				(string) $intent->payment_method,
				StripeSdk::request_options()
			);
		} catch ( Exception $exception ) {
			self::log( 'Moyen de paiement illisible : ' . $exception->getMessage(), true );

			return new WP_Error( 'rcp_stripe_sepa_payment_method_unreadable', ReasonPresenter::generic_failure() );
		}

		return self::apply( $membership, $intent, $payment_method );
	}

	/**
	 * Applique à l'adhésion un mandat déjà relu.
	 *
	 * Séparer la lecture de l'application permet d'éprouver la bascule sur des
	 * objets construits, sans dépendre de ce qu'un serveur de test veut bien
	 * renvoyer. `complete()` reste le point d'entrée du parcours réel.
	 *
	 * @param RCP_Membership $membership     Adhésion à migrer.
	 * @param object         $intent         Intention d'enregistrement Stripe.
	 * @param object         $payment_method Moyen de paiement issu de l'intention.
	 * @return true|WP_Error
	 */
	public static function apply( RCP_Membership $membership, $intent, $payment_method ) {
		$guard = self::guard( $membership, $intent );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		if ( MandateData::PAYMENT_METHOD_TYPE !== (string) $payment_method->type ) {
			return new WP_Error( 'rcp_stripe_sepa_wrong_type', ReasonPresenter::message( Eligibility::REASON_UNSUPPORTED_GATEWAY ) );
		}

		$updated = self::apply_to_subscription( $membership, (string) $payment_method->id );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		self::store_mandate( $membership, $payment_method, $intent );

		self::switch_gateway( $membership );

		/**
		 * Se déclenche après la bascule d'une adhésion vers le prélèvement SEPA.
		 *
		 * @since 0.1.0
		 *
		 * @param RCP_Membership $membership     Adhésion migrée.
		 * @param string         $payment_method Identifiant du mandat.
		 */
		do_action( 'rcp_stripe_sepa_migrated', $membership, (string) $payment_method->id );

		return true;
	}

	// -- Contrôles ---------------------------------------------------------------

	/**
	 * Vérifie que l'intention appartient bien à cette adhésion.
	 *
	 * Sans ce contrôle, un adhérent pourrait transmettre l'identifiant d'une
	 * intention confirmée par quelqu'un d'autre et rattacher le mandat d'un
	 * tiers à sa propre adhésion.
	 *
	 * @param RCP_Membership $membership Adhésion visée.
	 * @param object         $intent     Intention Stripe.
	 * @return true|WP_Error
	 */
	private static function guard( RCP_Membership $membership, $intent ) {
		/*
		 * Le rattachement est contrôlé en premier : un appelant qui ne possède
		 * pas l'adhésion n'a pas à apprendre l'état d'une intention.
		 */
		$customer = is_string( $intent->customer ) ? $intent->customer : (string) ( $intent->customer->id ?? '' );

		if ( '' === $customer || $customer !== (string) $membership->get_gateway_customer_id() ) {
			self::log(
				sprintf(
					'Intention %s rejetée : client %s étranger à l\'adhésion #%d.',
					(string) $intent->id,
					$customer,
					$membership->get_id()
				),
				true
			);

			return new WP_Error( 'rcp_stripe_sepa_foreign_intent', ReasonPresenter::generic_failure() );
		}

		if ( 'succeeded' !== (string) $intent->status ) {
			return new WP_Error( 'rcp_stripe_sepa_intent_not_confirmed', ReasonPresenter::not_confirmed() );
		}

		if ( empty( $intent->payment_method ) ) {
			return new WP_Error( 'rcp_stripe_sepa_no_payment_method', ReasonPresenter::generic_failure() );
		}

		return true;
	}

	// -- Application ---------------------------------------------------------------

	/**
	 * Bascule l'abonnement Stripe sur le nouveau mandat.
	 *
	 * @param RCP_Membership $membership     Adhésion visée.
	 * @param string         $payment_method Identifiant du mandat.
	 * @return true|WP_Error
	 */
	private static function apply_to_subscription( RCP_Membership $membership, string $payment_method ) {
		$subscription_id = (string) $membership->get_gateway_subscription_id();

		if ( '' === $subscription_id ) {
			// Pas d'abonnement : le mandat servira au prochain renouvellement
			// créé par RCP.
			return true;
		}

		try {
			\Stripe\Subscription::update(
				$subscription_id,
				array(
					'default_payment_method' => $payment_method,
					'payment_settings'       => array(
						'payment_method_types' => array( IntentFactory::PAYMENT_METHOD_TYPE ),
					),
					// Aucune proratisation : la migration ne touche ni au prix
					// ni à la date de prochaine échéance.
					'proration_behavior'     => 'none',
				),
				StripeSdk::request_options()
			);
		} catch ( Exception $exception ) {
			self::log( 'Bascule de l\'abonnement impossible : ' . $exception->getMessage(), true );

			return new WP_Error( 'rcp_stripe_sepa_subscription_update_failed', ReasonPresenter::generic_failure() );
		}

		return true;
	}

	/**
	 * Persiste le nouveau mandat.
	 *
	 * @param RCP_Membership $membership     Adhésion visée.
	 * @param object         $payment_method Moyen de paiement Stripe.
	 * @param object         $intent         Intention Stripe.
	 * @return void
	 */
	private static function store_mandate( RCP_Membership $membership, $payment_method, $intent ): void {
		$mandate = array();

		if ( ! empty( $intent->mandate ) ) {
			try {
				$mandate = \Stripe\Mandate::retrieve(
					(string) $intent->mandate,
					StripeSdk::request_options()
				)->toArray();
			} catch ( Exception $exception ) {
				self::log( 'Mandat Stripe illisible : ' . $exception->getMessage(), true );
			}
		}

		$data = MandateData::from_stripe( $payment_method->toArray(), $mandate );

		if ( array() === $data ) {
			return;
		}

		MandateRepository::save(
			(int) $membership->get_id(),
			MandateData::with_acceptance( $data, self::client_ip(), current_time( 'mysql', true ) )
		);
	}

	/**
	 * Rattache l'adhésion à la passerelle SEPA.
	 *
	 * @param RCP_Membership $membership Adhésion visée.
	 * @return void
	 */
	private static function switch_gateway( RCP_Membership $membership ): void {
		$previous = (string) $membership->get_gateway();

		if ( GatewayDefinition::ID !== $previous ) {
			$membership->update( array( 'gateway' => GatewayDefinition::ID ) );
		}

		$membership->add_note(
			sprintf(
				/* translators: %s: identifiant de la passerelle précédente. */
				__( 'Prélèvement SEPA — moyen de paiement migré depuis « %s ». Prix et date de prochaine échéance inchangés.', 'rcp-stripe-sepa' ),
				$previous
			)
		);
	}

	// -- Utilitaires -----------------------------------------------------------------

	/**
	 * Instantané de l'adhésion, pour l'évaluation d'éligibilité.
	 *
	 * @param RCP_Membership $membership Adhésion visée.
	 * @return array
	 */
	public static function snapshot( RCP_Membership $membership ): array {
		return array(
			'status'                  => (string) $membership->get_status(),
			'gateway'                 => (string) $membership->get_gateway(),
			'gateway_customer_id'     => (string) $membership->get_gateway_customer_id(),
			'gateway_subscription_id' => (string) $membership->get_gateway_subscription_id(),
			'auto_renew'              => (bool) $membership->is_recurring(),
			'currency'                => (string) rcp_get_currency(),
		);
	}

	/**
	 * Adresse de l'auteur de l'acceptation du mandat.
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		return rest_is_ip_address( $address ) ? $address : '';
	}

	/**
	 * Journalise un message expurgé.
	 *
	 * @param string $message Message.
	 * @param bool   $error   S'agit-il d'une erreur.
	 * @return void
	 */
	private static function log( string $message, bool $error = false ): void {
		rcp_log( 'Migration SEPA : ' . Redactor::redact( $message ), $error );
	}
}
