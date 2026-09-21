<?php
/**
 * Rejeu des charges utiles réellement émises par Stripe.
 *
 * Les tests précédents fabriquent des événements ; ceux-ci rejouent les
 * charges utiles capturées sur un compte Stripe de test, telles que Stripe les
 * a émises. C'est ce qui protège des divergences entre la forme supposée des
 * objets et leur forme réelle — notamment la version d'API du compte, qui
 * diffère de celle que le plugin utilise dans ses propres requêtes.
 *
 * @package RCP_Stripe_Sepa
 * @see docs/environnement-stripe-test.md §4
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Webhooks;

use RCP_Stripe_Sepa\Membership\StateMachine;
use RCP_Stripe_Sepa\Webhook\EventStore;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Webhook\Endpoint
 * @covers \RCP_Stripe_Sepa\Webhook\EventProcessor
 */
final class RealPayloadsTest extends WP_UnitTestCase {

	use WebhookTestHelpers;

	public function set_up(): void {
		parent::set_up();

		$this->prepare_webhook_environment();
	}

	public function tear_down(): void {
		$this->reset_webhook_environment();

		parent::tear_down();
	}

	/**
	 * Charge une fixture capturée.
	 *
	 * @param string $name Nom de la fixture.
	 * @return array
	 */
	private function fixture( string $name ): array {
		$path = dirname( __DIR__ ) . '/fixtures/webhooks/' . $name . '.json';

		$this->assertFileExists( $path );

		$event = json_decode( (string) file_get_contents( $path ), true );

		$this->assertIsArray( $event, $name . ' : JSON invalide.' );

		// L'identifiant est réaffecté à chaque rejeu : sans cela, le verrou
		// d'idempotence ferait échouer le second test utilisant la fixture.
		$event['id']      = 'evt_replay_' . wp_generate_password( 16, false );
		$event['created'] = time();

		return $event;
	}

	/**
	 * Rattache une adhésion aux identifiants Stripe portés par une fixture.
	 *
	 * @param array  $event  Événement.
	 * @param string $status Statut initial de l'adhésion.
	 * @return int
	 */
	private function membership_for( array $event, string $status ): int {
		$object        = $event['data']['object'];
		$membership_id = $this->create_membership( 0, $status );
		$membership    = $this->reload_membership( $membership_id );

		$subscription = 'subscription' === ( $object['object'] ?? '' )
			? ( $object['id'] ?? '' )
			: ( $object['subscription'] ?? '' );

		$membership->update(
			array(
				'gateway_customer_id'     => is_string( $object['customer'] ?? null ) ? $object['customer'] : '',
				'gateway_subscription_id' => is_string( $subscription ) ? $subscription : '',
			)
		);

		return $membership_id;
	}

	// -- Le piège du faux échec ------------------------------------------------

	public function test_la_facture_en_echec_emise_a_la_creation_ne_resilie_rien(): void {
		/*
		 * Charge utile réelle : Stripe émet cet événement dès la création de
		 * l'abonnement, avant toute tentative de prélèvement, parce que la
		 * facture attend la confirmation du mandat. `attempt_count` vaut 0 et
		 * `charge` est nul.
		 */
		$event         = $this->fixture( 'invoice-payment-failed-at-creation' );
		$membership_id = $this->membership_for( $event, StateMachine::MEMBERSHIP_PENDING );

		$this->assertSame( 200, $this->send( $event )->get_status() );
		$this->assertSame( EventStore::STATUS_SKIPPED, $this->stored_status( $event['id'] ) );
		$this->assertSame(
			StateMachine::MEMBERSHIP_PENDING,
			$this->reload_membership( $membership_id )->get_status(),
			'Une adhésion valide a été résiliée par un faux échec.'
		);
	}

	public function test_la_facture_en_echec_apres_tentative_resilie_l_adhesion(): void {
		// Même type d'événement, même abonnement : seuls `attempt_count` et
		// `charge` distinguent un impayé réel.
		$event         = $this->fixture( 'invoice-payment-failed-real' );
		$membership_id = $this->membership_for( $event, StateMachine::MEMBERSHIP_PENDING );

		$this->send( $event );

		$this->assertSame( EventStore::STATUS_PROCESSED, $this->stored_status( $event['id'] ) );
		$this->assertSame(
			StateMachine::MEMBERSHIP_CANCELLED,
			$this->reload_membership( $membership_id )->get_status()
		);
	}

	// -- Cycle nominal ----------------------------------------------------------

	public function test_un_prelevement_reel_en_cours_laisse_l_adhesion_en_attente(): void {
		$event         = $this->fixture( 'payment-intent-processing' );
		$membership_id = $this->membership_for( $event, StateMachine::MEMBERSHIP_PENDING );

		$this->send( $event );

		$this->assertSame(
			StateMachine::MEMBERSHIP_PENDING,
			$this->reload_membership( $membership_id )->get_status()
		);
	}

	public function test_un_prelevement_reel_abouti_active_l_adhesion(): void {
		$event         = $this->fixture( 'payment-intent-succeeded' );
		$membership_id = $this->membership_for( $event, StateMachine::MEMBERSHIP_PENDING );

		$this->send( $event );

		$this->assertSame(
			StateMachine::MEMBERSHIP_ACTIVE,
			$this->reload_membership( $membership_id )->get_status()
		);
	}

	public function test_une_facture_reellement_reglee_active_l_adhesion(): void {
		$event         = $this->fixture( 'invoice-paid' );
		$membership_id = $this->membership_for( $event, StateMachine::MEMBERSHIP_PENDING );

		$this->send( $event );

		$this->assertSame(
			StateMachine::MEMBERSHIP_ACTIVE,
			$this->reload_membership( $membership_id )->get_status()
		);
	}

	public function test_un_prelevement_reel_refuse_resilie_l_adhesion(): void {
		$event         = $this->fixture( 'payment-intent-payment-failed' );
		$membership_id = $this->membership_for( $event, StateMachine::MEMBERSHIP_PENDING );

		$this->send( $event );

		$this->assertSame(
			StateMachine::MEMBERSHIP_CANCELLED,
			$this->reload_membership( $membership_id )->get_status()
		);
	}

	// -- Robustesse ------------------------------------------------------------

	/**
	 * @dataProvider provide_fixtures
	 *
	 * @param string $name Nom de la fixture.
	 */
	public function test_toute_charge_utile_reelle_est_acquittee( string $name ): void {
		// Aucune charge utile réelle ne doit provoquer d'erreur : un 500 ferait
		// rejouer l'événement indéfiniment par Stripe.
		$event = $this->fixture( $name );

		$this->membership_for( $event, StateMachine::MEMBERSHIP_PENDING );

		$status = $this->send( $event )->get_status();

		$this->assertLessThan( 300, $status, $name . ' a produit un HTTP ' . $status );
		$this->assertContains(
			$this->stored_status( $event['id'] ),
			array( EventStore::STATUS_PROCESSED, EventStore::STATUS_SKIPPED ),
			$name . ' : issue inattendue.'
		);
	}

	/**
	 * Toutes les fixtures du dépôt.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_fixtures(): array {
		$cases = array();

		foreach ( glob( dirname( __DIR__ ) . '/fixtures/webhooks/*.json' ) as $path ) {
			$name           = basename( $path, '.json' );
			$cases[ $name ] = array( $name );
		}

		return $cases;
	}
}
