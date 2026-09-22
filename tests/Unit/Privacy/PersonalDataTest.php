<?php
/**
 * Tests de la composition des données personnelles exportées.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Privacy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Privacy\PersonalData;

/**
 * @covers \RCP_Stripe_Sepa\Privacy\PersonalData
 */
final class PersonalDataTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Mandat complet tel qu'il est stocké.
	 *
	 * @param array $overrides Valeurs à remplacer.
	 * @return array
	 */
	private function mandate( array $overrides = array() ): array {
		return array_merge(
			array(
				'payment_method_id'   => 'pm_123',
				'mandate_id'          => 'mandate_123',
				'mandate_reference'   => 'RUM123',
				'mandate_url'         => 'https://example.test/mandat',
				'mandate_status'      => 'active',
				'iban_last4'          => '2606',
				'country'             => 'FR',
				'bank_code'           => '20041',
				'branch_code'         => '01005',
				'account_holder_name' => 'Membre Test',
				'accepted_at'         => '2026-09-21 16:30:00',
				'accepted_ip'         => '203.0.113.4',
			),
			$overrides
		);
	}

	// -- Contenu exporté ---------------------------------------------------------

	public function test_les_donnees_du_mandat_sont_exportees(): void {
		$items = PersonalData::mandate_items( $this->mandate() );

		$values = wp_list_pluck_values( $items );

		$this->assertContains( 'Membre Test', $values );
		$this->assertContains( 'RUM123', $values );
		$this->assertContains( '203.0.113.4', $values );
	}

	public function test_l_iban_est_exporte_masque(): void {
		// L'export est remis à la personne concernée, mais il transite par un
		// fichier téléchargeable : l'IBAN complet n'a pas à y figurer, et il
		// n'est de toute façon pas stocké.
		$values = wp_list_pluck_values( PersonalData::mandate_items( $this->mandate() ) );

		$this->assertContains( 'FR•• •••• •••• 2606', $values );
		$this->assertNotContains( '2606', $values, 'Les quatre derniers chiffres ne sont exposés que masqués.' );
	}

	public function test_les_champs_vides_ne_sont_pas_exportes(): void {
		// Un export lisible ne comporte pas de lignes vides.
		$items = PersonalData::mandate_items(
			$this->mandate( array( 'mandate_reference' => '', 'accepted_ip' => '' ) )
		);

		foreach ( $items as $item ) {
			$this->assertNotSame( '', $item['value'] );
		}
	}

	public function test_chaque_element_porte_un_nom_et_une_valeur(): void {
		foreach ( PersonalData::mandate_items( $this->mandate() ) as $item ) {
			$this->assertArrayHasKey( 'name', $item );
			$this->assertArrayHasKey( 'value', $item );
			$this->assertNotSame( '', $item['name'] );
		}
	}

	// -- Ce qui n'est jamais exporté -------------------------------------------------

	/**
	 * @group SEC-04
	 */
	public function test_aucun_identifiant_technique_n_est_exporte(): void {
		/*
		 * Les identifiants Stripe ne renseignent pas la personne concernée sur
		 * ses propres données : ils n'ont d'utilité que pour le site.
		 */
		$serialized = (string) json_encode( PersonalData::mandate_items( $this->mandate() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		$this->assertStringNotContainsString( 'pm_123', $serialized );
		$this->assertStringNotContainsString( 'mandate_123', $serialized );
	}

	public function test_un_mandat_vide_ne_produit_aucun_element(): void {
		$this->assertSame( array(), PersonalData::mandate_items( array() ) );
	}

	public function test_le_mandat_fourni_n_est_pas_mute(): void {
		$mandate = $this->mandate();
		$copy    = $mandate;

		PersonalData::mandate_items( $mandate );

		$this->assertSame( $copy, $mandate );
	}

	// -- Mention de politique de confidentialité ----------------------------------------

	/**
	 * @group CNF-07
	 */
	public function test_la_mention_de_confidentialite_nomme_le_sous_traitant(): void {
		$content = PersonalData::privacy_policy_content();

		$this->assertStringContainsString( 'Stripe', $content );
		$this->assertStringContainsString( 'mandat', $content );
	}

	/**
	 * @group CNF-08
	 */
	public function test_la_mention_precise_la_duree_de_conservation(): void {
		$this->assertStringContainsString( '13', PersonalData::privacy_policy_content() );
	}
}

/**
 * Extrait les valeurs d'une liste d'éléments exportés.
 *
 * @param array[] $items Éléments.
 * @return string[]
 */
function wp_list_pluck_values( array $items ): array {
	return array_map(
		static function ( array $item ): string {
			return (string) $item['value'];
		},
		$items
	);
}
