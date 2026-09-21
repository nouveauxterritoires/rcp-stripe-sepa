<?php
/**
 * Fonctions partagées par les outils console.
 *
 * Ce fichier n'est jamais chargé par WordPress : il ne sert qu'aux scripts de
 * `bin/`, exécutés en ligne de commande pendant le développement.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Console;

const STRIPE_API = 'https://api.stripe.com/v1';

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
 * @return void
 */
function fail( string $message ): void {
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
	return dirname( __DIR__, 2 ) . '/.env';
}

/**
 * Charge les variables du fichier .env.
 *
 * `parse_ini_file()` n'est pas utilisée : elle échoue sur les commentaires
 * contenant des parenthèses. L'absence du fichier n'est pas une erreur — les
 * variables peuvent venir de l'environnement, ce dont dépend l'intégration
 * continue.
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
 * Ces outils créent et rejouent des objets : ils ne doivent jamais viser un
 * compte de production.
 *
 * @param array<string, string> $env Variables d'environnement.
 * @return string
 */
function secret_key( array $env ): string {
	$key = setting( $env, 'STRIPE_TEST_SECRET_KEY' );

	if ( '' === $key ) {
		fail( 'STRIPE_TEST_SECRET_KEY absente de .env et de l\'environnement.' );
	}

	if ( 0 === strpos( $key, 'sk_live_' ) || 0 === strpos( $key, 'rk_live_' ) ) {
		fail( 'Clé de production détectée. Ces outils sont réservés au mode test.' );
	}

	return $key;
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
 * Appelle l'API Stripe.
 *
 * @param string     $key    Clé secrète.
 * @param string     $path   Chemin, éventuellement suivi d'une chaîne de requête.
 * @param string     $method Méthode HTTP.
 * @param array|null $params Paramètres du corps.
 * @return array{0: int, 1: array}
 */
function stripe( string $key, string $path, string $method = 'GET', ?array $params = null ): array {
	$body = null === $params
		? ''
		: http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

	list( $status, $response ) = http_request(
		STRIPE_API . '/' . $path,
		$method,
		array(
			'Authorization: Bearer ' . $key,
			'Content-Type: application/x-www-form-urlencoded',
		),
		$body
	);

	$decoded = json_decode( $response, true );

	return array( $status, is_array( $decoded ) ? $decoded : array() );
}
