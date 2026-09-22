<?php
/**
 * Tests de contrat de l'outillage console des webhooks.
 *
 * L'intérêt de `bin/webhook.php` tient entièrement à une propriété : la
 * signature qu'il produit doit être **celle que Stripe produirait**, sans quoi
 * rejouer un événement en local n'éprouverait pas la vérification réelle. Ces
 * tests confrontent donc la sortie du script au vérificateur du SDK Stripe.
 *
 * @package RCP_Stripe_Sepa
 * @see docs/webhooks-en-local.md
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Contract;

use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use WP_UnitTestCase;

/**
 * @group contract
 */
final class WebhookSigningTest extends WP_UnitTestCase {

	/**
	 * Racine du plugin.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Secret de signature lu dans .env.
	 *
	 * @var string
	 */
	private $secret;

	public function set_up(): void {
		parent::set_up();

		$this->root = dirname( __DIR__, 2 );

		/*
		 * Le secret est fourni au script par l'environnement, qui prime sur
		 * .env. Le test est donc exécutable partout — y compris en intégration
		 * continue, où aucun .env n'existe — sans jamais être ignoré.
		 */
		$this->secret = 'whsec_' . str_repeat( 'a1b2c3d4', 6 );
	}

	/**
	 * @group SEC-06
	 */
	public function test_la_signature_produite_est_acceptee_par_le_sdk_stripe(): void {
		$fixture = $this->fixture( 'payment-intent-succeeded' );
		$payload = (string) file_get_contents( $fixture );
		$header  = $this->sign( $fixture );

		$event = Webhook::constructEvent( $payload, $header, $this->secret );

		$this->assertSame( 'payment_intent.succeeded', $event->type );
		$this->assertFalse( $event->livemode );
	}

	public function test_une_charge_utile_alteree_est_rejetee(): void {
		$fixture = $this->fixture( 'payment-intent-succeeded' );
		$header  = $this->sign( $fixture );
		$altered = str_replace( '"livemode": false', '"livemode": true', (string) file_get_contents( $fixture ) );

		$this->expectException( SignatureVerificationException::class );

		Webhook::constructEvent( $altered, $header, $this->secret );
	}

	public function test_un_mauvais_secret_est_rejete(): void {
		$fixture = $this->fixture( 'payment-intent-succeeded' );
		$payload = (string) file_get_contents( $fixture );
		$header  = $this->sign( $fixture );

		$this->expectException( SignatureVerificationException::class );

		Webhook::constructEvent( $payload, $header, 'whsec_' . str_repeat( '0', 48 ) );
	}

	public function test_une_signature_antidatee_est_rejetee_par_la_tolerance(): void {
		// SEC-07 : tolérance de 300 secondes.
		$fixture   = $this->fixture( 'payment-intent-succeeded' );
		$payload   = (string) file_get_contents( $fixture );
		$timestamp = time() - 600;
		$header    = 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, $this->secret );

		$this->expectException( SignatureVerificationException::class );

		Webhook::constructEvent( $payload, $header, $this->secret, 300 );
	}

	public function test_toutes_les_fixtures_sont_du_json_valide_et_en_mode_test(): void {
		$files = glob( $this->root . '/tests/fixtures/webhooks/*.json' );

		$this->assertNotEmpty( $files, 'Aucune fixture de webhook.' );

		foreach ( $files as $file ) {
			$event = json_decode( (string) file_get_contents( $file ), true );

			$this->assertIsArray( $event, basename( $file ) . ' : JSON invalide.' );
			$this->assertSame( 'event', $event['object'] ?? null, basename( $file ) . ' : objet inattendu.' );
			$this->assertArrayHasKey( 'type', $event, basename( $file ) );
			$this->assertArrayHasKey( 'data', $event, basename( $file ) );

			// SEC-09 : aucune fixture de production ne doit entrer dans le dépôt.
			$this->assertFalse(
				(bool) ( $event['livemode'] ?? false ),
				basename( $file ) . ' : fixture en mode production, à retirer.'
			);
		}
	}

	// -- Utilitaires -----------------------------------------------------------

	/**
	 * Chemin d'une fixture.
	 *
	 * @param string $name Nom de la fixture.
	 * @return string
	 */
	private function fixture( string $name ): string {
		$path = $this->root . '/tests/fixtures/webhooks/' . $name . '.json';

		$this->assertFileExists( $path );

		return $path;
	}

	/**
	 * Demande au script console de signer une fixture.
	 *
	 * @param string $fixture Chemin de la fixture.
	 * @return string En-tête Stripe-Signature.
	 */
	private function sign( string $fixture ): string {
		$command = sprintf(
			'STRIPE_WEBHOOK_SECRET=%s php %s sign %s 2>&1',
			escapeshellarg( $this->secret ),
			escapeshellarg( $this->root . '/bin/webhook.php' ),
			escapeshellarg( $fixture )
		);

		$header = trim( (string) shell_exec( $command ) );

		$this->assertMatchesRegularExpression(
			'/^t=\d+,v1=[0-9a-f]{64}$/',
			$header,
			'Sortie inattendue de bin/webhook.php sign : ' . $header
		);

		return $header;
	}

}
