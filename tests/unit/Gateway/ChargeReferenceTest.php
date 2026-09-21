<?php
/**
 * Tests de la lecture de la charge d'une intention.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Gateway;

use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Gateway\ChargeReference;

/**
 * @covers \RCP_Stripe_Sepa\Gateway\ChargeReference
 */
final class ChargeReferenceTest extends TestCase {

	public function test_lit_le_champ_des_versions_recentes(): void {
		$intent = array( 'id' => 'pi_1', 'latest_charge' => 'py_abc123' );

		$this->assertSame( 'py_abc123', ChargeReference::from_intent( $intent ) );
	}

	public function test_lit_le_champ_developpe(): void {
		// Une requête avec expansion renvoie l'objet complet, pas l'identifiant.
		$intent = array( 'id' => 'pi_1', 'latest_charge' => array( 'id' => 'py_abc123', 'status' => 'pending' ) );

		$this->assertSame( 'py_abc123', ChargeReference::from_intent( $intent ) );
	}

	public function test_lit_le_champ_des_versions_anterieures(): void {
		/*
		 * Restrict Content Pro impose globalement l'API 2020-08-27, où la
		 * charge est exposée sous `charges.data`. Les réponses reçues via la
		 * passerelle carte prennent donc cette forme.
		 */
		$intent = array(
			'id'      => 'pi_1',
			'charges' => array( 'data' => array( array( 'id' => 'py_legacy', 'status' => 'pending' ) ) ),
		);

		$this->assertSame( 'py_legacy', ChargeReference::from_intent( $intent ) );
	}

	public function test_le_champ_recent_prime_sur_l_ancien(): void {
		$intent = array(
			'latest_charge' => 'py_recent',
			'charges'       => array( 'data' => array( array( 'id' => 'py_ancien' ) ) ),
		);

		$this->assertSame( 'py_recent', ChargeReference::from_intent( $intent ) );
	}

	public function test_une_intention_sans_charge_ne_produit_rien(): void {
		// À la création, l'intention n'a pas encore de charge.
		$this->assertSame( '', ChargeReference::from_intent( array( 'id' => 'pi_1', 'status' => 'requires_action' ) ) );
	}

	public function test_un_champ_nul_ne_produit_rien(): void {
		$this->assertSame( '', ChargeReference::from_intent( array( 'latest_charge' => null, 'charges' => null ) ) );
	}

	public function test_une_liste_de_charges_vide_ne_produit_rien(): void {
		$this->assertSame( '', ChargeReference::from_intent( array( 'charges' => array( 'data' => array() ) ) ) );
	}

	public function test_une_intention_d_enregistrement_n_a_pas_de_charge(): void {
		$this->assertSame( '', ChargeReference::from_intent( array( 'id' => 'seti_1', 'object' => 'setup_intent' ) ) );
	}
}
