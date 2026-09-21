<?php
/**
 * Expurgation des données sensibles avant journalisation.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Logging;

/**
 * Masque les secrets et les données bancaires dans ce qui est journalisé.
 *
 * Un journal est lu par des personnes, copié dans des tickets de support et
 * parfois exporté. Rien de ce qui y transite ne doit permettre de rejouer un
 * appel à Stripe ni d'identifier un compte bancaire.
 *
 * @see docs/cahier-des-charges.md §9.4 SEC-13
 */
final class Redactor {

	/**
	 * Marqueur substitué à toute valeur masquée.
	 */
	public const PLACEHOLDER = '[expurgé]';

	/**
	 * Motifs de valeurs à masquer, quel que soit le contexte.
	 *
	 * @var string[]
	 */
	private const VALUE_PATTERNS = array(
		// Clés d'API et secrets de webhook.
		'/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{8,}/',
		'/\bwhsec_[A-Za-z0-9]{8,}/',
		// Secret client d'une intention : permet de confirmer un paiement.
		'/\b(?:pi|seti)_[A-Za-z0-9]+_secret_[A-Za-z0-9]{8,}/',
		// IBAN : deux lettres de pays, deux chiffres de contrôle, puis 11 à 30
		// caractères. Le motif exige au moins un chiffre afin de ne pas happer
		// un identifiant Stripe en majuscules.
		'/\b[A-Z]{2}[0-9]{2}(?=[A-Z0-9]{11,30}\b)(?:[A-Z0-9]*[0-9][A-Z0-9]*)\b/',
	);

	/**
	 * Clés dont la valeur est toujours masquée, quelle qu'elle soit.
	 *
	 * @var string[]
	 */
	private const SENSITIVE_KEYS = array(
		'client_secret',
		'iban',
		'secret',
		'api_key',
		'webhook_secret',
		'stripe_test_secret',
		'stripe_live_secret',
		'authorization',
	);

	/**
	 * Masque les valeurs sensibles d'un message.
	 *
	 * @param string $message Message à journaliser.
	 * @return string
	 */
	public static function redact( string $message ): string {
		foreach ( self::VALUE_PATTERNS as $pattern ) {
			$message = (string) preg_replace( $pattern, self::PLACEHOLDER, $message );
		}

		return $message;
	}

	/**
	 * Masque les valeurs sensibles d'un tableau, en profondeur.
	 *
	 * Le tableau fourni n'est jamais modifié.
	 *
	 * @param array $data Données à journaliser.
	 * @return array
	 */
	public static function redact_array( array $data ): array {
		$redacted = array();

		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && self::is_sensitive_key( $key ) ) {
				$redacted[ $key ] = self::PLACEHOLDER;

				continue;
			}

			if ( is_array( $value ) ) {
				$redacted[ $key ] = self::redact_array( $value );

				continue;
			}

			$redacted[ $key ] = is_string( $value ) ? self::redact( $value ) : $value;
		}

		return $redacted;
	}

	/**
	 * La clé désigne-t-elle une valeur toujours sensible ?
	 *
	 * @param string $key Nom de la clé.
	 * @return bool
	 */
	private static function is_sensitive_key( string $key ): bool {
		return in_array( strtolower( $key ), self::SENSITIVE_KEYS, true );
	}
}
