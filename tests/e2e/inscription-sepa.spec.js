/**
 * Parcours : inscription à une adhésion récurrente par prélèvement SEPA.
 *
 * Ce parcours vérifie ce qu'aucun test d'intégration ne peut établir : que
 * l'IBAN est bien saisi dans l'iframe de Stripe, que le mandat est confirmé
 * dans le navigateur, et que l'adhésion reste en attente jusqu'à
 * l'encaissement.
 *
 * @package RCP_Stripe_Sepa
 */

const { test, expect } = require( '@playwright/test' );
const { IBAN, createMember, waitForMembership, logIn, fillIban } = require( './helpers' );

test.describe( 'Inscription par prélèvement SEPA', () => {
	let member;
	let consoleErrors;

	test.beforeEach( async ( { page } ) => {
		member = createMember();
		consoleErrors = [];

		/*
		 * Une erreur JavaScript interrompt silencieusement la confirmation du
		 * mandat : sans cette capture, l'échec se lit comme « aucune adhésion
		 * créée », ce qui ne dit rien de la cause.
		 */
		page.on( 'console', ( message ) => {
			if ( 'error' === message.type() ) {
				consoleErrors.push( message.text() );
			}
		} );
		page.on( 'pageerror', ( error ) => consoleErrors.push( String( error ) ) );

		// Les réponses de l'AJAX de RCP disent si l'inscription a seulement été
		// tentée, et ce que la passerelle a répondu.
		page.on( 'response', async ( response ) => {
			if ( ! response.url().includes( 'admin-ajax.php' ) ) {
				return;
			}

			const body = await response.text().catch( () => '' );

			consoleErrors.push( `AJAX ${ response.status() } : ${ body.slice( 0, 400 ) }` );
		} );

		await logIn( page, member.login, member.password );
		await page.goto( '/adhesion/' );
	} );

	// @group F-01
	test( 'le prélèvement SEPA est proposé à côté de la carte', async ( { page } ) => {
		// Les deux passerelles doivent coexister : la régression la plus
		// coûteuse serait de casser le paiement par carte du même site.
		await expect( page.getByText( 'SEPA Direct Debit', { exact: false } ).first() ).toBeVisible();
		await expect( page.getByText( /Credit|Debit Card/i ).first() ).toBeVisible();
	} );

	// @group SEC-12
	test( 'le formulaire SEPA ne contient aucun champ IBAN soumis au serveur', async ( { page } ) => {
		await page.getByRole( 'radio', { name: /SEPA/i } ).check();
		await page.waitForSelector( '#rcp-stripe-sepa-iban-element iframe', { timeout: 30_000 } );

		// SEC-12 : l'IBAN est saisi dans une iframe servie par Stripe.
		const nativeIbanFields = await page.locator( 'input[name*="iban" i]' ).count();

		expect( nativeIbanFields ).toBe( 0 );
	} );

	// @group CNF-01
	// @group CNF-02
	test( 'le mandat est présenté avant la signature', async ( { page } ) => {
		await page.getByRole( 'radio', { name: /SEPA/i } ).check();

		await expect( page.getByText( /8 weeks|8 semaines/ ).first() ).toBeVisible();
	} );

	// @group F-02
	// @group RG-01
	test( 'une inscription laisse l\'adhésion en attente d\'encaissement', async ( { page } ) => {
		/*
		 * RG-01 : un prélèvement SEPA n'aboutit pas au moment de
		 * l'inscription. L'adhésion ne doit donc pas être activée, sans quoi
		 * l'accès au contenu serait ouvert avant tout encaissement.
		 */
		await page.getByRole( 'radio', { name: /SEPA/i } ).check();
		await page.waitForSelector( '#rcp-stripe-sepa-iban-element iframe', { timeout: 30_000 } );

		await page.fill( '#rcp-stripe-sepa-holder-name', 'Membre E2E' );
		await fillIban( page, '#rcp-stripe-sepa-iban-element', IBAN.success );

		/*
		 * `#rcp_submit` est l'identifiant du bouton de RCP. Le viser par son
		 * rôle happerait le menu de navigation, qui porte lui aussi le mot
		 * « Register ».
		 */
		await page.locator( '#rcp_submit' ).click();

		/*
		 * L'attente porte sur le résultat métier : Stripe.js maintient des
		 * connexions ouvertes, et `networkidle` ne se déclencherait jamais.
		 */
		const status = await waitForMembership( member.email, 'pending' );
		const shown = ( await page.locator( '#rcp-stripe-sepa-errors' ).textContent() ) || '';
		const rcpError = ( await page.locator( '.rcp_error, #rcp_ajax_error' ).first().textContent().catch( () => '' ) ) || '';

		expect(
			status,
			`Message SEPA : « ${ shown.trim() } » — message RCP : « ${ rcpError.trim() } » — console : ${ consoleErrors.join( ' | ' ) }`
		).toBe( 'pending' );
	} );
} );
