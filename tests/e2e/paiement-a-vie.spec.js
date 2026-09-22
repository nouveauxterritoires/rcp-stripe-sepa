/**
 * Parcours : adhésion à vie réglée par un prélèvement SEPA unique.
 *
 * C'est le chemin le moins attesté du plugin : implémenté et couvert par des
 * tests unitaires, mais jamais éprouvé de bout en bout. Ce qu'il faut établir
 * ici tient en une phrase — le débiteur n'a autorisé qu'un seul prélèvement,
 * donc rien ne doit subsister qui permette d'en déclencher un autre.
 *
 * @package RCP_Stripe_Sepa
 */

const { test, expect } = require( '@playwright/test' );
const {
	IBAN,
	createMember,
	membershipFacts,
	membershipId,
	sendEventFor,
	waitForMembership,
	logIn,
	fillIban,
} = require( './helpers' );

/**
 * Niveau à vie du site de développement : durée 0, prix non nul.
 */
const LIFETIME_LEVEL = 'A vie';

test.describe( 'Adhésion à vie par prélèvement SEPA', () => {
	let member;
	let diagnostics;

	test.beforeEach( async ( { page } ) => {
		member = createMember();
		diagnostics = [];

		page.on( 'pageerror', ( error ) => diagnostics.push( String( error ) ) );
		page.on( 'console', ( message ) => {
			if ( 'error' === message.type() ) {
				diagnostics.push( message.text() );
			}
		} );

		await logIn( page, member.login, member.password );
		await page.goto( '/adhesion/' );
	} );

	/**
	 * Souscrit au niveau à vie et confirme le mandat dans le navigateur.
	 *
	 * @param {import('@playwright/test').Page} page Page Playwright.
	 * @return {Promise<void>}
	 */
	async function subscribeToLifetime( page ) {
		await page.getByRole( 'radio', { name: LIFETIME_LEVEL } ).check();
		await page.getByRole( 'radio', { name: /SEPA/i } ).check();
		await page.waitForSelector( '#rcp-stripe-sepa-iban-element iframe', { timeout: 30_000 } );

		await page.fill( '#rcp-stripe-sepa-holder-name', 'Membre à vie' );
		await fillIban( page, '#rcp-stripe-sepa-iban-element', IBAN.success );

		await page.locator( '#rcp_submit' ).click();
	}

	// @group F-03
	test( 'le prélèvement SEPA est proposé sur une adhésion à vie', async ( { page } ) => {
		await page.getByRole( 'radio', { name: LIFETIME_LEVEL } ).check();

		await expect( page.getByText( 'SEPA Direct Debit', { exact: false } ).first() ).toBeVisible();
	} );

	// @group F-03
	// @group RG-01
	test( 'l\'adhésion à vie reste en attente jusqu\'à l\'encaissement', async ( { page } ) => {
		await subscribeToLifetime( page );

		const status = await waitForMembership( member.email, 'pending' );
		const shown = ( await page.locator( '#rcp-stripe-sepa-errors' ).textContent() ) || '';

		expect(
			status,
			`Message SEPA : « ${ shown.trim() } » — console : ${ diagnostics.join( ' | ' ) }`
		).toBe( 'pending' );
	} );

	/**
	 * Le cœur du parcours. Un abonnement Stripe subsistant rendrait possible un
	 * second prélèvement, que le débiteur n'a jamais autorisé.
	 */
	// @group F-03
	test( 'aucun abonnement Stripe n\'est créé pour un paiement unique', async ( { page } ) => {
		await subscribeToLifetime( page );
		await waitForMembership( member.email, 'pending' );

		const facts = membershipFacts( member.email );

		expect( facts.gateway ).toBe( 'stripe_sepa' );
		expect(
			facts.subscriptionId,
			'Une adhésion à vie ne doit laisser aucun abonnement derrière elle.'
		).toBe( '' );
	} );

	// @group F-03
	// @group F-04
	test( 'l\'encaissement confirmé active l\'adhésion sans échéance', async ( { page } ) => {
		await subscribeToLifetime( page );
		await waitForMembership( member.email, 'pending' );

		const id = membershipId( member.email );

		sendEventFor( 'payment_intent.succeeded', id, {
			object: 'payment_intent',
			status: 'succeeded',
			latest_charge: 'py_e2e_a_vie',
		} );

		const status = await waitForMembership( member.email, 'active' );

		expect( status ).toBe( 'active' );

		const facts = membershipFacts( member.email );

		/*
		 * `none` est la façon dont Restrict Content Pro note une adhésion sans
		 * terme. Une date réelle signalerait que le niveau a été traité comme
		 * reconductible.
		 */
		expect( facts.expiration ).toBe( 'none' );
		expect( facts.subscriptionId ).toBe( '' );
	} );

	// @group RG-04
	test( 'un prélèvement refusé laisse l\'adhésion sans accès', async ( { page } ) => {
		await subscribeToLifetime( page );
		await waitForMembership( member.email, 'pending' );

		const id = membershipId( member.email );

		sendEventFor( 'payment_intent.payment_failed', id, {
			object: 'payment_intent',
			status: 'requires_payment_method',
		} );

		const status = await waitForMembership( member.email, 'expired' );

		expect( status ).toBe( 'expired' );
	} );
} );
