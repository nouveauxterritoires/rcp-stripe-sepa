<?php
/**
 * Tests des contrôles d'accès de la migration vers le prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Integration;

use RCP_Stripe_Sepa\Migration\AccountPage;
use RCP_Stripe_Sepa\Migration\AjaxController;
use RCP_Stripe_Sepa\Tests\Support\RcpFixtures;
use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

/**
 * @covers \RCP_Stripe_Sepa\Migration\AjaxController
 * @group ajax
 */
final class MigrationAjaxTest extends WP_Ajax_UnitTestCase {

	use RcpFixtures;

	/**
	 * Adhésion de l'utilisateur courant.
	 *
	 * @var int
	 */
	private $own_membership = 0;

	/**
	 * Adhésion appartenant à un tiers.
	 *
	 * @var int
	 */
	private $foreign_membership = 0;

	public function set_up(): void {
		parent::set_up();

		$this->configure_rcp_stripe();
		$this->redirect_stripe_to_mock();

		AjaxController::register();

		// `WP_Ajax_UnitTestCase` ne connecte personne : l'utilisateur courant
		// doit être établi avant de créer son adhésion.
		$current = $this->create_user();

		wp_set_current_user( $current );

		$this->own_membership     = $this->card_membership( $current );
		$this->foreign_membership = $this->card_membership( $this->create_user() );
	}

	public function tear_down(): void {
		$this->restore_stripe_api_base();

		parent::tear_down();
	}

	/**
	 * Crée une adhésion active réglée par carte.
	 *
	 * @param int $user_id Propriétaire.
	 * @return int
	 */
	private function card_membership( int $user_id ): int {
		return $this->create_membership(
			array(
				'user_id'                 => $user_id,
				'status'                  => 'active',
				'gateway'                 => 'stripe',
				'gateway_customer_id'     => 'cus_' . $user_id,
				'gateway_subscription_id' => 'sub_' . $user_id,
			)
		);
	}

	/**
	 * Exécute une action AJAX et renvoie la réponse décodée.
	 *
	 * @param string $action Action à exécuter.
	 * @param array  $post   Données transmises.
	 * @return array
	 */
	private function dispatch( string $action, array $post ): array {
		$_POST = $post;

		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Réponse envoyée : la sortie est disponible ci-dessous.
		} catch ( WPAjaxDieStopException $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Idem.
		}

		$decoded = json_decode( $this->_last_response, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	// -- Contrôle de propriété ----------------------------------------------------

	/**
	 * @group SEC-17
	 */
	public function test_un_adherent_ne_peut_pas_migrer_l_adhesion_d_un_autre(): void {
		/*
		 * SEC-17 : sans ce contrôle, l'identifiant d'adhésion étant un entier
		 * séquentiel, n'importe quel adhérent pourrait changer le moyen de
		 * paiement d'un autre.
		 */
		$before = rcp_get_membership( $this->foreign_membership )->get_gateway();

		$response = $this->dispatch(
			AjaxController::ACTION_START,
			array(
				'nonce'         => wp_create_nonce( AccountPage::NONCE_ACTION ),
				'membership_id' => $this->foreign_membership,
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame(
			$before,
			rcp_get_membership( $this->foreign_membership )->get_gateway(),
			'L\'adhésion d\'un tiers a été modifiée.'
		);
	}

	public function test_les_deux_points_d_entree_verifient_la_propriete(): void {
		// La confirmation est le point d'entrée qui modifie réellement
		// l'adhésion : elle doit être gardée au même titre que la préparation.
		$response = $this->dispatch(
			AjaxController::ACTION_COMPLETE,
			array(
				'nonce'           => wp_create_nonce( AccountPage::NONCE_ACTION ),
				'membership_id'   => $this->foreign_membership,
				'setup_intent_id' => 'seti_quelconque',
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame(
			'stripe',
			rcp_get_membership( $this->foreign_membership )->get_gateway(),
			'La passerelle d\'une adhésion tierce a été modifiée.'
		);
	}

	public function test_une_adhesion_inexistante_et_une_adhesion_d_autrui_donnent_le_meme_message(): void {
		// Distinguer les deux cas permettrait d'énumérer les adhésions
		// existantes.
		$nonce = wp_create_nonce( AccountPage::NONCE_ACTION );

		$foreign = $this->dispatch(
			AjaxController::ACTION_START,
			array( 'nonce' => $nonce, 'membership_id' => $this->foreign_membership )
		);

		$this->_last_response = '';

		$unknown = $this->dispatch(
			AjaxController::ACTION_START,
			array( 'nonce' => $nonce, 'membership_id' => 999999 )
		);

		$this->assertSame( $foreign['data']['message'], $unknown['data']['message'] );
	}

	// -- Nonce ----------------------------------------------------------------------

	/**
	 * @group SEC-17
	 */
	public function test_une_requete_sans_nonce_est_refusee(): void {
		$response = $this->dispatch(
			AjaxController::ACTION_START,
			array( 'membership_id' => $this->own_membership )
		);

		$this->assertFalse( $response['success'] );
	}

	public function test_un_nonce_invalide_est_refuse(): void {
		$response = $this->dispatch(
			AjaxController::ACTION_START,
			array( 'nonce' => 'nonce-invalide', 'membership_id' => $this->own_membership )
		);

		$this->assertFalse( $response['success'] );
	}

	// -- Session ---------------------------------------------------------------------

	public function test_la_migration_n_est_pas_exposee_aux_visiteurs(): void {
		// Aucune variante `nopriv` : la migration n'a pas de sens hors session.
		$this->assertFalse( has_action( 'wp_ajax_nopriv_' . AjaxController::ACTION_START ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_' . AjaxController::ACTION_COMPLETE ) );
	}

	// -- Paramètres --------------------------------------------------------------------

	public function test_une_confirmation_sans_intention_est_refusee(): void {
		$response = $this->dispatch(
			AjaxController::ACTION_COMPLETE,
			array(
				'nonce'         => wp_create_nonce( AccountPage::NONCE_ACTION ),
				'membership_id' => $this->own_membership,
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertNotEmpty( $response['data']['message'] );
	}
	/**
	 * Le message technique renvoyé par Stripe est attaché aux données de
	 * l'erreur, pour qu'un développeur puisse le lire sans dépendre du journal
	 * de RCP. Il ne doit jamais accompagner la réponse envoyée à l'adhérent :
	 * il nomme des objets internes et, selon le cas, des identifiants de
	 * client.
	 */
	public function test_la_reponse_ne_transporte_jamais_le_detail_technique(): void {
		$error = new \WP_Error(
			'rcp_stripe_sepa_setup_failed',
			'Message destiné à l\'adhérent.',
			array( 'stripe_message' => 'No such customer: cus_SECRET' )
		);

		$sent = array( 'message' => $error->get_error_message() );

		$this->assertSame( 'Message destiné à l\'adhérent.', $sent['message'] );
		$this->assertStringNotContainsString( 'cus_SECRET', wp_json_encode( $sent ) );

		/*
		 * Le contrôleur ne compose sa réponse qu'avec `get_error_message()` :
		 * c'est cette propriété du code qu'il faut préserver.
		 */
		$controller = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Migration/AjaxController.php' );

		$this->assertStringNotContainsString( 'get_error_data', $controller );
	}

}
