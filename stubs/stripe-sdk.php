<?php
/**
 * Déclarations du SDK Stripe embarqué par RCP, pour l'analyse statique.
 *
 * Ce fichier n'est jamais chargé à l'exécution : le SDK réel est fourni par
 * Restrict Content Pro, et chargé à la demande par `Support\StripeSdk`.
 *
 * Seuls les objets et propriétés réellement utilisés par le plugin sont
 * déclarés. Toute utilisation d'un champ absent d'ici doit être ajoutée
 * sciemment, ce qui force à vérifier qu'il existe bien dans la version d'API
 * que le plugin transmet.
 *
 * @package RCP_Stripe_Sepa
 */

// phpcs:ignoreFile

namespace Stripe;

/**
 * Objet Stripe générique.
 */
class StripeObject {

	/** @var string */
	public $id;

	/** @var string */
	public $object;

	/** @return array */
	public function toArray() {}
}

/**
 * Point d'entrée de configuration du SDK.
 */
class Stripe {

	const VERSION = '10.3.0';

	/** @return string|null */
	public static function getApiVersion() {}
}

/**
 * Authentification des charges utiles de webhook.
 */
class Webhook {

	/**
	 * @param string $payload   Charge utile brute.
	 * @param string $header    En-tête Stripe-Signature.
	 * @param string $secret    Secret du point de terminaison.
	 * @param int    $tolerance Tolérance temporelle, en secondes.
	 * @return \Stripe\Event
	 */
	public static function constructEvent( $payload, $header, $secret, $tolerance = null ) {}
}

/**
 * Événement Stripe.
 */
class Event extends StripeObject {

	/** @var string */
	public $type;

	/** @var bool */
	public $livemode;

	/**
	 * @param string|array $id      Identifiant.
	 * @param array        $options Options de requête.
	 * @return \Stripe\Event
	 */
	public static function retrieve( $id, $options = null ) {}
}

/**
 * Client Stripe.
 */
class Customer extends StripeObject {

	/**
	 * @param string $id      Identifiant du client.
	 * @param array  $params  Champs à mettre à jour.
	 * @param array  $options Options de requête.
	 * @return \Stripe\Customer
	 */
	public static function update( $id, $params = null, $options = null ) {}

	/**
	 * @param string|array $id      Identifiant du client.
	 * @param array        $options Options de requête.
	 * @return \Stripe\Customer
	 */
	public static function retrieve( $id, $options = null ) {}
}

/**
 * Encaissement.
 */
class PaymentIntent extends StripeObject {

	/** @var string */
	public $client_secret;

	/** @var string */
	public $customer;

	/** @var string */
	public $payment_method;

	/** @var string */
	public $status;

	/** @var string|object|null */
	public $latest_charge;

	/** @var object|null */
	public $charges;

	/**
	 * @param array $params  Arguments.
	 * @param array $options Options de requête.
	 * @return \Stripe\PaymentIntent
	 */
	public static function create( $params = null, $options = null ) {}

	/**
	 * @param string|array $id      Identifiant.
	 * @param array        $options Options de requête.
	 * @return \Stripe\PaymentIntent
	 */
	public static function retrieve( $id, $options = null ) {}
}

/**
 * Enregistrement d'un moyen de paiement sans encaissement.
 */
class SetupIntent extends StripeObject {

	/** @var string */
	public $client_secret;

	/** @var string */
	public $customer;

	/** @var string */
	public $payment_method;

	/** @var string */
	public $status;

	/**
	 * @param array $params  Arguments.
	 * @param array $options Options de requête.
	 * @return \Stripe\SetupIntent
	 */
	public static function create( $params = null, $options = null ) {}

	/**
	 * @param string|array $id      Identifiant.
	 * @param array        $options Options de requête.
	 * @return \Stripe\SetupIntent
	 */
	public static function retrieve( $id, $options = null ) {}
}

/**
 * Moyen de paiement.
 */
class PaymentMethod extends StripeObject {

	/** @var string */
	public $type;

	/** @var string|null */
	public $customer;

	/** @var object|null */
	public $card;

	/** @var object|null */
	public $sepa_debit;

	/** @var object|null */
	public $billing_details;

	/**
	 * @param string|array $id      Identifiant.
	 * @param array        $options Options de requête.
	 * @return \Stripe\PaymentMethod
	 */
	public static function retrieve( $id, $options = null ) {}

	/**
	 * @param array $params  Paramètres, dont `customer`.
	 * @param array $options Options de requête.
	 * @return \Stripe\PaymentMethod
	 */
	public function attach( $params = null, $options = null ) {}

	/**
	 * @param array $params  Paramètres.
	 * @param array $options Options de requête.
	 * @return \Stripe\PaymentMethod
	 */
	public function detach( $params = null, $options = null ) {}
}

/**
 * Mandat de prélèvement.
 */
class Mandate extends StripeObject {

	/** @var string */
	public $status;

	/** @var object|null */
	public $payment_method_details;

	/**
	 * @param string|array $id      Identifiant.
	 * @param array        $options Options de requête.
	 * @return \Stripe\Mandate
	 */
	public static function retrieve( $id, $options = null ) {}
}

/**
 * Abonnement récurrent.
 */
class Subscription extends StripeObject {

	/** @var string */
	public $status;

	/**
	 * @param array $params  Arguments.
	 * @param array $options Options de requête.
	 * @return \Stripe\Subscription
	 */
	public static function create( $params = null, $options = null ) {}

	/**
	 * @param string|array $id      Identifiant.
	 * @param array        $options Options de requête.
	 * @return \Stripe\Subscription
	 */
	public static function retrieve( $id, $options = null ) {}

	/**
	 * @param string $id      Identifiant.
	 * @param array  $params  Champs à mettre à jour.
	 * @param array  $options Options de requête.
	 * @return \Stripe\Subscription
	 */
	public static function update( $id, $params = null, $options = null ) {}

	/**
	 * @param array $params  Paramètres.
	 * @param array $options Options de requête.
	 * @return \Stripe\Subscription
	 */
	public function cancel( $params = null, $options = null ) {}
}

namespace Stripe\Util;

/**
 * Version d'API du SDK.
 */
class ApiVersion {

	const CURRENT = '2022-11-15';
}

namespace Stripe\Exception;

/**
 * Erreur renvoyée par l'API Stripe.
 */
class ApiErrorException extends \Exception {
}

/**
 * Signature de webhook invalide.
 */
class SignatureVerificationException extends \Exception {
}
