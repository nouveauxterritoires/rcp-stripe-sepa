<?php
/**
 * Finalisation d'une inscription payée par prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Gateway;

use DateTime;
use DateTimeZone;
use Exception;
use RCP_Stripe_Sepa\Mandate\MandateData;
use RCP_Stripe_Sepa\Mandate\MandateRepository;
use RCP_Stripe_Sepa\Membership\StateMachine;
use RCP_Stripe_Sepa\Support\StripeSdk;

/**
 * Enchaîne ce qui suit l'acceptation du mandat par le débiteur.
 *
 * La passerelle carte de RCP ne peut pas être réutilisée telle quelle pour deux
 * raisons :
 *
 *  1. sa déduplication des moyens de paiement lit `card->fingerprint`, propriété
 *     inexistante sur un moyen SEPA, et émet des avertissements PHP ;
 *  2. elle conclut à un paiement abouti dès que la charge est `succeeded`, ce
 *     qui n'arrive jamais à l'inscription avec SEPA.
 *
 * L'adhésion reste donc `pending` : son activation est décidée par le webhook
 * d'encaissement, deux à quatorze jours plus tard.
 *
 * @see docs/cahier-des-charges.md §6.1
 */
final class SignupProcessor {

	/**
	 * Gateway appelante.
	 *
	 * @var Gateway
	 */
	private $gateway;

	/**
	 * Construit le processeur pour une passerelle donnée.
	 *
	 * @param Gateway $gateway Gateway appelante.
	 */
	public function __construct( Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Finalise l'inscription.
	 *
	 * @param object $intent     Intention Stripe confirmée côté navigateur.
	 * @param object $membership Adhésion RCP.
	 * @param object $payment    Enregistrement de paiement RCP.
	 * @return void
	 *
	 * @throws Exception Si Stripe refuse une opération déterminante.
	 */
	public function run( $intent, $membership, $payment ): void {
		$customer = $this->gateway->stripe_customer( (string) $intent->customer );

		$payment_method = $this->attach_payment_method( $intent, $customer );

		if ( null !== $payment_method ) {
			$this->store_mandate( (int) $membership->get_id(), $payment_method );
		}

		$this->record_pending_payment( $intent, $payment );

		$this->cancel_previous_subscription( $membership );

		if ( $this->gateway->is_recurring() && null !== $payment_method ) {
			$this->create_subscription( $customer, $payment_method, $membership );
		}
	}

	// -- Moyen de paiement et mandat ------------------------------------------------

	/**
	 * Rattache le moyen de paiement au client et en fait son moyen par défaut.
	 *
	 * La déduplication pratiquée par la passerelle carte n'est pas reprise :
	 * elle ne s'applique qu'aux cartes, et deux mandats portant le même IBAN
	 * restent deux autorisations distinctes qu'il serait abusif de fusionner.
	 *
	 * @param object $intent   Intention Stripe.
	 * @param object $customer Stripe customer.
	 * @return object|null
	 */
	private function attach_payment_method( $intent, $customer ) {
		if ( empty( $intent->payment_method ) ) {
			$this->gateway->log( 'Aucun moyen de paiement sur l\'intention : mandat non enregistré.', true );

			return null;
		}

		try {
			$payment_method = \Stripe\PaymentMethod::retrieve(
				(string) $intent->payment_method,
				StripeSdk::request_options()
			);

			if ( empty( $payment_method->customer ) ) {
				$payment_method->attach( array( 'customer' => $customer->id ) );
			}

			\Stripe\Customer::update(
				$customer->id,
				array( 'invoice_settings' => array( 'default_payment_method' => $payment_method->id ) ),
				StripeSdk::request_options()
			);

			return $payment_method;
		} catch ( Exception $exception ) {
			$this->gateway->log( 'Rattachement du mandat impossible : ' . $exception->getMessage(), true );

			return null;
		}
	}

	/**
	 * Persiste le mandat et la preuve du consentement.
	 *
	 * @param int    $membership_id  Identifiant d'adhésion.
	 * @param object $payment_method Moyen de paiement Stripe.
	 * @return void
	 */
	private function store_mandate( int $membership_id, $payment_method ): void {
		$mandate = array();

		if ( ! empty( $payment_method->sepa_debit->mandate ) ) {
			try {
				$mandate = \Stripe\Mandate::retrieve(
					(string) $payment_method->sepa_debit->mandate,
					StripeSdk::request_options()
				)->toArray();
			} catch ( Exception $exception ) {
				$this->gateway->log( 'Mandat Stripe illisible : ' . $exception->getMessage(), true );
			}
		}

		$data = MandateData::from_stripe( $payment_method->toArray(), $mandate );

		if ( array() === $data ) {
			return;
		}

		MandateRepository::save(
			$membership_id,
			MandateData::with_acceptance( $data, $this->client_ip(), current_time( 'mysql', true ) )
		);
	}

	/**
	 * Adresse de l'auteur de l'acceptation du mandat.
	 *
	 * @return string
	 */
	private function client_ip(): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		return rest_is_ip_address( $address ) ? $address : '';
	}

	// -- Paiement -------------------------------------------------------------------

	/**
	 * Enregistre le paiement comme engagé, mais non encaissé.
	 *
	 * @param object $intent  Intention Stripe.
	 * @param object $payment Enregistrement de paiement RCP.
	 * @return void
	 */
	private function record_pending_payment( $intent, $payment ): void {
		global $rcp_payments_db;

		$data = array(
			'payment_type' => __( 'SEPA Direct Debit', 'rcp-stripe-sepa' ),
			'status'       => StateMachine::PAYMENT_PENDING,
		);

		$charge = ChargeReference::from_intent( $intent->toArray() );

		if ( '' !== $charge ) {
			$data['transaction_id'] = $charge;
		}

		$rcp_payments_db->update( $payment->id, $data );

		$this->gateway->log(
			sprintf( 'Paiement #%d en attente d\'encaissement (intention %s).', (int) $payment->id, (string) $intent->id )
		);
	}

