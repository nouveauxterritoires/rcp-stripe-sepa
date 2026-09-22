<?php
/**
 * Tests d'intégration de la persistance des mandats.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Integration;

use RCP_Stripe_Sepa\Mandate\MandateData;
use RCP_Stripe_Sepa\Mandate\MandateRepository;
use RCP_Stripe_Sepa\Tests\Support\RcpFixtures;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Mandate\MandateRepository
 */
final class MandateRepositoryTest extends WP_UnitTestCase {

	use RcpFixtures;

	/**
	 * Identifiant d'adhésion utilisé par les tests.
	 *
	 * @var int
	 */
	private $membership_id = 0;

	public function set_up(): void {
		parent::set_up();

		$this->membership_id = $this->create_membership( array( 'gateway' => 'stripe_sepa' ) );

	}

	/**
	 * Données de mandat de référence.
	 *
	 * @return array
	 */
	private function mandate_data(): array {
		return MandateData::with_acceptance(
			MandateData::from_stripe(
				array(
					'id'              => 'pm_test_1',
					'type'            => 'sepa_debit',
					'billing_details' => array( 'name' => 'Membre Test' ),
					'sepa_debit'      => array(
						'last4'       => '2606',
						'country'     => 'FR',
						'bank_code'   => '20041',
						'branch_code' => '01005',
						'fingerprint' => 'secret-fingerprint',
					),
				),
				array(
					'id'                     => 'mandate_test_1',
					'status'                 => 'active',
					'payment_method_details' => array(
						'sepa_debit' => array( 'reference' => '3F7X9K2L', 'url' => 'https://example.test/m' ),
					),
				)
			),
			'203.0.113.4',
			'2026-09-21 16:30:00'
		);
	}

	public function test_un_mandat_enregistre_est_relu_a_l_identique(): void {
		MandateRepository::save( $this->membership_id, $this->mandate_data() );

		$stored = MandateRepository::find( $this->membership_id );

		$this->assertSame( 'pm_test_1', $stored['payment_method_id'] );
		$this->assertSame( 'mandate_test_1', $stored['mandate_id'] );
		$this->assertSame( '3F7X9K2L', $stored['mandate_reference'] );
		$this->assertSame( '2606', $stored['iban_last4'] );
		$this->assertSame( 'Membre Test', $stored['account_holder_name'] );
		$this->assertSame( '2026-09-21 16:30:00', $stored['accepted_at'] );
	}

	public function test_aucune_donnee_bancaire_durable_n_est_stockee(): void {
		// SEC-03 : ni IBAN complet, ni empreinte bancaire.
		MandateRepository::save( $this->membership_id, $this->mandate_data() );

		$serialized = (string) wp_json_encode( MandateRepository::find( $this->membership_id ) );

		$this->assertStringNotContainsString( 'secret-fingerprint', $serialized );
		$this->assertStringNotContainsString( 'FR1420041010050500013M02606', $serialized );
	}

	public function test_une_adhesion_sans_mandat_renvoie_un_tableau_vide(): void {
		$this->assertSame( array(), MandateRepository::find( $this->membership_id ) );
	}

	public function test_l_oubli_supprime_toutes_les_metadonnees(): void {
		// CNF-06 : l'effaceur RGPD s'appuie sur cette opération.
		MandateRepository::save( $this->membership_id, $this->mandate_data() );
		MandateRepository::forget( $this->membership_id );

		$this->assertSame( array(), MandateRepository::find( $this->membership_id ) );
	}

	public function test_l_enregistrement_est_idempotent(): void {
		MandateRepository::save( $this->membership_id, $this->mandate_data() );
		MandateRepository::save( $this->membership_id, $this->mandate_data() );

		$values = rcp_get_membership_meta( $this->membership_id, MandateRepository::META_PREFIX . 'mandate_id' );

		$this->assertCount( 1, $values, 'La métadonnée a été dupliquée.' );
	}

	public function test_l_adresse_d_acceptation_est_purgee_apres_la_duree_de_conservation(): void {
		// SEC-15 : au-delà de 13 mois, cette donnée personnelle n'a plus
		// d'utilité probatoire.
		$data = MandateData::with_acceptance(
			MandateData::from_stripe(
				array( 'id' => 'pm_old', 'type' => 'sepa_debit', 'sepa_debit' => array( 'last4' => '0000' ) ),
				array()
			),
			'203.0.113.9',
			gmdate( 'Y-m-d H:i:s', strtotime( '-14 months' ) )
		);

		MandateRepository::save( $this->membership_id, $data );

		$this->assertTrue( MandateRepository::maybe_purge_ip( $this->membership_id ) );
		$this->assertSame( '', MandateRepository::find( $this->membership_id )['accepted_ip'] );
	}

	public function test_une_acceptation_recente_n_est_pas_purgee(): void {
		MandateRepository::save( $this->membership_id, $this->mandate_data() );

		$this->assertFalse( MandateRepository::maybe_purge_ip( $this->membership_id ) );
		$this->assertSame( '203.0.113.4', MandateRepository::find( $this->membership_id )['accepted_ip'] );
	}

	public function test_un_identifiant_d_adhesion_invalide_est_sans_effet(): void {
		MandateRepository::save( 0, $this->mandate_data() );

		$this->assertSame( array(), MandateRepository::find( 0 ) );
	}
}
