/**
 * Utilitaires partagés par les tests de bout en bout.
 *
 * @package RCP_Stripe_Sepa
 */

const { expect } = require( '@playwright/test' );
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '../..' );

/**
 * IBAN de test Stripe et comportement associé.
 *
 * @see docs/environnement-stripe-test.md
 */
const IBAN = {
	success: 'FR1420041010050500013M02606',
	failure: 'FR8420041010050500013M02607',
};

/**
 * Exécute du PHP dans le conteneur WordPress déjà démarré.
 *
 * `docker compose exec` est préféré à `run` : ce dernier crée un conteneur par
 * appel, ce qui ajouterait une dizaine de secondes à chaque étape de test.
 *
 * Les avertissements de RCP — requêtes SQL invalides de son contrôle de
 * prérequis — polluent la sortie : seule la dernière ligne utile est retenue.
 *
 * @param {string} code Code PHP à évaluer.
 * @return {string} Sortie de la commande.
 */
function wpEval( code ) {
	const output = execFileSync(
		'docker',
		[
			'compose', 'exec', '-T', 'wordpress',
			'wp', '--path=/var/www/html', '--allow-root', 'eval', code,
		],
		{ cwd: ROOT, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] }
	);

	return output
		.split( '\n' )
		.map( ( line ) => line.trim() )
		.filter( ( line ) => line !== '' && ! line.startsWith( '[' ) && ! line.includes( 'database error' ) )
		.pop() || '';
}

/**
 * Rejoue un événement de webhook signé.
 *
 * @param {string} fixture Nom de la fixture.
 * @return {string} Sortie de la commande.
 */
function sendWebhook( fixture ) {
	return execFileSync(
		'php',
		[ 'bin/webhook.php', 'send', fixture ],
		{ cwd: ROOT, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] }
	);
}

/**
 * Crée un adhérent de test et renvoie ses identifiants.
 *
 * @return {{login: string, password: string, email: string}}
 */
function createMember() {
	const suffix = Math.random().toString( 36 ).slice( 2, 10 );
	const login = `e2e_${ suffix }`;
	const email = `${ login }@example.test`;

	const created = wpEval(
		`$id = wp_insert_user( array( "user_login" => "${ login }", "user_pass" => "${ login }", "user_email" => "${ email }", "role" => "subscriber" ) );
		 echo is_wp_error( $id ) ? "ERREUR" : $id;`
	);

	if ( 'ERREUR' === created || '' === created ) {
		throw new Error( `Création de l'utilisateur ${ login } impossible.` );
	}

	return { login, password: login, email, id: Number( created ) };
}

/**
 * Lit le statut de la dernière adhésion d'un membre.
 *
 * @param {string} email Adresse du membre.
 * @return {string} Statut, ou une chaîne vide.
 */
function membershipStatus( email ) {
	return wpEval(
		`do_action( "admin_init" );
		 $user = get_user_by( "email", "${ email }" );
		 if ( ! $user ) { echo ""; return; }
		 $customer = rcp_get_customer_by_user_id( $user->ID );
		 if ( ! $customer ) { echo ""; return; }
		 $memberships = rcp_get_customer_memberships( $customer->get_id() );
		 if ( ! $memberships ) { echo ""; return; }
		 $m = reset( $memberships );
		 echo $m->get_status();`
	);
}

/**
 * Connecte un utilisateur.
 *
 * @param {import('@playwright/test').Page} page     Page Playwright.
 * @param {string}                          login    Identifiant.
 * @param {string}                          password Mot de passe.
 * @return {Promise<void>}
 */
async function logIn( page, login, password ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', login );
	await page.fill( '#user_pass', password );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'networkidle' );
}

/**
 * Saisit un IBAN dans l'élément Stripe.
 *
 * L'IBAN est servi dans une iframe par Stripe : il ne peut pas être rempli
 * comme un champ ordinaire, et c'est précisément ce qui garantit qu'il
 * n'atteint jamais le serveur WordPress.
 *
 * @param {import('@playwright/test').Page} page      Page Playwright.
 * @param {string}                          container Sélecteur du conteneur.
 * @param {string}                          iban      IBAN à saisir.
 * @return {Promise<void>}
 */
async function fillIban( page, container, iban ) {
	const frame = page.frameLocator( `${ container } iframe` ).first();

	// Stripe n'expose pas de `name` sur son champ : seul le nom accessible est
	// stable d'une version à l'autre de l'élément.
	const field = frame.getByRole( 'textbox', { name: 'IBAN' } );

	await field.waitFor( { state: 'visible', timeout: 30_000 } );
	await field.click();
	await field.pressSequentially( iban, { delay: 30 } );

	await expect( field ).not.toHaveValue( '' );
}

/**
 * Attend qu'une adhésion atteigne le statut voulu.
 *
 * L'attente porte sur le résultat métier, et non sur l'inactivité du réseau :
 * Stripe.js maintient des connexions ouvertes, si bien que `networkidle` ne se
 * déclenche jamais sur une page qui l'utilise.
 *
 * @param {string} email     Adresse du membre.
 * @param {string} expected  Statut attendu.
 * @param {number} timeoutMs Délai maximal.
 * @return {Promise<string>} Statut observé.
 */
