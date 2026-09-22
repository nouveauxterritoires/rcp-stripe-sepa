<?php
/**
 * Tests de l'extraction des données de mandat.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Mandate;

use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Mandate\MandateData;

/**
 * @covers \RCP_Stripe_Sepa\Mandate\MandateData
 */
final class MandateDataTest extends TestCase {

	/**
	 * Moyen de paiement SEPA tel que Stripe le renvoie.
	 *
	 * @param array $overrides Valeurs à remplacer dans `sepa_debit`.
	 * @return array
	 */
	private function payment_method( array $overrides = array() ): array {
		return array(
			'id'              => 'pm_1UI9c1',
			'object'          => 'payment_method',
			'type'            => 'sepa_debit',
			'billing_details' => array(
				'name'  => 'Membre Test',
				'email' => 'membre@example.test',
			),
			'sepa_debit'      => array_merge(
				array(
					'bank_code'   => '20041',
					'branch_code' => '01005',
					'country'     => 'FR',
					'last4'       => '2606',
					'fingerprint' => 'abc123',
				),
				$overrides
			),
		);
	}

	/**
	 * Mandat tel que Stripe le renvoie.
	 *
	 * @return array
	 */
	private function mandate(): array {
		return array(
			'id'                     => 'mandate_1UI9c1',
			'status'                 => 'active',
			'payment_method_details' => array(
				'sepa_debit' => array(
					'reference' => '3F7X9K2L',
					'url'       => 'https://stripe.com/mandats/exemple',
				),
			),
		);
	}

	// -- Données conservées -----------------------------------------------------

	public function test_les_informations_utiles_sont_extraites(): void {
		$data = MandateData::from_stripe( $this->payment_method(), $this->mandate() );

		$this->assertSame( 'pm_1UI9c1', $data['payment_method_id'] );
		$this->assertSame( 'mandate_1UI9c1', $data['mandate_id'] );
		$this->assertSame( '3F7X9K2L', $data['mandate_reference'] );
		$this->assertSame( 'https://stripe.com/mandats/exemple', $data['mandate_url'] );
		$this->assertSame( 'active', $data['mandate_status'] );
		$this->assertSame( '2606', $data['iban_last4'] );
		$this->assertSame( 'FR', $data['country'] );
		$this->assertSame( 'Membre Test', $data['account_holder_name'] );
	}

	public function test_un_mandat_absent_ne_fait_pas_echouer_l_extraction(): void {
		// Une intention peut aboutir avant que le mandat ne soit développé
		// dans la réponse : les informations du moyen de paiement suffisent.
		$data = MandateData::from_stripe( $this->payment_method(), array() );

		$this->assertSame( 'pm_1UI9c1', $data['payment_method_id'] );
		$this->assertSame( '', $data['mandate_id'] );
		$this->assertSame( '2606', $data['iban_last4'] );
	}

	// -- Données jamais conservées ------------------------------------------------

	public function test_aucun_iban_complet_n_est_conserve(): void {
		// SEC-03 : seuls les quatre derniers caractères sont stockés.
		$data = MandateData::from_stripe(
			$this->payment_method( array( 'iban' => 'FR1420041010050500013M02606' ) ),
			$this->mandate()
		);

		$serialized = (string) json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

        $this->assertStringNotContainsString( 'FR1420041010050500013M02606', $serialized );
		$this->assertArrayNotHasKey( 'iban', $data );
	}

	public function test_l_empreinte_bancaire_n_est_pas_conservee(): void {
		// L'empreinte permet de rapprocher un compte entre plusieurs comptes
		// Stripe : elle n'a aucune utilité locale et constitue un identifiant
		// bancaire durable.
		$data = MandateData::from_stripe( $this->payment_method(), $this->mandate() );

		$this->assertArrayNotHasKey( 'fingerprint', $data );
	}

	// -- Affichage -----------------------------------------------------------------

	public function test_l_iban_masque_est_lisible_par_le_membre(): void {
		$data = MandateData::from_stripe( $this->payment_method(), $this->mandate() );

		$this->assertSame( 'FR•• •••• •••• 2606', MandateData::masked_iban( $data ) );
	}

	public function test_l_iban_masque_reste_correct_sans_pays_connu(): void {
		$masked = MandateData::masked_iban( array( 'iban_last4' => '2606', 'country' => '' ) );

		$this->assertStringContainsString( '2606', $masked );
	}

	// -- Preuve de consentement ------------------------------------------------------

	public function test_la_preuve_de_consentement_est_horodatee(): void {
		// CNF-02 : la soumission du formulaire vaut signature électronique.
		$data = MandateData::with_acceptance(
			MandateData::from_stripe( $this->payment_method(), $this->mandate() ),
			'203.0.113.4',
			'2026-09-21 16:30:00'
		);

		$this->assertSame( '2026-09-21 16:30:00', $data['accepted_at'] );
		$this->assertSame( '203.0.113.4', $data['accepted_ip'] );
	}

	// -- Robustesse --------------------------------------------------------------------

	public function test_un_moyen_de_paiement_non_sepa_est_refuse(): void {
		$card = array( 'id' => 'pm_card', 'type' => 'card', 'card' => array( 'last4' => '4242' ) );

		$this->assertSame( array(), MandateData::from_stripe( $card, array() ) );
	}

	public function test_les_donnees_fournies_ne_sont_pas_mutees(): void {
		$payment_method = $this->payment_method();
		$copy           = $payment_method;

		MandateData::from_stripe( $payment_method, $this->mandate() );

		$this->assertSame( $copy, $payment_method );
	}
}
