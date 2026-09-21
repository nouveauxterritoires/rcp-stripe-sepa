<?php
/**
 * Tests de la composition des e-mails transactionnels.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Email;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Email\MessageFactory;

/**
 * @covers \RCP_Stripe_Sepa\Email\MessageFactory
 */
final class MessageFactoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Contexte d'envoi.
	 *
	 * @param array $overrides Valeurs à remplacer.
	 * @return array
	 */
	private function context( array $overrides = array() ): array {
		return array_merge(
			array(
				'site_name'  => 'Association Test',
				'level_name' => 'Adhésion mensuelle',
				'delay_days' => 14,
				'reason'     => '',
			),
			$overrides
		);
	}

	// -- Messages destinés à l'adhérent ------------------------------------------

	public function test_le_message_de_prelevement_engage_explique_le_delai(): void {
		/*
		 * C'est le message le plus important : sans lui, l'adhérent croit que
		 * son adhésion est active et s'étonne de ne pas accéder au contenu.
		 */
		$message = MessageFactory::build( MessageFactory::DEBIT_INITIATED, $this->context() );

		$this->assertStringContainsString( 'Adhésion mensuelle', $message['subject'] . $message['body'] );
		$this->assertStringContainsString( '14', $message['body'] );
		$this->assertNotSame( '', $message['subject'] );
	}

	public function test_le_message_de_refus_indique_le_motif_quand_il_est_connu(): void {
		$message = MessageFactory::build(
			MessageFactory::DEBIT_FAILED,
			$this->context( array( 'reason' => 'Provision insuffisante' ) )
		);

		$this->assertStringContainsString( 'Provision insuffisante', $message['body'] );
	}

	public function test_le_message_de_refus_reste_lisible_sans_motif(): void {
		$message = MessageFactory::build( MessageFactory::DEBIT_FAILED, $this->context() );

		$this->assertNotSame( '', $message['body'] );
		$this->assertStringNotContainsString( '  ', trim( $message['body'] ), 'Espaces doubles laissés par un motif vide.' );
	}

	public function test_le_message_de_mandat_confirme_la_bascule(): void {
		$message = MessageFactory::build( MessageFactory::MANDATE_UPDATED, $this->context() );

		$this->assertStringContainsString( 'Association Test', $message['body'] );
	}

	// -- Messages destinés à l'administrateur ---------------------------------------

	public function test_l_alerte_de_litige_identifie_l_adhesion(): void {
		$message = MessageFactory::build(
			MessageFactory::DISPUTE_OPENED,
			$this->context( array( 'membership_id' => 42 ) )
		);

		$this->assertStringContainsString( '42', $message['body'] );
	}

	public function test_l_alerte_d_evenement_abandonne_identifie_l_evenement(): void {
		$message = MessageFactory::build(
			MessageFactory::EVENT_ABANDONED,
			$this->context( array( 'event_id' => 'evt_123', 'event_type' => 'invoice.paid' ) )
		);

		$this->assertStringContainsString( 'evt_123', $message['body'] );
		$this->assertStringContainsString( 'invoice.paid', $message['body'] );
	}

	// -- Invariants ---------------------------------------------------------------------

	/**
	 * @dataProvider provide_message_types
	 *
	 * @param string $type Type de message.
	 */
	public function test_chaque_message_a_un_objet_et_un_corps( string $type ): void {
		$message = MessageFactory::build( $type, $this->context() );

		$this->assertNotSame( '', trim( $message['subject'] ), $type );
		$this->assertNotSame( '', trim( $message['body'] ), $type );
	}

	/**
	 * @dataProvider provide_message_types
	 *
	 * @param string $type Type de message.
	 */
	public function test_aucun_message_ne_contient_de_donnee_bancaire( string $type ): void {
		// SEC-03 : un e-mail traverse des serveurs tiers et reste archivé.
		$message = MessageFactory::build(
			$type,
			$this->context( array( 'iban' => 'FR1420041010050500013M02606' ) )
		);

		$this->assertStringNotContainsString( 'FR1420041010050500013M02606', $message['subject'] . $message['body'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provide_message_types(): array {
		$cases = array();

		foreach ( MessageFactory::types() as $type ) {
			$cases[ $type ] = array( $type );
		}

		return $cases;
	}

	public function test_un_type_inconnu_ne_produit_aucun_message(): void {
		$this->assertSame( array(), MessageFactory::build( 'type_inexistant', $this->context() ) );
	}

	public function test_le_contexte_fourni_n_est_pas_mute(): void {
		$context = $this->context();
		$copy    = $context;

		MessageFactory::build( MessageFactory::DEBIT_INITIATED, $context );

		$this->assertSame( $copy, $context );
	}
}