async function waitForMembership( email, expected, timeoutMs = 60_000 ) {
	const deadline = Date.now() + timeoutMs;
	let observed = '';

	while ( Date.now() < deadline ) {
		observed = membershipStatus( email );

		if ( observed === expected ) {
			return observed;
		}

		await new Promise( ( resolve ) => setTimeout( resolve, 2_000 ) );
	}

	return observed;
}

/**
 * Rejoue un événement Stripe construit pour une adhésion donnée.
 *
 * @param {string} type         Type d'événement.
 * @param {number} membershipId Identifiant de l'adhésion visée.
 * @param {Object} objectExtra  Champs ajoutés à l'objet de l'événement.
 * @return {string} Sortie de la commande.
 */
function sendEventFor( type, membershipId, objectExtra = {} ) {
	const event = {
		id: `evt_e2e_${ Math.random().toString( 36 ).slice( 2, 12 ) }`,
		object: 'event',
		api_version: '2020-08-27',
		created: Math.floor( Date.now() / 1000 ),
		livemode: false,
		type,
		data: {
			object: Object.assign(
				{
					id: `pi_e2e_${ membershipId }`,
					object: 'payment_intent',
					amount: 1000,
					currency: 'eur',
					status: 'succeeded',
					payment_method_types: [ 'sepa_debit' ],
					metadata: { rcp_membership_id: String( membershipId ) },
				},
				objectExtra
			),
		},
	};

	const file = path.join( os.tmpdir(), `${ event.id }.json` );

	fs.writeFileSync( file, JSON.stringify( event ) );

	try {
		return execFileSync( 'php', [ 'bin/webhook.php', 'send', file ], {
			cwd: ROOT,
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
		} );
	} finally {
		fs.unlinkSync( file );
	}
}

/**
 * Identifiant de la dernière adhésion d'un membre.
 *
 * @param {string} email Adresse du membre.
 * @return {number} Identifiant, ou 0.
 */
function membershipId( email ) {
	const value = wpEval(
		`do_action( "admin_init" );
		 $user = get_user_by( "email", "${ email }" );
		 if ( ! $user ) { echo 0; return; }
		 $customer = rcp_get_customer_by_user_id( $user->ID );
		 if ( ! $customer ) { echo 0; return; }
		 $memberships = rcp_get_customer_memberships( $customer->get_id() );
		 echo $memberships ? reset( $memberships )->get_id() : 0;`
	);

	return Number( value ) || 0;
}

/**
 * Crée un client Stripe de test et renvoie son identifiant.
 *
 * La migration part d'un client existant : un identifiant inventé ferait
 * échouer la création du SetupIntent avant même le formulaire.
 *
 * @param {string} email Adresse à rattacher.
 * @return {string} Identifiant du client Stripe.
 */
function createStripeCustomer( email ) {
	const code = `
		require "bin/lib/stripe-console.php";
		$key = RCP_Stripe_Sepa\\Console\\secret_key( RCP_Stripe_Sepa\\Console\\load_env() );
		list( $status, $customer ) = RCP_Stripe_Sepa\\Console\\stripe( $key, "customers", "POST", array( "email" => "${ email }" ) );
		echo 200 === $status ? $customer["id"] : "ERREUR";
	`;

	const id = execFileSync( 'php', [ '-r', code ], {
		cwd: ROOT,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();

	if ( ! id.startsWith( 'cus_' ) ) {
		throw new Error( `Création du client Stripe impossible : ${ id }` );
	}

	return id;
}

/**
 * Crée une adhésion active réglée par carte, rattachée à un client Stripe réel.
 *
 * @param {number} userId     Utilisateur WordPress.
 * @param {string} customerId Client Stripe.
 * @return {number} Identifiant de l'adhésion.
 */
function createCardMembership( userId, customerId ) {
	const value = wpEval(
		`do_action( "admin_init" );
		 $customer = rcp_get_customer_by_user_id( ${ userId } );
		 $customer_id = $customer ? $customer->get_id() : rcp_add_customer( array( "user_id" => ${ userId } ) );
		 $levels = rcp_get_membership_levels( array( "number" => 1 ) );
		 $id = rcp_add_membership( array(
			"customer_id"             => $customer_id,
			"object_id"               => reset( $levels )->get_id(),
			"object_type"             => "membership",
			"status"                  => "active",
			"gateway"                 => "stripe",
			"gateway_customer_id"     => "${ customerId }",
			"subscription_key"        => "key_e2e_" . wp_generate_password( 8, false ),
			"auto_renew"              => 1,
			"expiration_date"         => gmdate( "Y-m-d 23:59:59", strtotime( "+1 month" ) ),
		 ) );
		 echo is_wp_error( $id ) ? 0 : $id;`
	);

	return Number( value ) || 0;
}

/**
 * Passerelle enregistrée pour une adhésion.
 *
 * @param {number} id Identifiant de l'adhésion.
 * @return {string} Identifiant de passerelle.
 */
function membershipGateway( id ) {
	return wpEval(
		`do_action( "admin_init" );
		 $m = rcp_get_membership( ${ id } );
		 echo $m ? $m->get_gateway() : "";`
	);
}

module.exports = {
	IBAN,
	createStripeCustomer,
	createCardMembership,
	membershipGateway,
	wpEval,
	sendWebhook,
	sendEventFor,
	createMember,
	membershipStatus,
	membershipId,
	waitForMembership,
	logIn,
	fillIban,
};
