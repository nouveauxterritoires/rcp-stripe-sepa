<?php
/**
 * Résolution du secret de signature des webhooks.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Webhook;

/**
 * Fournit le secret du point de terminaison, cloisonné par mode.
 *
 * Une constante de `wp-config.php` prévaut toujours sur une option en base :
 * un secret hors base ne fuite ni dans un export, ni dans une sauvegarde
 * partagée, ni dans l'interface d'administration.
 *
 * @see docs/cahier-des-charges.md §9.2 SEC-02
 */
final class WebhookSecret {

	public const CONSTANT_TEST = 'RCP_SEPA_WEBHOOK_SECRET_TEST';
	public const CONSTANT_LIVE = 'RCP_SEPA_WEBHOOK_SECRET_LIVE';

	public const OPTION_TEST = 'rcp_stripe_sepa_webhook_secret_test';
	public const OPTION_LIVE = 'rcp_stripe_sepa_webhook_secret_live';

	/**
	 * Le site est-il en mode test ?
	 *
	 * Le mode est celui de RCP : le plugin n'introduit pas son propre
	 * sélecteur, afin qu'un seul mode soit actif sur le site.
	 *
	 * @return bool
	 */
	public static function is_test_mode(): bool {
		global $rcp_options;

		return ! empty( $rcp_options['sandbox'] );
	}

	/**
	 * Secret du mode courant, ou chaîne vide s'il n'est pas configuré.
	 *
	 * @return string
	 */
	public static function get(): string {
		$test = self::is_test_mode();

		$constant = $test ? self::CONSTANT_TEST : self::CONSTANT_LIVE;

		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
			return (string) constant( $constant );
		}

		$option = get_option( $test ? self::OPTION_TEST : self::OPTION_LIVE, '' );

		return is_string( $option ) ? $option : '';
	}

	/**
	 * Le secret est-il défini par une constante, donc non modifiable en base ?
	 *
	 * @return bool
	 */
	public static function is_locked(): bool {
		$constant = self::is_test_mode() ? self::CONSTANT_TEST : self::CONSTANT_LIVE;

		return defined( $constant ) && '' !== (string) constant( $constant );
	}
}
