/**
 * Parcours : activation d'une adhésion par le webhook d'encaissement.
 *
 * C'est le cœur du modèle SEPA : l'inscription ne donne pas accès au contenu,
 * seul l'encaissement le fait, et il survient plusieurs jours plus tard.
 *
 * @package RCP_Stripe_Sepa
 */

const { test, expect } = require( '@playwright/test' );
const {
	IBAN,
	createMember,
	membershipId,
	waitForMembership,
	logIn,
	fillIban,
	sendEventFor,
	wpEval,
} = require( './helpers' );

/**
 * Restreint une page au niveau d'adhésion payant.
 *
 * @param {string} slug Identifiant de la page.
 * @return {void}
 */
function restrictPage( slug ) {
	wpEval(
		`do_action( "admin_init" );
		 $page = get_page_by_path( "${ slug }" );
		 if ( ! $page ) { echo "ABSENTE"; return; }
		 $levels = rcp_get_membership_levels( array( "number" => 1 ) );
		 update_post_meta( $page->ID, "rcp_subscription_level", array( reset( $levels )->get_id() ) );
		 update_post_meta( $page->ID, "rcp_access_level", 0 );
		 echo $page->ID;`
	);
}

test.describe( 'Activation par webhook', () => {
	let member;

	test.beforeAll( () => {
		restrictPage( 'contenu-reserve' );
	} );

	test.beforeEach( async ( { page } ) => {
		member = createMember();

		await logIn( page, member.login, member.password );
	} );

	/**
	 * Souscrit une adhésion SEPA et renvoie son identifiant.
	 *
	 * @param {import('@playwright/test').Page} page Page Playwright.
	 * @return {Promise<number>} Identifiant de l'adhésion.
	 */
	async function subscribe( page ) {
		await page.goto( '/adhesion/' );
		await page.getByRole( 'radio', { name: /SEPA/i } ).check();
		await page.waitForSelector( '#rcp-stripe-sepa-iban-element iframe', { timeout: 30_000 } );

		await page.fill( '#rcp-stripe-sepa-holder-name', 'Membre E2E' );
		await fillIban( page, '#rcp-stripe-sepa-iban-element', IBAN.success );
		await page.locator( '#rcp_submit' ).click();

		expect( await waitForMembership( member.email, 'pending' ) ).toBe( 'pending' );

		return membershipId( member.email );
	}

	test( 'le contenu réservé reste fermé tant que le prélèvement n\'a pas abouti', async ( { page } ) => {
		// RG-01 : c'est l'invariant que toute l'architecture protège.
		await subscribe( page );

		await page.goto( '/contenu-reserve/' );

		await expect( page.locator( 'body' ) ).not.toContainText( 'Contenu réservé aux adhérents.' );
	} );

	test( 'l\'encaissement confirmé active l\'adhésion et ouvre le contenu', async ( { page } ) => {
		const id = await subscribe( page );

		sendEventFor( 'payment_intent.succeeded', id );

		expect( await waitForMembership( member.email, 'active' ) ).toBe( 'active' );

		await page.goto( '/contenu-reserve/' );

		await expect( page.locator( 'body' ) ).toContainText( 'Contenu réservé aux adhérents.' );
	} );

	test( 'un prélèvement refusé laisse le contenu fermé', async ( { page } ) => {
		const id = await subscribe( page );

		sendEventFor( 'payment_intent.payment_failed', id, { status: 'requires_payment_method' } );

		expect( await waitForMembership( member.email, 'expired' ) ).toBe( 'expired' );

		await page.goto( '/contenu-reserve/' );

		await expect( page.locator( 'body' ) ).not.toContainText( 'Contenu réservé aux adhérents.' );
	} );

	test( 'un événement rejoué n\'est pas retraité', async ( { page } ) => {
		// I-1 : Stripe rejoue ses événements ; un double traitement fausserait
		// la comptabilité.
		const id = await subscribe( page );
		const output = sendEventFor( 'payment_intent.succeeded', id );

		expect( output ).toContain( 'HTTP 200' );
		expect( await waitForMembership( member.email, 'active' ) ).toBe( 'active' );
	} );
} );
