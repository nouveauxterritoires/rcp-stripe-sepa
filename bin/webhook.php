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

const FIXTURES_DIR = __DIR__ . '/../tests/fixtures/webhooks';
const STRIPE_API   = 'https://api.stripe.com/v1';
const DEFAULT_PATH = '/wp-json/rcp-stripe-sepa/v1/webhook';

/**
 * Écrit un message sur la sortie standard.
 *
 * @param string $message Message.
 * @param string $color   Code couleur ANSI.
 * @return void
 */
function out( string $message, string $color = '' ): void {
	$prefix = '' !== $color ? "\033[{$color}m" : '';
	$suffix = '' !== $color ? "\033[0m" : '';

	fwrite( STDOUT, $prefix . $message . $suffix . PHP_EOL );
}

/**
 * Interrompt l'exécution avec un message d'erreur.
 *
 * @param string $message Message.
 * @return never
 */
function fail( string $message ) {
	fwrite( STDERR, "\033[0;31mErreur :\033[0m " . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Masque une valeur sensible pour l'affichage.
 *
 * @param string $value Valeur.
 * @return string
 */
function mask( string $value ): string {
	if ( strlen( $value ) <= 12 ) {
		return str_repeat( '*', strlen( $value ) );
	}

	return substr( $value, 0, 8 ) . str_repeat( '*', 8 ) . substr( $value, -4 );
}

/**
 * Chemin du fichier .env.
 *
 * @return string
 */
function env_path(): string {
	return __DIR__ . '/../.env';
}

/**
 * Charge les variables du fichier .env.
 *
 * `parse_ini_file()` n'est pas utilisée : elle échoue sur les commentaires
 * contenant des parenthèses.
 *
 * L'absence de .env n'est pas une erreur : les variables peuvent provenir de
 * l'environnement, ce dont dépendent l'intégration continue et les tests.
 *
 * @return array<string, string>
 */
function load_env(): array {
	$path = env_path();

	if ( ! is_readable( $path ) ) {
		return array();
	}

	$vars = array();

	foreach ( file( $path, FILE_IGNORE_NEW_LINES ) as $line ) {
		$line = trim( $line );

		if ( '' === $line || '#' === $line[0] || false === strpos( $line, '=' ) ) {
			continue;
		}

		list( $key, $value ) = explode( '=', $line, 2 );

		$vars[ trim( $key ) ] = trim( $value, " \"'" );
	}

	return $vars;
}

/**
 * Valeur d'un réglage, l'environnement primant sur le fichier .env.
 *
 * @param array<string, string> $env      Variables lues dans .env.
 * @param string                $key      Nom du réglage.
 * @param string                $fallback Valeur par défaut.
 * @return string
 */
function setting( array $env, string $key, string $fallback = '' ): string {
	$from_environment = getenv( $key );

	if ( is_string( $from_environment ) && '' !== $from_environment ) {
		return $from_environment;
	}

	return '' !== ( $env[ $key ] ?? '' ) ? $env[ $key ] : $fallback;
}

/**
 * Enregistre une variable dans .env, en remplaçant la ligne existante.
 *
 * @param string $key   Nom de la variable.
 * @param string $value Valeur.
 * @return void
 */
function write_env( string $key, string $value ): void {
	$path    = env_path();
	$lines   = file( $path, FILE_IGNORE_NEW_LINES );
	$written = false;

	foreach ( $lines as $index => $line ) {
		if ( 0 === strpos( trim( $line ), $key . '=' ) ) {
			$lines[ $index ] = $key . '=' . $value;
			$written         = true;
			break;
		}
	}

	if ( ! $written ) {
		$lines[] = $key . '=' . $value;
	}

	file_put_contents( $path, implode( PHP_EOL, $lines ) . PHP_EOL );
}

/**
 * Clé secrète Stripe, en refusant toute clé de production.
 *
 * @param array<string, string> $env Variables d'environnement.
 * @return string
 */
function secret_key( array $env ): string {
	$key = setting( $env, 'STRIPE_TEST_SECRET_KEY' );

	if ( '' === $key ) {
		fail( 'STRIPE_TEST_SECRET_KEY absente de .env et de l\'environnement.' );
	}

	// Garde-fou : cet outil rejoue des événements, il ne doit jamais viser
	// un compte de production.
	if ( 0 === strpos( $key, 'sk_live_' ) || 0 === strpos( $key, 'rk_live_' ) ) {
		fail( 'Clé de production détectée. Cet outil est réservé au mode test.' );
	}

	return $key;
}

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
 * Exécute une requête HTTP et renvoie le code et le corps de la réponse.
 *
 * @param string   $url     URL.
 * @param string   $method  Méthode HTTP.
 * @param string[] $headers En-têtes.
 * @param string   $body    Corps de la requête.
 * @return array{0: int, 1: string}
 */
function http_request( string $url, string $method, array $headers, string $body = '' ): array {
	$handle = curl_init( $url );

	curl_setopt_array(
		$handle,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => 30,
		)
	);

	if ( '' !== $body ) {
		curl_setopt( $handle, CURLOPT_POSTFIELDS, $body );
	}

	$response = curl_exec( $handle );
	$status   = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
	$error    = curl_error( $handle );

	curl_close( $handle );

	if ( false === $response ) {
		fail( 'Requête HTTP échouée : ' . $error );
	}

	return array( $status, (string) $response );
}

/**
 * Récupère un événement depuis l'API Stripe.
 *
 * @param string $event_id Identifiant de l'événement.
 * @param string $key      Clé secrète.
 * @return array
 */
function fetch_event( string $event_id, string $key ): array {
	list( $status, $body ) = http_request(
		STRIPE_API . '/events/' . rawurlencode( $event_id ),
		'GET',
		array( 'Authorization: Bearer ' . $key )
	);

	$decoded = json_decode( $body, true );

	if ( 200 !== $status || ! is_array( $decoded ) ) {
		$message = is_array( $decoded ) && isset( $decoded['error']['message'] )
			? $decoded['error']['message']
			: 'réponse inattendue';

		fail( sprintf( 'Stripe a renvoyé %d : %s', $status, $message ) );
	}

	if ( ! empty( $decoded['livemode'] ) ) {
		fail( 'Cet événement provient du mode production. Rejeu refusé.' );
	}

	return $decoded;
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

	list( $status, $body ) = http_request(
		STRIPE_API . '/events?' . http_build_query( $query ),
		'GET',
		array( 'Authorization: Bearer ' . secret_key( $env ) )
	);

	$decoded = json_decode( $body, true );

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