	// -- Abonnement -------------------------------------------------------------------

	/**
	 * Résilie l'abonnement Stripe précédemment lié à l'adhésion.
	 *
	 * Un renouvellement manuel alors qu'un abonnement subsiste produirait deux
	 * prélèvements.
	 *
	 * @param object $membership Adhésion RCP.
	 * @return void
	 */
	private function cancel_previous_subscription( $membership ): void {
		$subscription_id = (string) $membership->get_gateway_subscription_id();

		if ( '' === $subscription_id || 0 !== strpos( $subscription_id, 'sub_' ) ) {
			return;
		}

		$membership->update( array( 'gateway_subscription_id' => '' ) );

		try {
			\Stripe\Subscription::retrieve( $subscription_id, StripeSdk::request_options() )->cancel();
		} catch ( Exception $exception ) {
			$this->gateway->log(
				sprintf( 'Résiliation de l\'abonnement %s impossible : %s', $subscription_id, $exception->getMessage() ),
				true
			);
		}
	}

	/**
	 * Crée l'abonnement Stripe, avec une première échéance différée.
	 *
	 * Le premier versement a déjà été engagé comme paiement ponctuel :
	 * l'abonnement démarre à l'expiration de la période ainsi réglée. C'est ce
	 * décalage qui évite qu'un abonnement SEPA naisse en
	 * `requires_confirmation`, faute de mandat confirmé pour sa facture
	 * initiale.
	 *
	 * @param object $customer       Stripe customer.
	 * @param object $payment_method Moyen de paiement Stripe.
	 * @param object $membership     Adhésion RCP.
	 * @return void
	 *
	 * @throws Exception Si le plan Stripe ne peut pas être fabriqué.
	 */
	private function create_subscription( $customer, $payment_method, $membership ): void {
		try {
			$plan_id = $this->gateway->maybe_create_plan(
				array(
					'name'           => $this->gateway->subscription_name(),
					'price'          => $this->gateway->recurring_amount(),
					'interval'       => $this->gateway->interval_unit(),
					'interval_count' => $this->gateway->interval_count(),
				)
			);

			if ( is_wp_error( $plan_id ) ) {
				throw new Exception( $plan_id->get_error_message() );
			}

			$start_date = $this->subscription_start_date( $membership );

			$args = SubscriptionArgs::for_sepa(
				array(
					'customer'               => $customer->id,
					'default_payment_method' => $payment_method->id,
					/*
					 * Le paramètre `plan`, encore employé par la passerelle
					 * carte de RCP, est déprécié : il n'est accepté que par les
					 * versions d'API antérieures à celle que le plugin
					 * transmet. La forme `items[].price` est celle attendue
					 * aujourd'hui, et un identifiant de plan y est valide, les
					 * plans et les tarifs partageant leur espace de noms.
					 */
					'items'                  => array( array( 'price' => $plan_id ) ),
					'proration_behavior'     => 'none',
					'metadata'               => array(
						'rcp_membership_id'         => (string) $membership->get_id(),
						'rcp_subscription_level_id' => (string) $membership->get_object_id(),
					),
				)
			);

			$args = SubscriptionSchedule::apply(
				$args,
				$start_date,
				$this->gateway->get_stripe_max_billing_cycle_anchor(
					$this->gateway->interval_count(),
					$this->gateway->interval_unit(),
					'now'
				)->getTimestamp(),
				(bool) $this->gateway->is_trial()
			);

			/**
			 * Filtre les arguments d'abonnement Stripe.
			 *
			 * Ce filtre appartient à Restrict Content Pro : il est appliqué ici
			 * pour que les extensions existantes voient aussi les abonnements
			 * SEPA.
			 *
			 * @since 0.1.0
			 *
			 * @param array   $args    Arguments d'abonnement.
			 * @param Gateway $gateway Gateway à l'origine de l'appel.
			 */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$args = apply_filters( 'rcp_stripe_create_subscription_args', $args, $this->gateway );

			$subscription = \Stripe\Subscription::create(
				$args,
				StripeSdk::request_options(
					array( 'idempotency_key' => rcp_stripe_generate_idempotency_key( $args ) )
				)
			);

			$membership->set_gateway_subscription_id( $subscription->id );

			$this->gateway->log( sprintf( 'Abonnement SEPA %s créé.', (string) $subscription->id ) );
		} catch ( Exception $exception ) {
			$this->gateway->log( 'Création de l\'abonnement impossible : ' . $exception->getMessage(), true );

			$membership->add_note(
				sprintf(
					/* translators: %s: error message returned by Stripe. */
					__( 'SEPA Direct Debit — the subscription could not be created: %s', 'rcp-stripe-sepa' ),
					$exception->getMessage()
				)
			);
			$membership->set_recurring( false );
		}
	}

	/**
	 * Date de première échéance de l'abonnement.
	 *
	 * @param object $membership Adhésion RCP.
	 * @return int
	 */
	private function subscription_start_date( $membership ): int {
		$timezone = get_option( 'timezone_string' );
		$timezone = ! empty( $timezone ) ? $timezone : 'UTC';

		$base = new DateTime( (string) $membership->get_expiration_date( false ), new DateTimeZone( $timezone ) );
		$now  = getdate();

		$base->setTime( $now['hours'], $now['minutes'], $now['seconds'] );

		// Une heure de marge, les horloges des serveurs n'étant pas toujours
		// exactes.
		return $base->getTimestamp() - HOUR_IN_SECONDS;
	}
}
