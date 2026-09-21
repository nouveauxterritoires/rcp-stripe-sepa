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
 * Suite demandée en ligne de commande, ou null si aucune.
 *
 * @return string|null
 */
function rcp_stripe_sepa_tests_requested_suite(): ?string {
	$argv = $_SERVER['argv'] ?? array();

	foreach ( $argv as $index => $arg ) {
		if ( '--testsuite' === $arg ) {
			return (string) ( $argv[ $index + 1 ] ?? '' );
		}

		if ( 0 === strpos( $arg, '--testsuite=' ) ) {
			return substr( $arg, strlen( '--testsuite=' ) );
		}
	}

	return null;
}

$rcp_sepa_suite = rcp_stripe_sepa_tests_requested_suite();

/*
 * Les suites ne peuvent pas partager un même processus.
 *
 * La suite « unit » remplace les fonctions de WordPress via Brain Monkey
 * (Patchwork), ce qui exige qu'aucune d'elles ne soit déjà définie. Les autres
 * suites chargent au contraire un WordPress complet. Exécuter les deux dans la
 * même invocation fait échouer Patchwork avec « DefinedTooEarly ».
 *
 * Chaque suite doit donc être lancée séparément — ce que font `make test` et
 * l'intégration continue.
 */
if ( null === $rcp_sepa_suite ) {
	fwrite(
		STDERR,
		"Les suites de tests ne peuvent pas être exécutées dans une même invocation.\n"
		. "Les tests unitaires remplacent les fonctions de WordPress ; les autres suites les chargent.\n\n"
		. "Utilisez : make test\n"
		. "Ou une suite à la fois : vendor/bin/phpunit --testsuite unit|integration|contract|webhooks\n"
	);
	exit( 1 );
}

if ( 'unit' === $rcp_sepa_suite ) {
	return;
}

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wp-tests/lib';

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
 * Localise le fichier principal de Restrict Content Pro, quelle que soit la
 * variante installée.
 *
 * L'ordre reflète la priorité : si les deux sont présentes, la variante
 * commerciale est testée.
 *
 * @return string|null Chemin absolu, ou null si aucune variante n'est présente.
 */
function rcp_stripe_sepa_tests_locate_rcp(): ?string {
	$candidates = array(
		'restrict-content-pro/restrict-content-pro.php',
		'restrict-content/restrictcontent.php',
	);

	foreach ( $candidates as $candidate ) {
		$path = WP_PLUGIN_DIR . '/' . $candidate;

		if ( file_exists( $path ) ) {
			return $path;
		}
	}

	return null;
}

/**
 * Charge Restrict Content Pro puis le plugin avant l'amorçage de WordPress.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () {
		$rcp = rcp_stripe_sepa_tests_locate_rcp();

		if ( null === $rcp ) {
			fwrite(
				STDERR,
				"Aucune variante de Restrict Content Pro trouvée dans " . WP_PLUGIN_DIR . ".\n"
				. "Exécutez : make prepare-tests (RCP_VARIANT=free ou pro)\n"
			);
			exit( 1 );
		}

		require_once $rcp;
		require_once dirname( __DIR__ ) . '/rcp-stripe-sepa.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';

/*
 * Restrict Content Pro crée ses tables sur le hook `admin_init`, qui ne se
 * déclenche pas dans la suite de tests. Sans elles, toute création d'adhésion
 * échoue.
 *
 * Les interfaces de table sont sollicitées directement plutôt que par
 * `do_action( 'admin_init' )`, qui entraînerait tout le reste de
 * l'initialisation de l'administration. Le DDL n'étant pas transactionnel, les
 * tables survivent au rollback opéré entre deux tests.
 */
if ( function_exists( 'rcp_setup_components' ) && function_exists( 'rcp_get_component' ) ) {
	rcp_setup_components();

	$rcp_sepa_components = array( 'customers', 'discounts', 'memberships', 'queue', 'membership_counts', 'logs' );

	foreach ( $rcp_sepa_components as $rcp_sepa_component ) {
		$rcp_sepa_object = rcp_get_component( $rcp_sepa_component );

		if ( ! $rcp_sepa_object ) {
			continue;
		}

		foreach ( array( 'table', 'meta' ) as $rcp_sepa_interface ) {
			$rcp_sepa_table = $rcp_sepa_object->get_interface( $rcp_sepa_interface );

			if ( $rcp_sepa_table && method_exists( $rcp_sepa_table, 'maybe_upgrade' ) ) {
				$rcp_sepa_table->maybe_upgrade();
			}
		}
	}
}
