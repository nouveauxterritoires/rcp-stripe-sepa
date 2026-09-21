<?php
/**
 * Amorçage PHPUnit.
 *
 * Deux modes selon la suite exécutée :
 *  - suite « unit » : WordPress est mocké par Brain Monkey, aucune base de données ;
 *  - autres suites  : la bibliothèque de tests de WordPress est chargée, avec RCP actif.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

$autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $autoloader ) ) {
	fwrite( STDERR, "Dépendances absentes. Exécutez : composer install\n" );
	exit( 1 );
}

require_once $autoloader;

/**
 * Détermine si la suite en cours nécessite un WordPress réel.
 *
 * @return bool
 */
function rcp_stripe_sepa_tests_need_wordpress(): bool {
	$argv = $_SERVER['argv'] ?? array();

	foreach ( $argv as $index => $arg ) {
		if ( '--testsuite' === $arg ) {
			return 'unit' !== ( $argv[ $index + 1 ] ?? '' );
		}

		if ( 0 === strpos( $arg, '--testsuite=' ) ) {
			return 'unit' !== substr( $arg, strlen( '--testsuite=' ) );
		}
	}

	// Sans précision, on exécute tout : WordPress est nécessaire.
	return true;
}

if ( ! rcp_stripe_sepa_tests_need_wordpress() ) {
	return;
}

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( ! file_exists( $tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"Bibliothèque de tests WordPress introuvable dans {$tests_dir}.\n"
		. "Exécutez : make prepare-tests\n"
	);
	exit( 1 );
}

require_once $tests_dir . '/includes/functions.php';

/**
 * Active Restrict Content puis le plugin avant le chargement de WordPress.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () {
		$plugins = array(
			'restrict-content/restrictcontent.php',
			'rcp-stripe-sepa/rcp-stripe-sepa.php',
		);

		foreach ( $plugins as $plugin ) {
			$path = WP_PLUGIN_DIR . '/' . $plugin;

			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}
);

require $tests_dir . '/includes/bootstrap.php';
