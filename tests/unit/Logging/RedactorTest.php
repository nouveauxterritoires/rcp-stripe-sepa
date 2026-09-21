<?php
/**
 * Tests de l'expurgation des journaux.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Logging\Redactor;

/**
 * @covers \RCP_Stripe_Sepa\Logging\Redactor
 */
final class RedactorTest extends TestCase {

	/**
	 * @dataProvider provide_secrets
	 *
	 * @param string $label   Nature du secret.
	 * @param string $secret  Valeur à masquer.
	 */
	public function test_masque_les_secrets( string $label, string $secret ): void {
		$redacted = Redactor::redact( 'Appel échoué avec ' . $secret . ' en contexte' );

		$this->assertStringNotContainsString( $secret, $redacted, $label );
		$this->assertStringContainsString( Redactor::PLACEHOLDER, $redacted );
		$this->assertStringContainsString( 'Appel échoué avec', $redacted );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_secrets(): array {
		return array(
			'clé secrète de test'       => array( 'clé secrète', 'sk_test_' . str_repeat( 'a1b2c3d4', 6 ) ),
			'clé secrète de production' => array( 'clé secrète', 'sk_live_' . str_repeat( 'a1b2c3d4', 6 ) ),
			'clé restreinte'            => array( 'clé restreinte', 'rk_live_' . str_repeat( 'f0e9d8c7', 6 ) ),
			'secret de webhook'         => array( 'secret de webhook', 'whsec_' . str_repeat( '0f1e2d3c', 6 ) ),
			'secret client'             => array( 'client_secret', 'pi_3ABC123_secret_XyZ987654321abcdefgh' ),
		);
	}

	public function test_masque_un_iban(): void {
		// SEC-03 : un IBAN ne doit jamais atteindre un journal.
		$redacted = Redactor::redact( 'Mandat pour FR1420041010050500013M02606 accepté' );

		$this->assertStringNotContainsString( 'FR1420041010050500013M02606', $redacted );
		$this->assertStringContainsString( 'Mandat pour', $redacted );
		$this->assertStringContainsString( 'accepté', $redacted );
	}

	public function test_conserve_les_identifiants_non_sensibles(): void {
		// Les identifiants d'objets Stripe sont nécessaires au diagnostic.
		$message = 'PaymentIntent pi_3UI9c1A461x37mSv0tun7KKA passé à processing, charge py_3UI9c1A461x37mSv0HEmraUf';

		$this->assertSame( $message, Redactor::redact( $message ) );
	}

	public function test_conserve_un_message_sans_secret(): void {
		$this->assertSame( 'Rien à masquer ici.', Redactor::redact( 'Rien à masquer ici.' ) );
	}

	public function test_masque_plusieurs_secrets_dans_un_meme_message(): void {
		$message = sprintf(
			'clé %s puis secret %s',
			'sk_test_' . str_repeat( 'a1b2c3d4', 6 ),
			'whsec_' . str_repeat( '9f8e7d6c', 6 )
		);

		$redacted = Redactor::redact( $message );

		$this->assertSame( 2, substr_count( $redacted, Redactor::PLACEHOLDER ) );
	}

	public function test_expurge_un_tableau_en_profondeur(): void {
		$payload = array(
			'id'     => 'evt_123',
			'data'   => array(
				'object' => array(
					'client_secret' => 'pi_3ABC_secret_ZzYy998877665544332211',
					'status'        => 'processing',
				),
			),
			'secret' => 'sk_test_' . str_repeat( 'a1b2c3d4', 6 ),
		);

		$redacted = Redactor::redact_array( $payload );

		$this->assertSame( 'evt_123', $redacted['id'] );
		$this->assertSame( 'processing', $redacted['data']['object']['status'] );
		$this->assertSame( Redactor::PLACEHOLDER, $redacted['data']['object']['client_secret'] );
		$this->assertSame( Redactor::PLACEHOLDER, $redacted['secret'] );
	}

	public function test_expurge_les_cles_sensibles_quelle_que_soit_la_valeur(): void {
		// Une clé nommée `iban` est expurgée même si sa valeur ne ressemble à rien.
		$redacted = Redactor::redact_array( array( 'iban' => 'valeur-quelconque', 'last4' => '2606' ) );

		$this->assertSame( Redactor::PLACEHOLDER, $redacted['iban'] );
		$this->assertSame( '2606', $redacted['last4'], 'Les 4 derniers caractères sont conservés.' );
	}

	public function test_n_altere_pas_les_valeurs_non_textuelles(): void {
		$redacted = Redactor::redact_array( array( 'amount' => 1000, 'paid' => false, 'meta' => null ) );

		$this->assertSame( 1000, $redacted['amount'] );
		$this->assertFalse( $redacted['paid'] );
		$this->assertNull( $redacted['meta'] );
	}

	public function test_le_tableau_fourni_n_est_pas_mute(): void {
		$payload = array( 'secret' => 'sk_test_' . str_repeat( 'a1b2c3d4', 6 ) );
		$copy    = $payload;

		Redactor::redact_array( $payload );

		$this->assertSame( $copy, $payload );
	}
}
