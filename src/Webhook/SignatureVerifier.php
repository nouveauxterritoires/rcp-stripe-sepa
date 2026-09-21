<?php
/**
 * Authentification des webhooks Stripe.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Webhook;

use RCP_Stripe_Sepa\Support\StripeSdk;
use Throwable;

/**
 * Vérifie l'en-tête `Stripe-Signature` d'une charge utile brute.
 *
 * Restrict Content Pro ne vérifie pas cette signature : son écouteur relit
 * l'événement via l'API à partir de son identifiant. Le plugin n'emprunte donc
 * pas cet écouteur et authentifie lui-même, par HMAC, ce qui écarte le rejeu
 * comme la falsification.
 *
 * @see docs/cahier-des-charges.md §9.3 SEC-06, SEC-07
 */
final class SignatureVerifier {

	/**
	 * Tolérance temporelle, en secondes.
	 *
	 * Un événement signé il y a plus longtemps est refusé : c'est ce qui borne
	 * la fenêtre de rejeu d'une charge utile interceptée.
	 */
	public const TOLERANCE = 300;

	/**
	 * En-tête HTTP portant la signature.
	 */
	public const HEADER = 'stripe-signature';

	/**
	 * Secret du point de terminaison.
	 *
	 * @var string
	 */
	private $secret;

	/**
	 * Tolérance temporelle appliquée.
	 *
	 * @var int
	 */
	private $tolerance;

	/**
	 * Construit un vérificateur.
	 *
	 * @param string $secret    Secret du point de terminaison.
	 * @param int    $tolerance Tolérance temporelle, en secondes.
	 */
	public function __construct( string $secret, int $tolerance = self::TOLERANCE ) {
		$this->secret    = $secret;
		$this->tolerance = $tolerance;
	}

	/**
	 * Authentifie une charge utile et renvoie l'événement décodé.
	 *
	 * La charge utile est renvoyée telle que Stripe l'a émise, et non
	 * reconstruite depuis les objets du SDK : la version d'API d'un webhook est
	 * celle du compte, et peut différer de celle que le plugin utilise dans ses
	 * propres requêtes. Conserver la forme d'origine évite toute perte de champ.
	 *
	 * @param string $payload Charge utile brute.
	 * @param string $header  Valeur de l'en-tête Stripe-Signature.
	 * @return array Événement décodé.
	 *
	 * @throws SignatureException Si la charge utile ne peut pas être authentifiée.
	 */
	public function verify( string $payload, string $header ): array {
		if ( '' === $this->secret ) {
			throw new SignatureException( 'Aucun secret de webhook configuré.' );
		}

		if ( '' === $header ) {
			throw new SignatureException( 'En-tête Stripe-Signature absent.' );
		}

		if ( ! StripeSdk::ensure_loaded() ) {
			throw new SignatureException( 'SDK Stripe indisponible : vérification impossible.' );
		}

		try {
			\Stripe\Webhook::constructEvent( $payload, $header, $this->secret, $this->tolerance );
		} catch ( Throwable $exception ) {
			/*
			 * L'exception d'origine est chaînée pour le diagnostic. Son message
			 * part dans le journal du serveur, jamais dans la réponse HTTP :
			 * le point de terminaison ne renvoie qu'un code, précisément pour
			 * ne rien apprendre à un appelant non authentifié.
			 */
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new SignatureException( esc_html( $exception->getMessage() ), 0, $exception );
		}

		$event = json_decode( $payload, true );

		if ( ! is_array( $event ) || ! isset( $event['id'], $event['type'] ) ) {
			throw new SignatureException( 'Charge utile authentifiée mais illisible.' );
		}

		return $event;
	}
}
