<?php
/**
 * Plugin Name:       Prélèvement SEPA pour Restrict Content Pro
 * Plugin URI:        https://github.com/nouveaux-territoires/rcp-stripe-sepa
 * Description:       Ajoute le prélèvement automatique SEPA (Stripe) aux moyens de paiement de Restrict Content Pro : adhésions récurrentes, paiements uniques et migration carte vers SEPA.
 * Version:           0.1.0-dev
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Nouveaux Territoires
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rcp-stripe-sepa
 * Domain Path:       /languages
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const RCP_SEPA_VERSION = '0.1.0-dev';

define( 'RCP_SEPA_PLUGIN_FILE', __FILE__ );
define( 'RCP_SEPA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RCP_SEPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Version de l'API Stripe utilisée par le plugin.
 *
 * Restrict Content Pro fixe globalement la version d'API à `2020-08-27` via
 * `\Stripe\Stripe::setApiVersion()`. Ce réglage est statique : le modifier
 * casserait la passerelle carte de RCP. Le plugin ne le touche donc jamais et
 * transmet cette version dans les options de chaque requête.
 *
 * La valeur retenue correspond à celle du SDK Stripe embarqué par RCP
 * (`\Stripe\Util\ApiVersion::CURRENT`), afin que les objets renvoyés par l'API
 * correspondent à ce que le SDK sait décoder.
 *
 * @see docs/cahier-des-charges.md §3.3 règles R-API-1 à R-API-3
 */
if ( ! defined( 'RCP_SEPA_STRIPE_API_VERSION' ) ) {
	define( 'RCP_SEPA_STRIPE_API_VERSION', '2022-11-15' );
}

/**
 * Charge l'autoloader du plugin.
 *
 * L'archive distribuable embarque `vendor/autoload.php`. En développement, un
 * autoloader PSR-4 minimal prend le relais si les dépendances ne sont pas
 * encore installées.
 *
 * @return void
 */
function rcp_stripe_sepa_register_autoloader(): void {
	$composer = RCP_SEPA_PLUGIN_DIR . 'vendor/autoload.php';

	if ( is_readable( $composer ) ) {
		require_once $composer;

		return;
	}

	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'RCP_Stripe_Sepa\\';

			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}

			$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
			$path     = RCP_SEPA_PLUGIN_DIR . 'src/' . $relative . '.php';

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

rcp_stripe_sepa_register_autoloader();

/**
 * Démarre le plugin une fois Restrict Content Pro chargé.
 *
 * La priorité 20 laisse à RCP — variante libre comme commerciale — le temps de
 * définir ses classes, ses constantes et son SDK Stripe.
 *
 * @return void
 */
function rcp_stripe_sepa_bootstrap(): void {
	\RCP_Stripe_Sepa\Plugin::boot();
}

add_action( 'plugins_loaded', 'rcp_stripe_sepa_bootstrap', 20 );
