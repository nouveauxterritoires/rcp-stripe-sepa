#!/usr/bin/env php
<?php
/**
 * Outillage console du compte Stripe de test.
 *
 * Usage : php bin/stripe.php <commande> [options]
 *
 * @package RCP_Stripe_Sepa
 * @see docs/environnement-stripe-test.md
 */

declare( strict_types = 1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/stripe-console.php';

use function RCP_Stripe_Sepa\Console\fail;
use function RCP_Stripe_Sepa\Console\load_env;
use function RCP_Stripe_Sepa\Console\out;
use function RCP_Stripe_Sepa\Console\secret_key;
use function RCP_Stripe_Sepa\Console\setting;
use function RCP_Stripe_Sepa\Console\stripe;

/**
 * IBAN de test Stripe et comportement associé.
 *
 * @link https://docs.stripe.com/payments/sepa-debit/accept-a-payment#test-integration
 */
const SCENARIOS = array(
	'success'      => array(
		'iban'        => 'FR1420041010050500013M02606',
		'description' => 'processing puis succeeded',
	),
	'failed'       => array(
		'iban'        => 'FR8420041010050500013M02607',
		'description' => 'processing puis requires_payment_method',
	),
	'disputed'     => array(
		'iban'        => 'FR5720041010050500013M02608',
		'description' => 'succeeded, puis litige immédiat',
	),
	'insufficient' => array(
		'iban'        => 'FR9720041010050000002222227',
		'description' => 'échec pour fonds insuffisants',
	),
);

/**
 * Étiquette utilisée pour reconnaître les objets créés par cet outil.
 */
const SEED_TAG = 'rcp_stripe_sepa_seed';

// -- doctor -------------------------------------------------------------------

/**
 * Vérifie que l'environnement de test est exploitable.
 *
 * @return void
 */
function command_doctor(): void {
	$env      = load_env();
	$key      = secret_key( $env );
	$failures = 0;

	/**
	 * Affiche le résultat d'un contrôle.
	 *
	 * @param bool   $ok      Contrôle satisfait.
	 * @param string $label   Intitulé.
	 * @param string $detail  Détail affiché.
	 * @param bool   $blocking Le contrôle est-il bloquant.
	 * @return bool
	 */
	$check = static function ( bool $ok, string $label, string $detail = '', bool $blocking = true ) use ( &$failures ): bool {
		if ( $ok ) {
			out( sprintf( '  ✔ %-46s %s', $label, $detail ), '0;32' );

			return true;
		}

		if ( $blocking ) {
			++$failures;
			out( sprintf( '  ✘ %-46s %s', $label, $detail ), '0;31' );
		} else {
			out( sprintf( '  ! %-46s %s', $label, $detail ), '0;33' );
		}

		return false;
	};

	out( 'Compte Stripe', '1;37' );

	list( $status, $account ) = stripe( $key, 'account' );

	if ( 200 !== $status ) {
		fail( 'Impossible de lire le compte Stripe (HTTP ' . $status . ').' );
	}

	$check( true, 'Compte accessible', (string) $account['id'] );
	$check( 0 === strpos( $key, 'sk_test_' ) || 0 === strpos( $key, 'rk_test_' ), 'Clé de test', 'mode test requis' );
	$check( 'EUR' === strtoupper( (string) ( $account['default_currency'] ?? '' ) ), 'Devise par défaut', strtoupper( (string) ( $account['default_currency'] ?? '?' ) ) . ' — le prélèvement SEPA exige EUR' );
	$check(
		in_array( strtoupper( (string) ( $account['country'] ?? '' ) ), array( 'FR', 'DE', 'ES', 'IT', 'BE', 'NL', 'AT', 'PT', 'IE', 'LU', 'FI' ), true ),
		'Pays du compte',
		(string) ( $account['country'] ?? '?' )
	);

	out( '' );
	out( 'Prélèvement SEPA', '1;37' );

	// Sonde réelle : en mode test, les capacités « inactive » du compte ne
	// bloquent pas la création d'un PaymentIntent. Seul l'appel fait foi.
	list( $probe_status, $probe ) = stripe(
		$key,
		'payment_intents',
		'POST',
		array(
			'amount'               => 1000,
			'currency'             => 'eur',
			'payment_method_types' => array( 'sepa_debit' ),
			'metadata'             => array( SEED_TAG => 'doctor' ),
		)
	);

	if ( $check( 200 === $probe_status, 'Création d\'un PaymentIntent SEPA', 200 === $probe_status ? 'possible' : (string) ( $probe['error']['message'] ?? 'refusée' ) ) ) {
		stripe( $key, 'payment_intents/' . $probe['id'] . '/cancel', 'POST' );
	}

	$capability = (string) ( $account['capabilities']['sepa_debit_payments'] ?? 'absente' );

	$check(
		'active' === $capability,
		'Capacité sepa_debit_payments',
		$capability . ' — sans effet en mode test, requise pour la production',
		false
	);

	out( '' );
	out( 'Environnement local', '1;37' );

	$check( '' !== setting( $env, 'STRIPE_TEST_PUBLISHABLE_KEY' ), 'Clé publiable', 'STRIPE_TEST_PUBLISHABLE_KEY' );
	$check( '' !== setting( $env, 'STRIPE_WEBHOOK_SECRET' ), 'Secret de webhook', 'make webhook-secret' );

	$site = 'http://localhost:' . setting( $env, 'WP_PORT', '8080' );

	$headers = @get_headers( $site . '/wp-login.php' );

	$check( false !== $headers, 'Site WordPress joignable', $site );

	out( '' );

	if ( $failures > 0 ) {
		out( sprintf( '%d contrôle(s) bloquant(s) en échec.', $failures ), '0;31' );
		exit( 1 );
	}

	out( 'Environnement de test exploitable.', '0;32' );
}

// -- seed ---------------------------------------------------------------------

/**
 * Crée un scénario de paiement SEPA dans le compte de test.
 *
 * @param string $name    Nom du scénario.
 * @param array  $options Options de la ligne de commande.
 * @return void
 */
function command_seed( string $name, array $options ): void {
	if ( ! isset( SCENARIOS[ $name ] ) ) {
		fail( 'Scénario inconnu : ' . $name . '. Disponibles : ' . implode( ', ', array_keys( SCENARIOS ) ) );
	}

	$env      = load_env();
	$key      = secret_key( $env );
	$scenario = SCENARIOS[ $name ];

	out( sprintf( 'Scénario « %s » — %s', $name, $scenario['description'] ), '1;37' );

	$customer       = create( $key, 'customers', array(
		'email'    => 'membre+' . $name . '@example.test',
		'name'     => 'Membre Test ' . $name,
		'metadata' => array( SEED_TAG => $name ),
	), 'Client' );

	$payment_method = create( $key, 'payment_methods', array(
		'type'            => 'sepa_debit',
		'sepa_debit'      => array( 'iban' => $scenario['iban'] ),
		'billing_details' => array(
			'name'  => 'Membre Test ' . $name,
			'email' => 'membre+' . $name . '@example.test',
		),
		'metadata'        => array( SEED_TAG => $name ),
	), 'Moyen de paiement' );

	out( sprintf( '    IBAN se terminant par %s (%s)', (string) $payment_method['sepa_debit']['last4'], (string) $payment_method['sepa_debit']['country'] ) );

	if ( isset( $options['subscription'] ) ) {
		seed_subscription( $key, $customer, $payment_method, $name );

		return;
	}

	$intent = create( $key, 'payment_intents', array(
		'amount'               => (int) ( $options['amount'] ?? 1000 ),
		'currency'             => 'eur',
		'customer'             => $customer['id'],
		'payment_method'       => $payment_method['id'],
		'payment_method_types' => array( 'sepa_debit' ),
		'confirm'              => 'true',
		'description'          => 'Adhésion de test — ' . $name,
		'mandate_data'         => array( 'customer_acceptance' => array( 'type' => 'offline' ) ),
		'metadata'             => array( SEED_TAG => $name ),
	), 'PaymentIntent' );

	out( sprintf( '    statut : %s', (string) $intent['status'] ), 'processing' === $intent['status'] ? '0;32' : '0;33' );

	if ( ! empty( $intent['latest_charge'] ) ) {
		// Les charges SEPA portent un identifiant `py_`, non `ch_`.
		out( sprintf( '    charge : %s', (string) $intent['latest_charge'] ) );
	}

	out( '' );
	out( 'Événements générés : php bin/webhook.php events --limit=10' );
}

/**
 * Crée un abonnement récurrent réglé par prélèvement SEPA.
 *
 * @param string $key            Clé secrète.
 * @param array  $customer       Client Stripe.
 * @param array  $payment_method Moyen de paiement.
 * @param string $name           Nom du scénario.
 * @return void
 */
function seed_subscription( string $key, array $customer, array $payment_method, string $name ): void {
	create( $key, 'payment_methods/' . $payment_method['id'] . '/attach', array(
		'customer' => $customer['id'],
	), 'Rattachement du moyen de paiement' );

	$price = create( $key, 'prices', array(
		'unit_amount'  => 1000,
		'currency'     => 'eur',
		'recurring'    => array( 'interval' => 'month' ),
		'product_data' => array( 'name' => 'Adhésion mensuelle de test' ),
		'metadata'     => array( SEED_TAG => $name ),
	), 'Tarif récurrent' );

	$subscription = create( $key, 'subscriptions', array(
		'customer'               => $customer['id'],
		'items'                  => array( array( 'price' => $price['id'] ) ),
		'default_payment_method' => $payment_method['id'],
		'payment_settings'       => array( 'payment_method_types' => array( 'sepa_debit' ) ),
		'expand'                 => array( 'latest_invoice.payment_intent' ),
		'metadata'               => array( SEED_TAG => $name ),
	), 'Abonnement' );

	$intent = $subscription['latest_invoice']['payment_intent'] ?? null;

	if ( ! is_array( $intent ) ) {
		out( '    aucun PaymentIntent sur la première facture' );

		return;
	}

	/*
	 * Contrairement à la carte, un abonnement SEPA naît en
	 * `requires_confirmation` : le mandat doit être accepté explicitement. En
	 * production cette confirmation a lieu côté navigateur, via
	 * `stripe.confirmSepaDebitPayment()`. Ici, l'acceptation « offline »
	 * reproduit la même transition côté serveur.
	 */
	if ( 'requires_confirmation' === $intent['status'] ) {
		$intent = create(
			$key,
			'payment_intents/' . $intent['id'] . '/confirm',
			array( 'mandate_data' => array( 'customer_acceptance' => array( 'type' => 'offline' ) ) ),
			'Confirmation du mandat'
		);
	}

	list( , $refreshed ) = stripe( $key, 'subscriptions/' . $subscription['id'] );

	out( '' );
	out( sprintf( '    abonnement    : %s', (string) ( $refreshed['status'] ?? $subscription['status'] ) ) );
	out( sprintf( '    PaymentIntent : %s', (string) $intent['status'] ) );

	if ( 'active' === ( $refreshed['status'] ?? '' ) && 'succeeded' !== $intent['status'] ) {
		out( '' );
		out( 'Attention : l\'abonnement est « active » alors que le prélèvement n\'a pas abouti.', '0;33' );
		out( 'C\'est le comportement normal de SEPA. Le statut d\'abonnement ne doit donc', '0;33' );
		out( 'jamais suffire à ouvrir l\'accès au contenu (cahier des charges RG-01).', '0;33' );
	}

	out( '' );
	out( 'Événements générés : php bin/webhook.php events --limit=15' );
}

/**
 * Crée un objet Stripe et interrompt en cas d'échec.
 *
 * @param string $key    Clé secrète.
 * @param string $path   Chemin d'API.
 * @param array  $params Paramètres.
 * @param string $label  Intitulé affiché.
 * @return array
 */
function create( string $key, string $path, array $params, string $label ): array {
	list( $status, $body ) = stripe( $key, $path, 'POST', $params );

	if ( 200 !== $status ) {
		fail( sprintf( '%s : %s', $label, (string) ( $body['error']['message'] ?? 'HTTP ' . $status ) ) );
	}

	out( sprintf( '  %-34s %s', $label, (string) ( $body['id'] ?? '' ) ), '0;32' );

	return $body;
}

// -- clean --------------------------------------------------------------------

/**
 * Annule les PaymentIntents de test restés en attente de moyen de paiement.
 *
 * @return void
 */
function command_clean(): void {
	$env = load_env();
	$key = secret_key( $env );

	list( $status, $body ) = stripe( $key, 'payment_intents?limit=100' );

	if ( 200 !== $status ) {
		fail( 'Lecture des PaymentIntents impossible (HTTP ' . $status . ').' );
	}

	$cancelled = 0;

	foreach ( $body['data'] as $intent ) {
		if ( 'requires_payment_method' !== $intent['status'] ) {
			continue;
		}

		stripe( $key, 'payment_intents/' . $intent['id'] . '/cancel', 'POST' );

		out( sprintf( '  annulé %s', (string) $intent['id'] ) );

		++$cancelled;
	}

	out( sprintf( '%d PaymentIntent(s) inachevé(s) annulé(s).', $cancelled ), '0;32' );
	out( '' );
	out( 'Les objets Stripe de test ne peuvent pas être supprimés par l\'API.' );
	out( 'Pour repartir de zéro : Dashboard → Developers → « Delete all test data ».' );
}

// -- Aide ---------------------------------------------------------------------

/**
 * Affiche l'aide.
 *
 * @return void
 */
function command_help(): void {
	out( 'Outillage du compte Stripe de test — rcp-stripe-sepa' );
	out( '' );
	out( 'Usage : php bin/stripe.php <commande> [options]' );
	out( '' );
	out( 'Commandes' );
	out( '  doctor                    Vérifie que l\'environnement de test est exploitable' );
	out( '  seed <scénario>           Crée un parcours de paiement SEPA de test' );
	out( '  clean                     Annule les PaymentIntents restés inachevés' );
	out( '' );
	out( 'Scénarios' );

	foreach ( SCENARIOS as $name => $scenario ) {
		out( sprintf( '  %-14s %s', $name, $scenario['description'] ) );
	}

	out( '' );
	out( 'Options de seed' );
	out( '  --subscription            Crée un abonnement récurrent au lieu d\'un paiement unique' );
	out( '  --amount=<centimes>       Montant du paiement unique (défaut : 1000)' );
}

// -- Répartition --------------------------------------------------------------

$options    = array();
$positional = array();

foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( 0 === strpos( $argument, '--' ) ) {
		$pair = explode( '=', substr( $argument, 2 ), 2 );

		$options[ $pair[0] ] = $pair[1] ?? true;

		continue;
	}

	$positional[] = $argument;
}

switch ( $positional[0] ?? 'help' ) {
	case 'doctor':
		command_doctor();
		break;

	case 'seed':
		command_seed( $positional[1] ?? 'success', $options );
		break;

	case 'clean':
		command_clean();
		break;

	default:
		command_help();
}
