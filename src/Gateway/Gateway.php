<?php
/**
 * Gateway de paiement par prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Gateway;

use Exception;
use RCP_Payment_Gateway_Stripe;
use RCP_Stripe_Sepa\Logging\Redactor;
use RCP_Stripe_Sepa\Support\StripeSdk;
use WP_Error;

/**
 * Gateway `stripe_sepa`, dérivée de la passerelle Stripe de RCP.
 *
 * L'héritage permet de réutiliser la gestion des clés, la création des clients
 * Stripe, la fabrication des plans, l'idempotence et le journal. Seuls les
 * points réellement spécifiques au prélèvement SEPA sont surchargés :
 * construction des intentions, formulaire, script de confirmation et
 * activation différée.
 *
 * @see docs/adr/0001-strategie-integration-rcp.md
 */
class Gateway extends RCP_Payment_Gateway_Stripe {

	/**
	 * Identifiant de la passerelle dans RCP.
	 *
	 * Repris de `GatewayDefinition`, qui porte la description utilisable sans
	 * que RCP soit chargé.
	 */
	public const GATEWAY_ID = GatewayDefinition::ID;

	/*
	 * Restrict Content Pro affecte ces trois propriétés sans les déclarer. PHP
	 * les crée alors dynamiquement, ce que la version 8.2 déprécie : sur un
	 * site où les dépréciations sont remontées, chaque inscription produirait
	 * un avertissement. Les déclarer ici corrige le défaut pour cette
	 * passerelle, sans modifier RCP.
	 */

	/**
	 * Point d'entrée de l'API, renseigné par RCP.
	 *
	 * @var string
	 */
	public $api_endpoint = '';

	/**
	 * Clé d'API, renseignée par RCP.
	 *
	 * @var string
	 */
	public $api_key = '';

	/**
	 * Code de réduction appliqué à l'inscription, renseigné par RCP.
	 *
	 * @var string
	 */
	public $discount_code = '';

	/**
	 * Prépare la passerelle.
	 *
	 * @return void
	 */
	public function init() {
		parent::init();

		/*
		 * Les capacités sont remplacées, et non complétées : `card-updates`,
		 * déclarée par la passerelle carte, afficherait à l'adhérent un
		 * formulaire de mise à jour de carte qui n'a pas de sens pour un
		 * mandat.
		 */
		$this->supports = GatewayDefinition::supports();
	}

	/**
	 * Crée l'intention Stripe et renvoie de quoi la confirmer côté navigateur.
	 *
	 * @return array|WP_Error
	 */
	public function process_ajax_signup() {
		global $rcp_payments_db;

		$currency_error = $this->currency_error();

		if ( null !== $currency_error ) {
			return $currency_error;
		}

		$stripe_customer = $this->get_or_create_customer(
			$this->membership->get_customer_id(),
			$this->membership->get_user_id()
		);

		if ( is_wp_error( $stripe_customer ) ) {
			return new WP_Error(
				$stripe_customer->get_error_code(),
				sprintf(
					/* translators: %s: error message returned by Stripe. */
					__( 'Error while creating the Stripe customer: %s', 'rcp-stripe-sepa' ),
					$stripe_customer->get_error_message()
				)
			);
		}

		$this->membership->set_gateway_customer_id( sanitize_text_field( $stripe_customer->id ) );

		$context = $this->intent_context( (string) $stripe_customer->id );

		try {
			$intent = $this->create_intent( $context );
		} catch ( Exception $exception ) {
			$this->log( 'Création de l\'intention SEPA impossible : ' . $exception->getMessage(), true );

			return new WP_Error( 'rcp_stripe_sepa_intent_failed', $exception->getMessage() );
		}

		$rcp_payments_db->update_meta(
			$this->payment->id,
			'stripe_payment_intent_id',
			sanitize_text_field( $intent->id )
		);

		return array(
			'stripe_client_secret' => sanitize_text_field( $intent->client_secret ),
			'stripe_intent_type'   => sanitize_text_field( $intent->object ),
		);
	}

