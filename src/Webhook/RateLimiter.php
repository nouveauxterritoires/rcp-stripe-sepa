<?php
/**
 * Limitation de débit du point de terminaison.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Webhook;

/**
 * Borne le nombre de requêtes acceptées par adresse et par minute.
 *
 * Le point de terminaison est public par nature — il est authentifié par
 * signature, pas par session. Sans borne, un tiers pourrait le solliciter en
 * boucle et faire porter au site le coût de la vérification HMAC.
 *
 * La limite est volontairement large : Stripe peut livrer en rafale après une
 * interruption, et un faux positif ferait perdre des événements.
 *
 * @see docs/cahier-des-charges.md §9.3 SEC-11
 */
final class RateLimiter {

	/**
	 * Requêtes autorisées par fenêtre.
	 */
	public const DEFAULT_LIMIT = 120;

	/**
	 * Durée de la fenêtre, en secondes.
	 */
	public const WINDOW = 60;

	/**
	 * Préfixe des entrées de cache.
	 */
	private const PREFIX = 'rcp_sepa_wh_';

	/**
	 * La requête est-elle autorisée ?
	 *
	 * @param string $identifier Identifiant de l'appelant.
	 * @return bool
	 */
	public static function allow( string $identifier ): bool {
		/**
		 * Filtre le nombre de requêtes autorisées par minute.
		 *
		 * Une infrastructure derrière une adresse IP unique — mandataire
		 * inverse, passerelle d'entreprise — peut avoir besoin d'une limite
		 * plus élevée, voire de zéro pour la désactiver.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $limit      Requêtes autorisées par fenêtre.
		 * @param string $identifier Identifiant de l'appelant.
		 */
		$limit = (int) apply_filters( 'rcp_stripe_sepa_webhook_rate_limit', self::DEFAULT_LIMIT, $identifier );

		if ( $limit <= 0 ) {
			return true;
		}

		$key   = self::PREFIX . md5( $identifier );
		$count = get_transient( $key );

		if ( false === $count ) {
			set_transient( $key, 1, self::WINDOW );

			return true;
		}

		$count = (int) $count + 1;

		set_transient( $key, $count, self::WINDOW );

		return $count <= $limit;
	}

	/**
	 * Identifie l'appelant à partir de la requête.
	 *
	 * @return string
	 */
	public static function identify(): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: 'inconnu';

		return '' !== $address ? $address : 'inconnu';
	}

	/**
	 * Réinitialise le compteur d'un appelant.
	 *
	 * @param string $identifier Identifiant de l'appelant.
	 * @return void
	 */
	public static function reset( string $identifier ): void {
		delete_transient( self::PREFIX . md5( $identifier ) );
	}
}
