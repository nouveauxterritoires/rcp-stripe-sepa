<?php
/**
 * Tests des e-mails transactionnels du prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Integration;

use RCP_Stripe_Sepa\Email\MessageFactory;
use RCP_Stripe_Sepa\Email\Notifications;
use RCP_Stripe_Sepa\Membership\StateMachine;
use RCP_Stripe_Sepa\Membership\Transition;
use RCP_Stripe_Sepa\Tests\Support\RcpFixtures;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Email\Notifications
 */
final class NotificationsTest extends WP_UnitTestCase {

	use RcpFixtures;

	/**
	 * Messages envoyés pendant le test.
	 *
	 * @var array[]
	 */
	private $sent = array();

	/**
	 * Adhésion utilisée.
	 *
	 * @var int
	 */
	private $membership_id = 0;

	public function set_up(): void {
		parent::set_up();

		$this->configure_rcp_stripe();
		$this->redirect_stripe_to_mock();

		$this->sent = array();

		// `wp_mail` n'aboutit pas dans le harnais : l'interception par filtre
		// permet d'observer ce qui aurait été envoyé.
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $args ) {
				$this->sent[] = $args;

				return true;
			},
			10,
			2
		);

		Notifications::register();

		$this->membership_id = $this->create_membership(
			array(
				'user_id' => $this->create_user(),
				'status'  => 'pending',
				'gateway' => 'stripe_sepa',
			)
		);
	}

	public function tear_down(): void {
		$this->restore_stripe_api_base();

		parent::tear_down();
	}

	/**
	 * Déclenche une transition appliquée.
	 *
	 * @param Transition $transition Transition.
	 * @param string     $event_type Type d'événement Stripe.
	 * @return void
	 */
	private function apply( Transition $transition, string $event_type ): void {
		do_action(
			'rcp_stripe_sepa_transition_applied',
			rcp_get_membership( $this->membership_id ),
			$transition,
			array( 'type' => $event_type ),
			array()
		);
	}

	/**
	 * Concatène objets et corps de tous les messages envoyés.
	 *
	 * @return string
	 */
	private function all_text(): string {
		$text = '';

		foreach ( $this->sent as $message ) {
			$text .= $message['subject'] . "\n" . $message['message'] . "\n";
		}

		return $text;
	}

	// -- Messages à l'adhérent ---------------------------------------------------

	public function test_un_prelevement_engage_previent_l_adherent_du_delai(): void {
		/*
		 * Sans ce message, l'adhérent croit son adhésion active et s'étonne de
		 * ne pas accéder au contenu pendant deux semaines.
		 */
		$this->apply(
			Transition::to( StateMachine::MEMBERSHIP_PENDING, StateMachine::PAYMENT_PENDING, 'Prélèvement engagé.' ),
			'payment_intent.processing'
		);

		$this->assertCount( 1, $this->sent );
		$this->assertStringContainsString( 'working days', $this->all_text() );
	}

	public function test_un_prelevement_refuse_previent_l_adherent(): void {
		$this->apply(
			Transition::to( StateMachine::MEMBERSHIP_CANCELLED, StateMachine::PAYMENT_FAILED, 'Prélèvement refusé.' ),
			'payment_intent.payment_failed'
		);

		$this->assertCount( 1, $this->sent );
		$this->assertStringContainsString( 'declined', $this->all_text() );
		$this->assertStringContainsString( 'Prélèvement refusé.', $this->all_text(), 'Le motif doit être repris.' );
	}

	public function test_un_encaissement_reussi_n_envoie_rien(): void {
		// RCP envoie déjà son e-mail d'activation : en ajouter un serait
		// redondant pour l'adhérent.
		$this->apply(
			Transition::to( StateMachine::MEMBERSHIP_ACTIVE, StateMachine::PAYMENT_COMPLETE, 'Encaissé.' ),
			'payment_intent.succeeded'
		);

		$this->assertSame( array(), $this->sent );
	}

	public function test_le_message_part_a_l_adresse_de_l_adherent(): void {
		$membership = rcp_get_membership( $this->membership_id );
		$user       = get_userdata( (int) $membership->get_user_id() );

		$this->apply(
			Transition::to( null, StateMachine::PAYMENT_PENDING, 'Prélèvement engagé.' ),
			'payment_intent.processing'
		);

		$this->assertSame( $user->user_email, $this->sent[0]['to'] );
	}

	public function test_la_bascule_de_moyen_de_paiement_est_confirmee(): void {
		do_action( 'rcp_stripe_sepa_migrated', rcp_get_membership( $this->membership_id ), 'pm_123' );

		$this->assertCount( 1, $this->sent );
		$this->assertStringContainsString( 'unchanged', $this->all_text() );
	}

	// -- Alertes à l'administrateur -------------------------------------------------

	public function test_un_litige_alerte_l_administrateur(): void {
		$this->apply(
			Transition::to( StateMachine::MEMBERSHIP_CANCELLED, null, 'Litige ouvert.' ),
			'charge.dispute.created'
		);

		$this->assertCount( 1, $this->sent );
		$this->assertSame( get_option( 'admin_email' ), $this->sent[0]['to'] );
		$this->assertStringContainsString( (string) $this->membership_id, $this->all_text() );
	}

	public function test_un_evenement_abandonne_alerte_l_administrateur(): void {
		do_action( 'rcp_stripe_sepa_webhook_abandoned', 'evt_perdu', 'invoice.paid' );

		$this->assertCount( 1, $this->sent );
		$this->assertStringContainsString( 'evt_perdu', $this->all_text() );
	}

	public function test_l_adresse_d_alerte_est_filtrable(): void {
		add_filter( 'rcp_stripe_sepa_admin_email', static fn() => 'supervision@example.test' );

		do_action( 'rcp_stripe_sepa_webhook_abandoned', 'evt_perdu', 'invoice.paid' );

		$this->assertSame( 'supervision@example.test', $this->sent[0]['to'] );
	}

	// -- Robustesse et confidentialité -------------------------------------------------

	public function test_un_parametre_inattendu_n_envoie_rien(): void {
		// Les actions sont publiques : elles doivent tolérer n'importe quoi.
		do_action( 'rcp_stripe_sepa_transition_applied', null, null, array(), array() );
		do_action( 'rcp_stripe_sepa_migrated', 'pas une adhésion' );

		$this->assertSame( array(), $this->sent );
	}

	public function test_aucun_message_ne_contient_de_donnee_bancaire(): void {
		// SEC-03 : un e-mail traverse des serveurs tiers et reste archivé.
		$this->apply(
			Transition::to( null, StateMachine::PAYMENT_PENDING, 'Prélèvement engagé.' ),
			'payment_intent.processing'
		);
		do_action( 'rcp_stripe_sepa_migrated', rcp_get_membership( $this->membership_id ), 'pm_123' );

		$this->assertDoesNotMatchRegularExpression( '/[A-Z]{2}\d{2}[A-Z0-9]{11,}/', $this->all_text() );
	}

	public function test_le_contenu_envoye_est_filtrable(): void {
		add_filter(
			'rcp_stripe_sepa_email',
			static function ( array $message ): array {
				$message['subject'] = 'Objet personnalisé';

				return $message;
			}
		);

		$this->apply(
			Transition::to( null, StateMachine::PAYMENT_PENDING, 'Prélèvement engagé.' ),
			'payment_intent.processing'
		);

		$this->assertSame( 'Objet personnalisé', $this->sent[0]['subject'] );
	}

	public function test_un_envoi_declenche_une_action(): void {
		$fired = false;

		add_action(
			'rcp_stripe_sepa_email_sent',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		$this->apply(
			Transition::to( null, StateMachine::PAYMENT_PENDING, 'Prélèvement engagé.' ),
			'payment_intent.processing'
		);

		$this->assertTrue( $fired );
	}
}
