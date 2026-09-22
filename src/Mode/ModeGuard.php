<?php
/**
 * Cohérence entre le mode configuré et les clés Stripe en place.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Mode;

use RCP_Stripe_Sepa\Support\StripeSdk;

/**
 * Refuse la passerelle lorsque le mode et la clé secrète se contredisent.
 *
 * Une clé de production employée alors que le bac à sable est actif prélèverait
 * réellement des comptes bancaires pendant une recette. L'inverse — une clé de
 * test en production — laisserait croire à des encaissements qui n'existent
 * pas. Les deux sont des erreurs de configuration silencieuses, qu'aucun écran
 * ne signale : le plugin les traite donc comme bloquantes.
 *
 * @see docs/cahier-des-charges.md §9.6 SEC-24
 */
final class ModeGuard {

	public const STATUS_OK       = 'ok';
	public const STATUS_LIVE_KEY = 'live_key_in_test_mode';
	public const STATUS_TEST_KEY = 'test_key_in_live_mode';
	public const STATUS_NO_KEY   = 'missing_key';

	/**
	 * Préfixes d'une clé de production : secrète ou restreinte.
	 */
	private const LIVE_PREFIXES = array( 'sk_live_', 'rk_live_' );

	/**
	 * Préfixes d'une clé de test.
	 */
	private const TEST_PREFIXES = array( 'sk_test_', 'rk_test_' );

	/**
	 * Diagnostic de cohérence.
	 *
	 * @param string|null $secret_key Clé à examiner ; lue dans RCP si omise.
	 * @param bool|null   $is_test    Mode à considérer ; lu dans RCP si omis.
	 * @return string L'une des constantes `STATUS_*`.
	 */
	public static function assess( ?string $secret_key = null, ?bool $is_test = null ): string {
		$key = null === $secret_key ? StripeSdk::secret_key() : trim( $secret_key );

		if ( '' === $key ) {
			return self::STATUS_NO_KEY;
		}

		$test_mode = null === $is_test ? self::is_test_mode() : $is_test;

		if ( $test_mode && self::has_prefix( $key, self::LIVE_PREFIXES ) ) {
			return self::STATUS_LIVE_KEY;
		}

		if ( ! $test_mode && self::has_prefix( $key, self::TEST_PREFIXES ) ) {
			return self::STATUS_TEST_KEY;
		}

		return self::STATUS_OK;
	}

	/**
	 * La passerelle peut-elle être proposée ?
	 *
	 * Une clé absente n'est pas une incohérence : l'installation n'est pas
	 * encore configurée, ce que signale déjà l'écran de diagnostic.
	 *
	 * @param string|null $secret_key Clé à examiner.
	 * @param bool|null   $is_test    Mode à considérer.
	 * @return bool
	 */
	public static function allows_gateway( ?string $secret_key = null, ?bool $is_test = null ): bool {
		$status = self::assess( $secret_key, $is_test );

		return self::STATUS_LIVE_KEY !== $status && self::STATUS_TEST_KEY !== $status;
	}

	/**
	 * Message destiné à l'administration, vide si tout concorde.
	 *
	 * @param string|null $secret_key Clé à examiner.
	 * @param bool|null   $is_test    Mode à considérer.
	 * @return string
	 */
	public static function message( ?string $secret_key = null, ?bool $is_test = null ): string {
		switch ( self::assess( $secret_key, $is_test ) ) {
			case self::STATUS_LIVE_KEY:
				return __(
					'SEPA Direct Debit is disabled: Restrict Content Pro is in test mode, but a live Stripe key is configured. Real bank accounts would be debited.',
					'rcp-stripe-sepa'
				);

			case self::STATUS_TEST_KEY:
				return __(
					'SEPA Direct Debit is disabled: Restrict Content Pro is in live mode, but a test Stripe key is configured. No payment would actually be collected.',
					'rcp-stripe-sepa'
				);

			default:
				return '';
		}
	}

	/**
	 * Le site est-il en mode bac à sable ?
	 *
	 * Le mode est celui de RCP : le plugin n'introduit pas son propre
	 * sélecteur (SEC-21).
	 *
	 * @return bool
	 */
	public static function is_test_mode(): bool {
		return function_exists( 'rcp_is_sandbox' ) && rcp_is_sandbox();
	}

	/**
	 * La clé commence-t-elle par l'un des préfixes ?
	 *
	 * @param string   $key      Clé secrète.
	 * @param string[] $prefixes Préfixes recherchés.
	 * @return bool
	 */
	private static function has_prefix( string $key, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
