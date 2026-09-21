<?php
/**
 * Tests de l'offre de migration sur la page « Mon compte ».
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Integration;

use RCP_Stripe_Sepa\Migration\AccountPage;
use RCP_Stripe_Sepa\Migration\Eligibility;
use RCP_Stripe_Sepa\Migration\ReasonPresenter;
use RCP_Stripe_Sepa\Tests\Support\RcpFixtures;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Migration\AccountPage
 * @covers \RCP_Stripe_Sepa\Migration\ReasonPresenter
 */
final class MigrationAccountPageTest extends WP_UnitTestCase {

	use RcpFixtures;

	/**
	 * Utilisateur adhérent.
	 *
	 * @var int
	 */
	private $user_id = 0;

	public function set_up(): void {
		parent::set_up();

		$this->configure_rcp_stripe();
		$this->redirect_stripe_to_mock();

		$this->user_id = $this->create_user();

		wp_set_current_user( $this->user_id );

		AccountPage::register();
	}

	public function tear_down(): void {
		$this->restore_stripe_api_base();

		parent::tear_down();
	}

	/**
	 * Crée une adhésion active réglée par carte.
	 *
	 * @param array $overrides Valeurs à remplacer.
	 * @return \RCP_Membership
	 */
	private function card_membership( array $overrides = array() ) {
		$id = $this->create_membership(
			array_merge(
				array(
					'user_id'                 => $this->user_id,
					'status'                  => 'active',
					'gateway'                 => 'stripe',
					'gateway_customer_id'     => 'cus_compte',
					'gateway_subscription_id' => 'sub_compte',
				),
				$overrides
			)
		);

		return rcp_get_membership( $id );
	}

	// -- Offre de migration ------------------------------------------------------

	/**
	 * Rend la colonne « Actions » comme le fait le gabarit de RCP.
	 *
	 * `rcp_subscription_details_action_links` est une action, et non un filtre
	 * malgré son nom : RCP l'invoque par `do_action()` pour laisser une
	 * extension écrire du HTML. Le test emprunte donc exactement le même
	 * chemin que le gabarit.
	 *
	 * @param \RCP_Membership $membership Adhésion concernée.
	 * @return string
	 */
	private function render_actions( $membership ): string {
		ob_start();
		do_action( 'rcp_subscription_details_action_links', array(), $membership );

		return (string) ob_get_clean();
	}

	public function test_le_bouton_est_propose_pour_une_adhesion_eligible(): void {
		$html = $this->render_actions( $this->card_membership() );

		$this->assertStringContainsString( 'Switch to SEPA Direct Debit', $html );
		$this->assertStringContainsString( 'rcp-stripe-sepa-migrate-toggle', $html );
	}

	public function test_le_bouton_reference_le_formulaire_correspondant(): void {
		// L'attribut aria-controls doit désigner le formulaire de cette
		// adhésion, et non celui d'une autre.
		$membership = $this->card_membership();

		$this->assertStringContainsString(
			'aria-controls="rcp-stripe-sepa-migration-' . $membership->get_id() . '"',
			$this->render_actions( $membership )
		);
	}

	public function test_le_bouton_n_est_pas_propose_pour_une_adhesion_inactive(): void {
		$html = $this->render_actions( $this->card_membership( array( 'status' => 'pending' ) ) );

		$this->assertSame( '', $html );
	}

	public function test_le_bouton_n_est_pas_propose_hors_stripe(): void {
		$html = $this->render_actions( $this->card_membership( array( 'gateway' => 'manual' ) ) );

		$this->assertSame( '', $html );
	}

	public function test_un_parametre_inattendu_ne_casse_pas_l_affichage(): void {
		// L'action est invoquée par un gabarit : elle doit tolérer l'absence
		// d'adhésion sans erreur fatale.
		ob_start();
		AccountPage::render_action_link( array(), null );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	// -- Formulaire ----------------------------------------------------------------

	public function test_le_formulaire_est_rendu_pour_une_adhesion_eligible(): void {
		$this->card_membership();

		ob_start();
		do_action( 'rcp_subscription_details_bottom' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'rcp-stripe-sepa-migration', $html );
		$this->assertStringContainsString( '8 weeks', $html, 'Mentions du mandat absentes.' );
		$this->assertStringContainsString( 'hidden', $html, 'Le formulaire doit être masqué au départ.' );
	}

	public function test_le_formulaire_ne_contient_aucun_champ_iban_soumis(): void {
		// SEC-12 : l'IBAN est saisi dans un Stripe Element.
		$this->card_membership();

		ob_start();
		do_action( 'rcp_subscription_details_bottom' );
		$html = (string) ob_get_clean();

		$this->assertDoesNotMatchRegularExpression( '/<input[^>]*name="[^"]*iban[^"]*"/i', $html );
	}

	public function test_aucun_formulaire_sans_adhesion_eligible(): void {
		$this->card_membership( array( 'gateway' => 'manual' ) );

		ob_start();
		do_action( 'rcp_subscription_details_bottom' );

		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	public function test_aucun_formulaire_pour_un_visiteur_sans_compte(): void {
		wp_set_current_user( 0 );

		ob_start();
		do_action( 'rcp_subscription_details_bottom' );

		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	public function test_les_scripts_sont_charges_avec_le_formulaire(): void {
		$this->card_membership();

		ob_start();
		do_action( 'rcp_subscription_details_bottom' );
		ob_end_clean();

		$this->assertTrue( wp_script_is( 'rcp-stripe-sepa-migration', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'stripe-js-v3', 'enqueued' ) );
	}

	// -- Messages -------------------------------------------------------------------

	/**
	 * @dataProvider provide_refusal_reasons
	 *
	 * @param string $reason Motif d'inéligibilité.
	 */
	public function test_chaque_motif_de_refus_a_un_message( string $reason ): void {
		$message = ReasonPresenter::message( $reason );

		$this->assertNotSame( '', $message );
		$this->assertStringEndsWith( '.', $message, 'Le message doit être une phrase complète.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provide_refusal_reasons(): array {
		$cases = array();

		foreach ( Eligibility::refusal_reasons() as $reason ) {
			$cases[ $reason ] = array( $reason );
		}

		return $cases;
	}

	public function test_aucun_message_ne_divulgue_de_detail_technique(): void {
		// Un adhérent n'a que faire d'un identifiant de client Stripe.
		$messages = array_map( array( ReasonPresenter::class, 'message' ), Eligibility::refusal_reasons() );

		$messages[] = ReasonPresenter::generic_failure();
		$messages[] = ReasonPresenter::not_confirmed();

		foreach ( $messages as $message ) {
			$this->assertStringNotContainsString( 'cus_', $message );
			$this->assertStringNotContainsString( 'Stripe\\', $message );
			$this->assertStringNotContainsString( 'seti_', $message );
		}
	}
}
