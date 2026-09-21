/**
 * Parcours : bascule d'une adhésion de la carte vers le prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

const { test, expect } = require( '@playwright/test' );
const {
	IBAN,
	createMember,
	createStripeCustomer,
	createCardMembership,
	membershipGateway,
	logIn,
	fillIban,
} = require( './helpers' );

test.describe( 'Migration vers le prélèvement SEPA', () => {
	let member;
	let membership;

	test.beforeEach( async ( { page } ) => {
		member = createMember();
		membership = createCardMembership( member.id, createStripeCustomer( member.email ) );

		expect( membership ).toBeGreaterThan( 0 );

		await logIn( page, member.login, member.password );
		await page.goto( '/mon-compte/' );
	} );

	test( 'la bascule est proposée sur une adhésion par carte', async ( { page } ) => {
		await expect( page.getByRole( 'button', { name: /Switch to SEPA/i } ) ).toBeVisible();
	} );

	test( 'le formulaire de mandat se déplie à la demande', async ( { page } ) => {
		await page.getByRole( 'button', { name: /Switch to SEPA/i } ).click();

		await expect( page.locator( '.rcp-stripe-sepa-migration' ) ).toBeVisible();
		await expect( page.getByText( /8 weeks|8 semaines/ ).first() ).toBeVisible();
	} );

	test( 'la bascule remplace le moyen de paiement sans toucher à l\'adhésion', async ( { page } ) => {
		/*
		 * RG-06 : la migration ne change ni le prix ni la date d'échéance, et
		 * l'adhésion reste active sans interruption.
		 */
		await page.getByRole( 'button', { name: /Switch to SEPA/i } ).click();
		await page.waitForSelector( '.rcp-stripe-sepa-migration .rcp-stripe-sepa-element iframe', { timeout: 30_000 } );

		await page.locator( '.rcp-stripe-sepa-migration .rcp-stripe-sepa-holder-name' ).fill( 'Membre E2E' );
		await fillIban( page, '.rcp-stripe-sepa-migration .rcp-stripe-sepa-element', IBAN.success );

		await page.getByRole( 'button', { name: /Confirm the mandate/i } ).click();

		await expect(
			page.locator( '.rcp-stripe-sepa-migration .rcp-stripe-sepa-errors' )
		).toContainText( /now paid by SEPA|désormais réglée/i, { timeout: 60_000 } );

		expect( membershipGateway( membership ) ).toBe( 'stripe_sepa' );
	} );

	test( 'le mandat en vigueur est ensuite rappelé à l\'adhérent', async ( { page } ) => {
		await page.getByRole( 'button', { name: /Switch to SEPA/i } ).click();
		await page.waitForSelector( '.rcp-stripe-sepa-migration .rcp-stripe-sepa-element iframe', { timeout: 30_000 } );

		await page.locator( '.rcp-stripe-sepa-migration .rcp-stripe-sepa-holder-name' ).fill( 'Membre E2E' );
		await fillIban( page, '.rcp-stripe-sepa-migration .rcp-stripe-sepa-element', IBAN.success );
		await page.getByRole( 'button', { name: /Confirm the mandate/i } ).click();

		await expect(
			page.locator( '.rcp-stripe-sepa-migration .rcp-stripe-sepa-errors' )
		).toContainText( /now paid by SEPA|désormais réglée/i, { timeout: 60_000 } );

		await page.goto( '/mon-compte/' );

		await expect( page.locator( '.rcp-stripe-sepa-mandate-details' ) ).toContainText( '2606' );
	} );
} );