	/**
	 * Finalise l'inscription, une fois le mandat accepté côté navigateur.
	 *
	 * @return void
	 */
	public function process_signup() {
		global $rcp_payments_db;

		$intent_id = (string) $rcp_payments_db->get_meta( $this->payment->id, 'stripe_payment_intent_id', true );

		if ( '' === $intent_id ) {
			$this->handle_processing_error(
				new WP_Error(
					'rcp_stripe_sepa_missing_intent',
					__( 'Stripe intent not found. Please start the registration again, or contact support.', 'rcp-stripe-sepa' )
				)
			);

			return;
		}

		try {
			$intent = $this->retrieve_intent( $intent_id );

			( new SignupProcessor( $this ) )->run( $intent, $this->membership, $this->payment );
		} catch ( Exception $exception ) {
			$this->handle_processing_error( $exception );

			return;
		}

		/**
		 * Se déclenche après une inscription par prélèvement SEPA.
		 *
		 * L'adhésion est encore en attente à cet instant : son activation
		 * dépend de l'encaissement, confirmé par webhook plusieurs jours plus
		 * tard.
		 *
		 * @since 0.1.0
		 *
		 * @param int     $user_id Identifiant de l'utilisateur.
		 * @param Gateway $gateway Gateway.
		 */
		do_action( 'rcp_stripe_sepa_signup', $this->user_id, $this );

		wp_safe_redirect( $this->return_url );
		exit;
	}

	/**
	 * Relit l'intention créée à l'étape AJAX.
	 *
	 * @param string $intent_id Identifiant de l'intention.
	 * @return \Stripe\PaymentIntent|\Stripe\SetupIntent
	 *
	 * @throws Exception Si l'identifiant n'est pas reconnu.
	 */
	protected function retrieve_intent( string $intent_id ) {
		$options = StripeSdk::request_options();

		if ( 0 === strpos( $intent_id, 'pi_' ) ) {
			return \Stripe\PaymentIntent::retrieve( $intent_id, $options );
		}

		if ( 0 === strpos( $intent_id, 'seti_' ) ) {
			return \Stripe\SetupIntent::retrieve( $intent_id, $options );
		}

		throw new Exception( esc_html( 'Identifiant d\'intention Stripe inattendu : ' . $intent_id ) );
	}

	// -- Accès aux données de l'inscription ---------------------------------------

	/**
	 * Stripe customer associé à l'adhésion.
	 *
	 * @param string $customer_id Identifiant connu, le cas échéant.
	 * @return \Stripe\Customer|\WP_Error
	 */
	public function stripe_customer( string $customer_id = '' ) {
		return $this->get_or_create_customer(
			$this->membership->get_customer_id(),
			$this->user_id,
			$customer_id
		);
	}

	/**
	 * L'adhésion se renouvelle-t-elle automatiquement ?
	 *
	 * @return bool
	 */
	public function is_recurring(): bool {
		return (bool) $this->auto_renew;
	}

	/**
	 * Nom du niveau d'adhésion.
	 *
	 * @return string
	 */
	public function subscription_name(): string {
		return (string) $this->subscription_name;
	}

	/**
	 * Montant des échéances suivantes.
	 *
	 * @return float
	 */
	public function recurring_amount(): float {
		return (float) $this->amount;
	}

	/**
	 * Unité de la période de facturation.
	 *
	 * @return string
	 */
	public function interval_unit(): string {
		return (string) $this->length_unit;
	}

	/**
	 * Nombre d'unités de la période de facturation.
	 *
	 * @return int
	 */
	public function interval_count(): int {
		return (int) $this->length;
	}

	/**
	 * Vérifie les conditions d'utilisation de la passerelle.
	 *
	 * @return void
	 */
	public function validate_fields() {
		$currency_error = $this->currency_error();

		if ( null !== $currency_error ) {
			$this->add_error( $currency_error->get_error_code(), $currency_error->get_error_message() );

			return;
		}

		if ( empty( $this->secret_key ) || empty( $this->publishable_key ) ) {
			$this->add_error(
				'rcp_stripe_sepa_missing_keys',
				__( 'SEPA Direct Debit is not configured. Please contact the site administrator.', 'rcp-stripe-sepa' )
			);
		}
	}

	/**
	 * Rend le formulaire de collecte du mandat.
	 *
	 * @return string
	 */
	public function fields() {
		ob_start();

		require __DIR__ . '/../../templates/sepa-fields.php';

		return ob_get_clean();
	}

	/**
	 * Charge Stripe.js et le script de confirmation du mandat.
	 *
	 * @return void
	 */
	public function scripts() {
		if ( wp_script_is( 'rcp-stripe-sepa', 'enqueued' ) ) {
			return;
		}

		/*
		 * Stripe impose que sa bibliothèque soit chargée depuis son domaine :
		 * une copie locale rompt la conformité PCI et la détection de fraude.
		 * Aucun numéro de version n'est ajouté à l'URL, Stripe servant une
		 * ressource versionnée par son chemin.
		 */
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_script( 'stripe-js-v3', 'https://js.stripe.com/v3/', array(), null, true );

		wp_enqueue_script(
			'rcp-stripe-sepa',
			RCP_SEPA_PLUGIN_URL . 'assets/js/register.js',
			array( 'stripe-js-v3', 'jquery' ),
			RCP_SEPA_VERSION,
			true
		);

		wp_localize_script(
			'rcp-stripe-sepa',
			'rcpStripeSepa',
			array(
				'publishableKey' => $this->publishable_key,
				'gateway'        => self::GATEWAY_ID,
				'locale'         => substr( (string) get_locale(), 0, 2 ),
				'strings'        => array(
					'missingName'  => __( 'Please enter the account holder’s name.', 'rcp-stripe-sepa' ),
					'missingIban'  => __( 'Please enter a valid IBAN.', 'rcp-stripe-sepa' ),
					'genericError' => __( 'The mandate could not be saved. Please check your bank details.', 'rcp-stripe-sepa' ),
				),
			)
		);

		wp_enqueue_style(
			'rcp-stripe-sepa',
			RCP_SEPA_PLUGIN_URL . 'assets/css/register.css',
			array(),
			RCP_SEPA_VERSION
		);
	}

