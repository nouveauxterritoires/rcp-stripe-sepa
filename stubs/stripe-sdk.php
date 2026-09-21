<?php
/**
 * Déclarations du SDK Stripe embarqué par RCP, pour l'analyse statique.
 *
 * Ce fichier n'est jamais chargé à l'exécution : le SDK réel est fourni par
 * Restrict Content Pro, et chargé à la demande par `Support\StripeSdk`.
 *
 * @package RCP_Stripe_Sepa
 */

// phpcs:ignoreFile

namespace Stripe;

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
class Event {

	/** @var string */
	public $id;

	/** @var string */
	public $type;

	/** @var bool */
	public $livemode;
}

namespace Stripe\Util;

/**
 * Version d'API du SDK.
 */
class ApiVersion {

	const CURRENT = '2022-11-15';
}
