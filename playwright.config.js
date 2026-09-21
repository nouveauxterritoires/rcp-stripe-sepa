/**
 * Configuration des tests de bout en bout.
 *
 * Les tests s'exécutent contre la pile Docker déjà démarrée : ils ne la
 * lancent pas eux-mêmes, de sorte qu'on puisse les rejouer sans attendre un
 * provisionnement complet. `make up` reste le préalable.
 *
 * @package RCP_Stripe_Sepa
 */

const { defineConfig, devices } = require( '@playwright/test' );

const port = process.env.WP_PORT || '8080';

module.exports = defineConfig( {
	testDir: './tests/e2e',
	testMatch: '**/*.spec.js',

	// Les parcours touchent la même base WordPress : les paralléliser
	// produirait des interférences difficiles à diagnostiquer.
	workers: 1,
	fullyParallel: false,

	// Le mandat SEPA passe par Stripe : une lenteur réseau ne doit pas se lire
	// comme un échec fonctionnel.
	timeout: 90_000,
	expect: { timeout: 15_000 },

	retries: process.env.CI ? 1 : 0,
	forbidOnly: !! process.env.CI,

	reporter: process.env.CI
		? [ [ 'list' ], [ 'html', { open: 'never' } ] ]
		: [ [ 'list' ] ],

	use: {
		baseURL: process.env.E2E_BASE_URL || `http://localhost:${ port }`,
		// Artefacts conservés uniquement en cas d'échec : ils servent au
		// diagnostic, pas à l'archivage.
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		trace: 'retain-on-failure',
		actionTimeout: 20_000,
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
