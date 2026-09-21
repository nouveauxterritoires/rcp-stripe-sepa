<?php
/**
 * Déclarations de Restrict Content Pro, pour l'analyse statique uniquement.
 *
 * Ce fichier n'est jamais chargé à l'exécution : il décrit à PHPStan les
 * classes et fonctions que RCP fournit au moment de l'exécution, et que le
 * plugin référence. Il couvre indifféremment la variante libre et la variante
 * commerciale, dont les API sont identiques.
 *
 * Toute évolution de `RcpEnvironment::REQUIRED_*` doit être répercutée ici.
 *
 * @package RCP_Stripe_Sepa
 * @see docs/compatibilite-rcp.md
 */

// phpcs:ignoreFile

/**
 * Classe abstraite des passerelles de paiement de RCP.
 */
abstract class RCP_Payment_Gateway {

	/**
	 * Capacités déclarées par la passerelle.
	 *
	 * @var string[]
	 */
	protected $supports = array();

	/**
	 * Montant du premier paiement.
	 *
	 * @var float
	 */
	public $initial_amount = 0.0;

	/**
	 * Montant des échéances suivantes.
	 *
	 * @var float
	 */
	public $amount = 0.0;

	/**
	 * Mode bac à sable hérité des réglages de RCP.
	 *
	 * @var bool
	 */
	public $test_mode = false;

	/**
	 * Adhésion en cours de traitement.
	 *
	 * @var RCP_Membership
	 */
	public $membership;

	/**
	 * Client RCP.
	 *
	 * @var object
	 */
	public $customer;

	/**
	 * Enregistrement de paiement en cours.
	 *
	 * @var object
	 */
	public $payment;

	/**
	 * Identifiant de l'utilisateur WordPress.
	 *
	 * @var int
	 */
	public $user_id = 0;

	/**
	 * Adresse électronique de l'adhérent.
	 *
	 * @var string
	 */
	public $email = '';

	/**
	 * Renouvellement automatique.
	 *
	 * @var bool
	 */
	public $auto_renew = false;

	/**
	 * Identifiant du niveau d'adhésion.
	 *
	 * @var int
	 */
	public $subscription_id = 0;

	/**
	 * Nom du niveau d'adhésion.
	 *
	 * @var string
	 */
	public $subscription_name = '';

	/**
	 * Durée de la période de facturation.
	 *
	 * @var int
	 */
	public $length = 0;

	/**
	 * Unité de la période de facturation.
	 *
	 * @var string
	 */
	public $length_unit = '';

	/**
	 * URL de retour après inscription.
	 *
	 * @var string
	 */
	public $return_url = '';

	/**
	 * Clé secrète Stripe du mode courant.
	 *
	 * @var string
	 */
	protected $secret_key = '';

	/**
	 * Clé publiable Stripe du mode courant.
	 *
	 * @var string
	 */
	protected $publishable_key = '';

	/**
	 * @param array $subscription_data Données d'inscription.
	 */
	public function __construct( $subscription_data = array() ) {}

	/** @return void */
	public function init() {}

	/** @return array|WP_Error */
	public function process_ajax_signup() {}

	/** @return void */
	public function process_signup() {}

	/** @return void */
	public function process_webhooks() {}

	/** @return void */
	public function fields() {}

	/** @return void */
	public function scripts() {}

	/** @return void */
	public function validate_fields() {}

	/** @return void */
	public function update_card_fields() {}

	/**
	 * @param string $item Capacité recherchée.
	 * @return bool
	 */
	public function supports( $item = '' ) {}

	/**
	 * @param string $code    Code d'erreur.
	 * @param string $message Message d'erreur.
	 * @return void
	 */
	public function add_error( $code = '', $message = '' ) {}

	/**
	 * @return bool
	 */
	public function is_trial() {}
}

/**
 * Passerelle Stripe (carte bancaire) de RCP.
 */
class RCP_Payment_Gateway_Stripe extends RCP_Payment_Gateway {

	/**
	 * @param int    $rcp_customer_id Identifiant client RCP.
	 * @param int    $user_id         Identifiant utilisateur WordPress.
	 * @param string $customer_id     Identifiant client Stripe.
	 * @return \Stripe\Customer|WP_Error
	 */
	public function get_or_create_customer( $rcp_customer_id = 0, $user_id = 0, $customer_id = '' ) {}

	/**
	 * @param array $args Arguments du plan.
	 * @return string|WP_Error
	 */
	public function maybe_create_plan( $args ) {}

	/**
	 * @param int    $interval      Durée de la période.
	 * @param string $interval_unit Unité de la période.
	 * @param string $signup_date   Date d'inscription.
	 * @return \DateTime
	 */
	public function get_stripe_max_billing_cycle_anchor( $interval, $interval_unit, $signup_date = 'now' ) {}

	/**
	 * @param \Exception|WP_Error $error Erreur rencontrée.
	 * @return void
	 */
	protected function handle_processing_error( $error ) {}
}

/**
 * Registre des passerelles de paiement.
 */
class RCP_Payment_Gateways {

	/**
	 * Passerelles disponibles.
	 *
	 * @var array
	 */
	public $available_gateways = array();

	/**
	 * @param string $id Identifiant de passerelle.
	 * @return array|false
	 */
	public function get_gateway( $id = '' ) {}
}

/**
 * Adhésion RCP.
 */
class RCP_Membership {

	/**
	 * @param int|object $object_id Identifiant ou objet d'adhésion.
	 */
	public function __construct( $object_id = 0 ) {}

	/** @return int */
	public function get_id() {}

	/** @return int */
	public function get_user_id() {}

	/** @return int */
	public function get_customer_id() {}

	/** @return int */
	public function get_object_id() {}

