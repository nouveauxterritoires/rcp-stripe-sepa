#!/usr/bin/env php
<?php
/**
 * Outillage console pour les webhooks Stripe.
 *
 * Permet de rejouer des événements vers le point de terminaison local sans
 * dépendre de la CLI Stripe ni d'un tunnel : les charges utiles sont signées
 * localement avec le même secret que celui configuré dans WordPress, donc la
 * vérification de signature du plugin s'exerce réellement.
 *
 * Usage : php bin/webhook.php <commande> [arguments]
 *
 * @package RCP_Stripe_Sepa
 * @see docs/webhooks-en-local.md
 */

declare( strict_types = 1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/stripe-console.php';

use function RCP_Stripe_Sepa\Console\fail;
use function RCP_Stripe_Sepa\Console\http_request;
use function RCP_Stripe_Sepa\Console\load_env;
use function RCP_Stripe_Sepa\Console\mask;
use function RCP_Stripe_Sepa\Console\out;
use function RCP_Stripe_Sepa\Console\secret_key;
use function RCP_Stripe_Sepa\Console\setting;
use function RCP_Stripe_Sepa\Console\stripe;
use function RCP_Stripe_Sepa\Console\write_env;

const FIXTURES_DIR = __DIR__ . '/../tests/fixtures/webhooks';
const DEFAULT_PATH = '/wp-json/rcp-stripe-sepa/v1/webhook';

/**
 * Secret de signature des webhooks.
 *
 * @param array<string, string> $env Variables d'environnement.
 * @return string
 */
function webhook_secret( array $env ): string {
	$secret = setting( $env, 'STRIPE_WEBHOOK_SECRET' );

	if ( '' === $secret ) {
		fail(
			"STRIPE_WEBHOOK_SECRET absent de .env et de l'environnement.\n"
			. "         Générez-en un pour le développement local : make webhook-secret\n"
			. '         Ou récupérez celui de la CLI Stripe : make stripe-listen'
		);
	}

	return $secret;
}

/**
 * URL du point de terminaison local.
 *
 * @param array<string, string> $env Variables d'environnement.
 * @return string
 */
function endpoint_url( array $env ): string {
	$explicit = setting( $env, 'WEBHOOK_ENDPOINT' );

	if ( '' !== $explicit ) {
		return $explicit;
	}

	return 'http://localhost:' . setting( $env, 'WP_PORT', '8080' ) . DEFAULT_PATH;
}

/**
 * Calcule l'en-tête Stripe-Signature pour une charge utile.
 *
 * Reproduit le schéma décrit par Stripe : HMAC-SHA256 de « timestamp.payload »
 * avec le secret du point de terminaison.
 *
 * @param string $payload   Charge utile JSON brute.
 * @param string $secret    Secret du point de terminaison.
 * @param int    $timestamp Horodatage Unix.
 * @return string
 */
function signature_header( string $payload, string $secret, int $timestamp ): string {
	$signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

	return 't=' . $timestamp . ',v1=' . $signature;
}

/**
 * Résout le chemin d'une fixture à partir d'un nom ou d'un chemin.
 *
 * @param string $name Nom court ou chemin.
 * @return string
 */
function fixture_path( string $name ): string {
	$candidates = array(
		$name,
		FIXTURES_DIR . '/' . $name,
		FIXTURES_DIR . '/' . $name . '.json',
	);

	foreach ( $candidates as $candidate ) {
		if ( is_readable( $candidate ) ) {
			return $candidate;
		}
	}

	fail( sprintf( 'Fixture introuvable : %s. Voir « php bin/webhook.php list ».', $name ) );
}

/**
 * Récupère un événement depuis l'API Stripe.
 *
 * @param string $event_id Identifiant de l'événement.
 * @param string $key      Clé secrète.
 * @return array
 */
function fetch_event( string $event_id, string $key ): array {
	list( $status, $event ) = stripe( $key, 'events/' . rawurlencode( $event_id ) );

	if ( 200 !== $status ) {
		fail(
			sprintf(
				'Stripe a renvoyé %d : %s',
				$status,
				(string) ( $event['error']['message'] ?? 'réponse inattendue' )
			)
		);
	}

	if ( ! empty( $event['livemode'] ) ) {
		fail( 'Cet événement provient du mode production. Rejeu refusé.' );
	}

	return $event;
}

// -- Commandes ----------------------------------------------------------------

/**
 * Génère un secret de webhook pour le développement local.
 *
 * @return void
 */
function command_secret(): void {
	$env = load_env();

	if ( ! is_readable( env_path() ) ) {
		fail( 'Fichier .env introuvable. Copiez .env.example vers .env.' );
	}

	if ( '' !== ( $env['STRIPE_WEBHOOK_SECRET'] ?? '' ) ) {
		out( 'Un secret est déjà configuré : ' . mask( $env['STRIPE_WEBHOOK_SECRET'] ) );
		out( 'Supprimez-le de .env pour en générer un nouveau.' );

		return;
	}

	$secret = 'whsec_' . bin2hex( random_bytes( 24 ) );

	write_env( 'STRIPE_WEBHOOK_SECRET', $secret );

	out( 'Secret de développement généré : ' . mask( $secret ), '0;32' );
	out( '' );
	out( 'Il est écrit dans .env et injecté dans wp-config.php au démarrage du conteneur.' );
	out( 'Redémarrez la pile pour qu\'il soit pris en compte : make down && make up' );
	out( '' );
	out( 'Ce secret n\'a de valeur qu\'en local : il ne correspond à aucun point de' );
	out( 'terminaison Stripe. Pour recevoir de vrais événements, utilisez make stripe-listen.' );
}

/**
 * Liste les fixtures disponibles.
 *
 * @return void
 */
function command_list(): void {
	$files = glob( FIXTURES_DIR . '/*.json' ) ?: array();

	if ( array() === $files ) {
		out( 'Aucune fixture. Enregistrez-en une : php bin/webhook.php capture <evt_id> <nom>' );

		return;
	}

	out( sprintf( '%-34s %-34s %s', 'FIXTURE', 'TYPE', 'ID' ) );

	foreach ( $files as $file ) {
		$event = json_decode( (string) file_get_contents( $file ), true );

		out(
			sprintf(
				'%-34s %-34s %s',
				basename( $file, '.json' ),
				is_array( $event ) ? (string) ( $event['type'] ?? '?' ) : '?',
				is_array( $event ) ? (string) ( $event['id'] ?? '?' ) : '?'
			)
		);
	}
}

/**
 * Affiche l'en-tête de signature d'une fixture, sans l'envoyer.
 *
 * @param string $name Fixture.
 * @return void
 */
function command_sign( string $name ): void {
	$env     = load_env();
	$payload = (string) file_get_contents( fixture_path( $name ) );

	out( signature_header( $payload, webhook_secret( $env ), time() ) );
}

/**
 * Signe une fixture et l'envoie au point de terminaison local.
 *
 * @param string $name    Fixture.
 * @param array  $options Options de la ligne de commande.
 * @return void
 */
function command_send( string $name, array $options ): void {
	$env     = load_env();
	$secret  = webhook_secret( $env );
	$url     = endpoint_url( $env );
	$path    = fixture_path( $name );
	$payload = (string) file_get_contents( $path );

	$event = json_decode( $payload, true );

	if ( ! is_array( $event ) ) {
		fail( 'Fixture illisible : ' . $path );
	}

	// Décalage volontaire de l'horodatage, pour éprouver la tolérance temporelle.
	$timestamp = time() - (int) ( $options['age'] ?? 0 );

	$header = isset( $options['bad-signature'] )
		? signature_header( $payload, 'whsec_' . str_repeat( '0', 48 ), $timestamp )
		: signature_header( $payload, $secret, $timestamp );

	if ( isset( $options['no-signature'] ) ) {
		$headers = array( 'Content-Type: application/json' );
	} else {
		$headers = array( 'Content-Type: application/json', 'Stripe-Signature: ' . $header );
	}

	out( sprintf( 'POST %s', $url ) );
	out( sprintf( 'Événement : %s (%s)', (string) ( $event['type'] ?? '?' ), (string) ( $event['id'] ?? '?' ) ) );

	if ( isset( $options['bad-signature'] ) ) {
		out( 'Signature : volontairement invalide', '0;33' );
	}

	if ( isset( $options['no-signature'] ) ) {
		out( 'Signature : absente', '0;33' );
	}

	if ( ! empty( $options['age'] ) ) {
		out( sprintf( 'Horodatage : %d secondes dans le passé', (int) $options['age'] ), '0;33' );
	}

	list( $status, $body ) = http_request( $url, 'POST', $headers, $payload );

	$color = $status >= 200 && $status < 300 ? '0;32' : '0;31';

	out( '' );
	out( sprintf( 'HTTP %d', $status ), $color );

	if ( '' !== trim( $body ) ) {
		out( substr( trim( $body ), 0, 2000 ) );
	}

	if ( 404 === $status ) {
		out( '' );
		out( 'Le point de terminaison n\'existe pas encore : il est livré au jalon J4.', '0;33' );
	}

	exit( $status >= 200 && $status < 300 ? 0 : 1 );
}

/**
 * Enregistre un événement réel comme fixture.
 *
 * @param string $event_id Identifiant de l'événement.
 * @param string $name     Nom de la fixture.
 * @return void
 */
function command_capture( string $event_id, string $name ): void {
	$env   = load_env();
	$event = fetch_event( $event_id, secret_key( $env ) );

	if ( ! is_dir( FIXTURES_DIR ) ) {
		mkdir( FIXTURES_DIR, 0755, true );
	}

	$target = FIXTURES_DIR . '/' . preg_replace( '/[^a-z0-9._-]/i', '-', $name ) . '.json';

	file_put_contents(
		$target,
		json_encode( $event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL
	);

	out( sprintf( 'Fixture enregistrée : %s', $target ), '0;32' );
	out( sprintf( 'Type : %s', (string) ( $event['type'] ?? '?' ) ) );
}

/**
 * Récupère un événement réel et le rejoue localement.
 *
 * @param string $event_id Identifiant de l'événement.
 * @param array  $options  Options de la ligne de commande.
 * @return void
 */
function command_replay( string $event_id, array $options ): void {
	$env   = load_env();
	$event = fetch_event( $event_id, secret_key( $env ) );

	$temp = tempnam( sys_get_temp_dir(), 'rcp-sepa-evt' );

	file_put_contents(
		$temp,
		json_encode( $event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);

	command_send( $temp, $options );
}

/**
 * Liste les derniers événements du compte Stripe de test.
 *
 * @param array $options Options de la ligne de commande.
 * @return void
 */
function command_events( array $options ): void {
	$env   = load_env();
	$query = array( 'limit' => (string) ( $options['limit'] ?? 20 ) );

	if ( ! empty( $options['type'] ) ) {
		$query['type'] = (string) $options['type'];
	}

	list( $status, $decoded ) = stripe( secret_key( $env ), 'events?' . http_build_query( $query ) );

	if ( 200 !== $status || ! isset( $decoded['data'] ) ) {
		fail( sprintf( 'Stripe a renvoyé %d.', $status ) );
	}

	if ( array() === $decoded['data'] ) {
		out( 'Aucun événement sur ce compte de test.' );

		return;
	}

	out( sprintf( '%-30s %-38s %s', 'ID', 'TYPE', 'DATE' ) );

	foreach ( $decoded['data'] as $event ) {
		out(
			sprintf(
				'%-30s %-38s %s',
				(string) $event['id'],
				(string) $event['type'],
				gmdate( 'Y-m-d H:i:s', (int) $event['created'] )
			)
		);
	}
}

/**
 * Affiche l'aide.
 *
 * @return void
 */
function command_help(): void {
	out( 'Outillage console des webhooks Stripe — rcp-stripe-sepa' );
	out( '' );
	out( 'Usage : php bin/webhook.php <commande> [arguments] [options]' );
	out( '' );
	out( 'Commandes' );
	out( '  secret                        Génère un secret de webhook pour le développement local' );
	out( '  list                          Liste les fixtures enregistrées' );
	out( '  send <fixture>                Signe une fixture et l\'envoie au point de terminaison local' );
	out( '  sign <fixture>                Affiche l\'en-tête Stripe-Signature sans envoyer' );
	out( '  events [--type=] [--limit=]   Liste les derniers événements du compte Stripe de test' );
	out( '  capture <evt_id> <nom>        Enregistre un événement réel comme fixture' );
	out( '  replay <evt_id>               Récupère un événement réel et le rejoue localement' );
	out( '' );
	out( 'Options de send et replay' );
	out( '  --bad-signature               Signe avec un mauvais secret (doit produire un 400)' );
	out( '  --no-signature                N\'envoie aucune signature (doit produire un 400)' );
	out( '  --age=<secondes>              Antidate la signature (éprouve la tolérance temporelle)' );
	out( '' );
	out( 'Variables lues dans .env : STRIPE_TEST_SECRET_KEY, STRIPE_WEBHOOK_SECRET, WP_PORT.' );
	out( 'WEBHOOK_ENDPOINT permet de viser une autre URL.' );
}

// -- Répartition --------------------------------------------------------------

$arguments = array_slice( $argv, 1 );
$options   = array();
$positional = array();

foreach ( $arguments as $argument ) {
	if ( 0 === strpos( $argument, '--' ) ) {
		$pair = explode( '=', substr( $argument, 2 ), 2 );

		$options[ $pair[0] ] = $pair[1] ?? true;

		continue;
	}

	$positional[] = $argument;
}

$command = $positional[0] ?? 'help';

switch ( $command ) {
	case 'secret':
		command_secret();
		break;

	case 'list':
		command_list();
		break;

	case 'sign':
		isset( $positional[1] ) || fail( 'Usage : php bin/webhook.php sign <fixture>' );
		command_sign( $positional[1] );
		break;

	case 'send':
		isset( $positional[1] ) || fail( 'Usage : php bin/webhook.php send <fixture>' );
		command_send( $positional[1], $options );
		break;

	case 'capture':
		isset( $positional[2] ) || fail( 'Usage : php bin/webhook.php capture <evt_id> <nom>' );
		command_capture( $positional[1], $positional[2] );
		break;

	case 'replay':
		isset( $positional[1] ) || fail( 'Usage : php bin/webhook.php replay <evt_id>' );
		command_replay( $positional[1], $options );
		break;

	case 'events':
		command_events( $options );
		break;

	default:
		command_help();
}