	// -- Outils internes ------------------------------------------------------------

	/**
	 * Contexte transmis à la fabrique d'intentions.
	 *
	 * @param string $customer_id Identifiant du client Stripe.
	 * @return array
	 */
	protected function intent_context( string $customer_id ): array {
		global $rcp_options;

		return array(
			'customer_id'          => $customer_id,
			'amount'               => (int) round( (float) $this->initial_amount * rcp_stripe_get_currency_multiplier() ),
			'currency'             => rcp_get_currency(),
			'description'          => (string) $this->membership->get_membership_level_name(),
			'recurring'            => (bool) $this->auto_renew,
			'statement_descriptor' => (string) ( $rcp_options['statement_descriptor'] ?? '' ),
			'payment_method'       => $this->requested_payment_method(),
			'metadata'             => array(
				'rcp_membership_id'    => (string) $this->membership->get_id(),
				'rcp_payment_id'       => (string) $this->payment->id,
				'rcp_subscription_key' => (string) $this->membership->get_subscription_key(),
				'user_id'              => (string) $this->user_id,
				'email'                => (string) $this->email,
			),
		);
	}

	/**
	 * Mandat déjà enregistré que l'adhérent a choisi de réutiliser.
	 *
	 * @return string
	 */
	protected function requested_payment_method(): string {
		/*
		 * La requête est déjà authentifiée en amont : RCP vérifie le nonce du
		 * formulaire d'inscription avant d'instancier la passerelle. La valeur
		 * lue ici n'est qu'un identifiant opaque, assaini puis confronté aux
		 * moyens de paiement du client par Stripe.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$requested = isset( $_POST['rcp_gateway_existing_payment_method'] )
			? sanitize_text_field( wp_unslash( $_POST['rcp_gateway_existing_payment_method'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return 'new' === $requested ? '' : $requested;
	}

	/**
	 * Crée l'intention adaptée au contexte.
	 *
	 * @param array $context Contexte d'inscription.
	 * @return \Stripe\PaymentIntent|\Stripe\SetupIntent
	 *
	 * @throws Exception Si Stripe refuse la création.
	 */
	protected function create_intent( array $context ) {
		$needs_payment = IntentFactory::needs_payment_intent( $context );

		$args = $needs_payment
			? IntentFactory::payment_intent( $context )
			: IntentFactory::setup_intent( $context );

		$options = StripeSdk::request_options(
			array( 'idempotency_key' => rcp_stripe_generate_idempotency_key( $args ) )
		);

		return $needs_payment
			? \Stripe\PaymentIntent::create( $args, $options )
			: \Stripe\SetupIntent::create( $args, $options );
	}

	/**
	 * Erreur de devise, le cas échéant.
	 *
	 * Le prélèvement SEPA ne fonctionne qu'en euros : proposer la passerelle
	 * dans une autre devise conduirait à un refus de Stripe au moment du
	 * paiement, après que l'adhérent a saisi son IBAN.
	 *
	 * @return WP_Error|null
	 */
	protected function currency_error(): ?WP_Error {
		if ( GatewayDefinition::CURRENCY === strtoupper( (string) rcp_get_currency() ) ) {
			return null;
		}

		return new WP_Error(
			'rcp_stripe_sepa_invalid_currency',
			sprintf(
				/* translators: %s: configured currency code. */
				__( 'SEPA Direct Debit requires payments in euros; this site uses %s.', 'rcp-stripe-sepa' ),
				strtoupper( (string) rcp_get_currency() )
			)
		);
	}

	/**
	 * Journalise un message expurgé.
	 *
	 * @param string $message Message.
	 * @param bool   $error   S'agit-il d'une erreur.
	 * @return void
	 */
	public function log( string $message, bool $error = false ): void {
		rcp_log( 'Gateway SEPA : ' . Redactor::redact( $message ), $error );
	}
}