	/** @return string */
	public function get_status() {}

	/**
	 * @param string $status Nouveau statut.
	 * @return bool
	 */
	public function set_status( $status ) {}

	/** @return string */
	public function get_gateway() {}

	/** @return string */
	public function get_gateway_customer_id() {}

	/** @return string */
	public function get_gateway_subscription_id() {}

	/**
	 * @param string $subscription_id Identifiant d'abonnement Stripe.
	 * @return bool
	 */
	public function set_gateway_subscription_id( $subscription_id ) {}

	/**
	 * @param string $customer_id Identifiant client Stripe.
	 * @return bool
	 */
	public function set_gateway_customer_id( $customer_id ) {}

	/** @return string */
	public function get_subscription_key() {}

	/** @return string */
	public function get_membership_level_name() {}

	/** @return string */
	public function get_notes() {}

	/**
	 * @param string $note Note à ajouter.
	 * @return bool
	 */
	public function add_note( $note ) {}

	/**
	 * @param array $data Champs à mettre à jour.
	 * @return bool
	 */
	public function update( $data = array() ) {}

	/** @return bool */
	public function is_disabled() {}

	/** @return bool */
	public function is_recurring() {}

	/**
	 * @param bool $formatted Formater la date.
	 * @return string
	 */
	public function get_expiration_date( $formatted = true ) {}

	/**
	 * @param bool $recurring L'adhésion se renouvelle-t-elle.
	 * @return bool
	 */
	public function set_recurring( $recurring = true ) {}
}

/**
 * Accès aux paiements enregistrés par RCP.
 */
class RCP_Payments {

	/**
	 * @param int $payment_id Identifiant du paiement.
	 * @return object|false
	 */
	public function get_payment( $payment_id = 0 ) {}

	/**
	 * @param array $args Critères.
	 * @return array
	 */
	public function get_payments( $args = array() ) {}

	/**
	 * @param int   $payment_id Identifiant du paiement.
	 * @param array $data       Données à mettre à jour.
	 * @return bool
	 */
	public function update( $payment_id = 0, $data = array() ) {}

	/**
	 * @param int    $payment_id Identifiant du paiement.
	 * @param string $meta_key   Clé.
	 * @param bool   $single     Valeur unique.
	 * @return mixed
	 */
	public function get_meta( $payment_id = 0, $meta_key = '', $single = false ) {}

	/**
	 * @param int    $payment_id Identifiant du paiement.
	 * @param string $meta_key   Clé.
	 * @param mixed  $meta_value Valeur.
	 * @return bool
	 */
	public function update_meta( $payment_id = 0, $meta_key = '', $meta_value = '' ) {}
}

/**
 * Journalise un message dans le journal de RCP.
 *
 * @param string $message Message.
 * @param bool   $error   Marquer comme erreur.
 * @return void
 */
function rcp_log( $message = '', $error = false ) {}

/**
 * Devise configurée dans RCP.
 *
 * @return string
 */
function rcp_get_currency() {}

/**
 * Récupère une adhésion.
 *
 * @param int $membership_id Identifiant d'adhésion.
 * @return RCP_Membership|false
 */
function rcp_get_membership( $membership_id = 0 ) {}

/**
 * Récupère une adhésion par l'un de ses champs.
 *
 * @param string $field Champ recherché.
 * @param mixed  $value Valeur recherchée.
 * @return RCP_Membership|false
 */
function rcp_get_membership_by( $field = '', $value = '' ) {}

/**
 * Récupère le client RCP d'un utilisateur.
 *
 * @param int $user_id Identifiant utilisateur.
 * @return object|false
 */
function rcp_get_customer_by_user_id( $user_id = 0 ) {}

/**
 * Récupère un client RCP.
 *
 * @param int $customer_id Identifiant client.
 * @return object|false
 */
function rcp_get_customer( $customer_id = 0 ) {}

/**
 * Récupère les adhésions d'un client.
 *
 * @param int   $customer_id Identifiant client.
 * @param array $args        Critères.
 * @return RCP_Membership[]
 */
function rcp_get_customer_memberships( $customer_id = 0, $args = array() ) {}

/**
 * Récupère des adhésions.
 *
 * @param array $args Critères.
 * @return RCP_Membership[]
 */
function rcp_get_memberships( $args = array() ) {}

/**
 * Multiplicateur à appliquer au montant selon la devise.
 *
 * @return int
 */
function rcp_stripe_get_currency_multiplier() {}

/**
 * Ajoute ou met à jour une métadonnée d'adhésion.
 *
 * @param int    $membership_id Identifiant d'adhésion.
 * @param string $meta_key      Clé.
 * @param mixed  $meta_value    Valeur.
 * @param mixed  $prev_value    Valeur précédente.
 * @return int|bool
 */
function rcp_update_membership_meta( $membership_id, $meta_key, $meta_value, $prev_value = '' ) {}

/**
 * Lit une métadonnée d'adhésion.
 *
 * @param int    $membership_id Identifiant d'adhésion.
 * @param string $key           Clé.
 * @param bool   $single        Valeur unique.
 * @return mixed
 */
function rcp_get_membership_meta( $membership_id, $key = '', $single = false ) {}

/**
 * Supprime une métadonnée d'adhésion.
 *
 * @param int    $membership_id Identifiant d'adhésion.
 * @param string $meta_key      Clé.
 * @param mixed  $meta_value    Valeur.
 * @return bool
 */
function rcp_delete_membership_meta( $membership_id, $meta_key, $meta_value = '' ) {}

/**
 * Génère une clé d'idempotence déterministe pour une requête Stripe.
 *
 * @param array $args Arguments de la requête.
 * @return string
 */
function rcp_stripe_generate_idempotency_key( $args ) {}
