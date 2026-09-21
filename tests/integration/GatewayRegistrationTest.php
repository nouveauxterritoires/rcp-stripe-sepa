<?php
/**
 * Tests d'intégration de l'enregistrement de la passerelle.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Integration;

use RCP_Payment_Gateways;
use RCP_Stripe_Sepa\Gateway\Gateway;
use RCP_Stripe_Sepa\Gateway\GatewayDefinition;
use RCP_Stripe_Sepa\Gateway\Registrar;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Gateway\Registrar
 * @covers \RCP_Stripe_Sepa\Gateway\Gateway
 * @covers \RCP_Stripe_Sepa\Gateway\GatewayDefinition
 */
final class GatewayRegistrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		global $rcp_options;

		$rcp_options = is_array( $rcp_options ) ? $rcp_options : array();

		$rcp_options['sandbox']  = 1;
		$rcp_options['currency'] = 'EUR';

		Registrar::register();
	}

	public function test_la_passerelle_est_declaree_a_rcp(): void {
		$gateways = new RCP_Payment_Gateways();

		$this->assertArrayHasKey( GatewayDefinition::ID, $gateways->available_gateways );
	}

	public function test_la_passerelle_carte_native_reste_disponible(): void {
		// Les deux passerelles doivent coexister sur le même site.
		$gateways = new RCP_Payment_Gateways();

		$this->assertArrayHasKey( 'stripe', $gateways->available_gateways );
		$this->assertArrayHasKey( 'manual', $gateways->available_gateways );
	}

	public function test_la_classe_declaree_existe_et_herite_de_la_passerelle_stripe(): void {
		$entry = ( new RCP_Payment_Gateways() )->available_gateways[ GatewayDefinition::ID ];

		$this->assertTrue( class_exists( $entry['class'] ) );
		$this->assertTrue( is_subclass_of( $entry['class'], 'RCP_Payment_Gateway_Stripe' ) );
	}

	public function test_la_passerelle_porte_des_libelles_traduits(): void {
		$entry = ( new RCP_Payment_Gateways() )->available_gateways[ GatewayDefinition::ID ];

		$this->assertNotSame( '', $entry['label'] );
		$this->assertStringContainsString( 'SEPA', $entry['label'] );
		$this->assertStringContainsString( 'SEPA', $entry['admin_label'] );
	}

	public function test_la_passerelle_est_activable_dans_les_reglages(): void {
		global $rcp_options;

		$rcp_options['gateways'] = array( GatewayDefinition::ID => 1 );

		$gateways = new RCP_Payment_Gateways();

		$this->assertTrue( $gateways->is_gateway_enabled( GatewayDefinition::ID ) );
	}

	// -- Capacités ---------------------------------------------------------------

	public function test_les_capacites_necessaires_sont_declarees(): void {
		$gateway = new Gateway();
		$gateway->init();

		foreach ( array( 'ajax-payment', 'recurring', 'one-time', 'gateway-submits-form' ) as $capability ) {
			$this->assertTrue( $gateway->supports( $capability ), 'Capacité manquante : ' . $capability );
		}
	}

	public function test_la_mise_a_jour_de_carte_n_est_pas_proposee(): void {
		/*
		 * Un mandat ne se met pas à jour, il se remplace. Déclarer
		 * `card-updates` afficherait à l'adhérent un formulaire de carte
		 * inopérant.
		 */
		$gateway = new Gateway();
		$gateway->init();

		$this->assertFalse( $gateway->supports( 'card-updates' ) );
	}

	public function test_les_capacites_ne_sont_pas_heritees_par_accumulation(): void {
		// `init()` remplace la liste : un appel répété ne doit pas la dupliquer.
		$gateway = new Gateway();
		$gateway->init();
		$gateway->init();

		$supports = new ReflectionProperty( 'RCP_Payment_Gateway', 'supports' );
		$supports->setAccessible( true );

		$declared = (array) $supports->getValue( $gateway );

		$this->assertSame( array_unique( $declared ), $declared );
		$this->assertSame( GatewayDefinition::supports(), $declared );
	}

	// -- Devise --------------------------------------------------------------------

	public function test_une_devise_autre_que_l_euro_est_refusee(): void {
		global $rcp_options;

		$rcp_options['currency'] = 'USD';

		$gateway = new Gateway();
		$gateway->init();
		$gateway->validate_fields();

		$errors = rcp_errors()->get_error_codes();

		$this->assertContains( 'rcp_stripe_sepa_invalid_currency', $errors );
	}

	// -- Arguments d'abonnement -------------------------------------------------------

	public function test_les_abonnements_de_la_passerelle_sont_restreints_au_sepa(): void {
		$args = apply_filters(
			'rcp_stripe_create_subscription_args',
			array( 'customer' => 'cus_1', 'plan' => 'plan_1' ),
			new Gateway()
		);

		$this->assertSame( array( 'sepa_debit' ), $args['payment_settings']['payment_method_types'] );
	}

	public function test_les_abonnements_de_la_passerelle_carte_ne_sont_pas_modifies(): void {
		// Régression critique : casser la passerelle carte du même site.
		$original = array( 'customer' => 'cus_1', 'plan' => 'plan_1' );

		$args = apply_filters(
			'rcp_stripe_create_subscription_args',
			$original,
			new \RCP_Payment_Gateway_Stripe()
		);

		$this->assertSame( $original, $args );
	}

	// -- Formulaire ---------------------------------------------------------------------

	public function test_le_formulaire_ne_contient_aucun_champ_iban_natif(): void {
		// SEC-12 : l'IBAN est saisi dans un Stripe Element, jamais dans un
		// champ dont la valeur serait soumise au serveur WordPress.
		$html = ( new Gateway() )->fields();

		$this->assertStringContainsString( 'rcp-stripe-sepa-iban-element', $html );
		$this->assertDoesNotMatchRegularExpression(
			'/<input[^>]*name="[^"]*iban[^"]*"/i',
			$html,
			'Un champ IBAN soumis au serveur a été trouvé.'
		);
	}

	public function test_le_formulaire_presente_le_mandat(): void {
		$html = ( new Gateway() )->fields();

		$this->assertStringContainsString( '8 semaines', $html, 'Droit au remboursement absent du mandat.' );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $html, 'Créancier non identifié.' );
	}

	public function test_le_texte_du_mandat_est_filtrable(): void {
		add_filter( 'rcp_stripe_sepa_mandate_text', static fn() => 'Mandat personnalisé.' );

		$this->assertStringContainsString( 'Mandat personnalisé.', ( new Gateway() )->fields() );
	}

	public function test_le_formulaire_est_accessible(): void {
		$html = ( new Gateway() )->fields();

		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
		$this->assertStringContainsString( 'for="rcp-stripe-sepa-holder-name"', $html );
	}
}
