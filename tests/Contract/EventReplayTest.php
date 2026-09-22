<?php
/**
 * Tests de contrat du rejeu manuel d'événement.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Contract;

use RCP_Stripe_Sepa\Admin\EventReplay;
use RCP_Stripe_Sepa\Tests\Support\RcpFixtures;
use RCP_Stripe_Sepa\Webhook\EventStore;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Admin\EventReplay
 * @group contract
 */
final class EventReplayTest extends WP_UnitTestCase {

	use RcpFixtures;

	public function set_up(): void {
		parent::set_up();

		$this->configure_rcp_stripe();
		$this->redirect_stripe_to_mock();

		EventStore::install();
	}

	public function tear_down(): void {
		$this->restore_stripe_api_base();

		parent::tear_down();
	}

	// -- Refus ------------------------------------------------------------------

	public function test_un_identifiant_vide_est_refuse(): void {
		$result = EventReplay::replay( '' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'rcp_stripe_sepa_invalid_event', $result->get_error_code() );
	}

	public function test_un_identifiant_qui_n_est_pas_un_evenement_est_refuse(): void {
		// Un identifiant de PaymentIntent transmis par erreur — ou à dessein —
		// ne doit pas déclencher d'appel à Stripe.
		$result = EventReplay::replay( 'pi_123456789' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'rcp_stripe_sepa_invalid_event', $result->get_error_code() );
	}

	public function test_un_appel_a_stripe_en_echec_est_refuse_proprement(): void {
		/*
		 * stripe-mock répond à n'importe quel identifiant d'événement : le cas
		 * « introuvable » ne peut pas y être reproduit. L'échec d'appel est
		 * donc provoqué en visant un port fermé, ce qui emprunte exactement la
		 * même branche — le rejeu doit renvoyer une erreur, jamais propager
		 * l'exception jusqu'à l'écran d'administration.
		 */
		\Stripe\Stripe::$apiBase = 'http://127.0.0.1:1';

		$result = EventReplay::replay( 'evt_123456789' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'rcp_stripe_sepa_event_unreadable', $result->get_error_code() );
	}

	public function test_un_evenement_de_l_autre_mode_est_refuse(): void {
		/*
		 * SEC-09 : rejouer en mode test un événement de production activerait
		 * des adhésions sur la foi de paiements qui n'existent pas ici.
		 */
		global $rcp_options;

		$rcp_options['sandbox'] = 0;

		$result = EventReplay::replay( 'evt_123456789' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'rcp_stripe_sepa_mode_mismatch', $result->get_error_code() );
	}

	// -- Rejeu effectif -------------------------------------------------------------

	public function test_un_evenement_du_bon_mode_est_rejoue(): void {
		$result = EventReplay::replay( 'evt_123456789' );

		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertNotSame( '', (string) $result );
	}

	public function test_le_rejeu_est_consigne_dans_le_journal_des_evenements(): void {
		EventReplay::replay( 'evt_123456789' );

		$recent = EventStore::recent( 5 );
		$ids    = wp_list_pluck( $recent, 'event_id' );

		$this->assertContains( 'evt_123456789', $ids );
	}

	public function test_un_rejeu_repete_n_accumule_pas_de_lignes(): void {
		// Le journal garde une ligne par événement, quel que soit le nombre de
		// rejeux : c'est le compteur de tentatives qui progresse.
		EventReplay::replay( 'evt_123456789' );
		EventReplay::replay( 'evt_123456789' );

		$matching = array_filter(
			EventStore::recent( 20 ),
			static function ( array $row ): bool {
				return 'evt_123456789' === $row['event_id'];
			}
		);

		$this->assertCount( 1, $matching );
	}

	public function test_un_evenement_non_gere_est_consigne_comme_ignore(): void {
		// stripe-mock renvoie un `plan.created`, que le plugin ne traite pas.
		EventReplay::replay( 'evt_123456789' );

		$row = array_values(
			array_filter(
				EventStore::recent( 20 ),
				static function ( array $candidate ): bool {
					return 'evt_123456789' === $candidate['event_id'];
				}
			)
		)[0];

		$this->assertSame( EventStore::STATUS_SKIPPED, $row['status'] );
	}
}
