<?php
/**
 * Tests des règles d'éligibilité à la migration vers le prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Migration\Eligibility;

/**
 * @covers \RCP_Stripe_Sepa\Migration\Eligibility
 */
final class EligibilityTest extends TestCase {

	/**
	 * Adhésion par carte, active et reconductible.
	 *
	 * @param array $overrides Valeurs à remplacer.
	 * @return array
	 */
	private function membership( array $overrides = array() ): array {
		return array_merge(
			array(
				'status'                  => 'active',
				'gateway'                 => 'stripe',
				'gateway_customer_id'     => 'cus_123',
				'gateway_subscription_id' => 'sub_123',
				'auto_renew'              => true,
				'currency'                => 'EUR',
			),
			$overrides
		);
	}

	// -- Cas autorisés ----------------------------------------------------------

	public function test_une_adhesion_par_carte_active_est_eligible(): void {
		$eligibility = Eligibility::assess( $this->membership() );

		$this->assertTrue( $eligibility->is_allowed() );
		$this->assertSame( Eligibility::REASON_ALLOWED, $eligibility->reason() );
	}

	public function test_une_adhesion_deja_en_sepa_peut_changer_de_mandat(): void {
		// Changement de banque, clôture de compte : l'adhérent doit pouvoir
		// fournir un nouvel IBAN sans résilier son adhésion.
		$eligibility = Eligibility::assess( $this->membership( array( 'gateway' => 'stripe_sepa' ) ) );

		$this->assertTrue( $eligibility->is_allowed() );
	}

	public function test_la_devise_est_comparee_sans_tenir_compte_de_la_casse(): void {
		$this->assertTrue( Eligibility::assess( $this->membership( array( 'currency' => 'eur' ) ) )->is_allowed() );
	}

	// -- Statut de l'adhésion ------------------------------------------------------

	/**
	 * @dataProvider provide_blocking_statuses
	 *
	 * @param string $status Statut bloquant.
	 */
	public function test_une_adhesion_non_active_n_est_pas_eligible( string $status ): void {
		/*
		 * RG-07 : migrer une adhésion en attente reviendrait à changer le
		 * moyen de paiement d'un prélèvement déjà engagé ; migrer une adhésion
		 * résiliée ou expirée n'a pas d'objet.
		 */
		$eligibility = Eligibility::assess( $this->membership( array( 'status' => $status ) ) );

		$this->assertFalse( $eligibility->is_allowed() );
		$this->assertSame( Eligibility::REASON_NOT_ACTIVE, $eligibility->reason() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provide_blocking_statuses(): array {
		return array(
			'en attente' => array( 'pending' ),
			'résiliée'   => array( 'cancelled' ),
			'expirée'    => array( 'expired' ),
			'inconnue'   => array( '' ),
		);
	}

	// -- Passerelle d'origine -------------------------------------------------------

	public function test_une_adhesion_hors_stripe_n_est_pas_eligible(): void {
		// Un paiement manuel ou par un autre prestataire n'a pas de client
		// Stripe à rattacher au mandat.
		$eligibility = Eligibility::assess( $this->membership( array( 'gateway' => 'manual' ) ) );

		$this->assertFalse( $eligibility->is_allowed() );
		$this->assertSame( Eligibility::REASON_UNSUPPORTED_GATEWAY, $eligibility->reason() );
	}

	public function test_une_adhesion_sans_client_stripe_n_est_pas_eligible(): void {
		$eligibility = Eligibility::assess( $this->membership( array( 'gateway_customer_id' => '' ) ) );

		$this->assertFalse( $eligibility->is_allowed() );
		$this->assertSame( Eligibility::REASON_NO_CUSTOMER, $eligibility->reason() );
	}

	// -- Nature de l'adhésion ---------------------------------------------------------

	public function test_une_adhesion_a_vie_n_a_rien_a_migrer(): void {
		// Sans échéance future, changer de moyen de paiement est sans effet.
		$eligibility = Eligibility::assess(
			$this->membership( array( 'auto_renew' => false, 'gateway_subscription_id' => '' ) )
		);

		$this->assertFalse( $eligibility->is_allowed() );
		$this->assertSame( Eligibility::REASON_NOT_RECURRING, $eligibility->reason() );
	}

	public function test_un_abonnement_stripe_subsistant_reste_migrable(): void {
		// Renouvellement automatique désactivé côté RCP mais abonnement Stripe
		// encore actif : le mandat sert toujours.
		$eligibility = Eligibility::assess( $this->membership( array( 'auto_renew' => false ) ) );

		$this->assertTrue( $eligibility->is_allowed() );
	}

	// -- Devise ---------------------------------------------------------------------------

	public function test_une_devise_autre_que_l_euro_n_est_pas_eligible(): void {
		$eligibility = Eligibility::assess( $this->membership( array( 'currency' => 'USD' ) ) );

		$this->assertFalse( $eligibility->is_allowed() );
		$this->assertSame( Eligibility::REASON_CURRENCY, $eligibility->reason() );
	}

	// -- Priorité des motifs ----------------------------------------------------------------

	public function test_le_motif_le_plus_explicite_est_retenu(): void {
		// Une adhésion résiliée et hors Stripe doit signaler le statut, plus
		// compréhensible pour l'adhérent que la passerelle.
		$eligibility = Eligibility::assess(
			$this->membership( array( 'status' => 'cancelled', 'gateway' => 'manual' ) )
		);

		$this->assertSame( Eligibility::REASON_NOT_ACTIVE, $eligibility->reason() );
	}

	public function test_tout_motif_de_refus_est_declare(): void {
		// Chaque motif doit être connu du présentateur de messages.
		$this->assertContains( Eligibility::REASON_NOT_ACTIVE, Eligibility::refusal_reasons() );
		$this->assertContains( Eligibility::REASON_CURRENCY, Eligibility::refusal_reasons() );
		$this->assertNotContains( Eligibility::REASON_ALLOWED, Eligibility::refusal_reasons() );
	}

	public function test_l_instantane_fourni_n_est_pas_mute(): void {
		$membership = $this->membership();
		$copy       = $membership;

		Eligibility::assess( $membership );

		$this->assertSame( $copy, $membership );
	}
}
