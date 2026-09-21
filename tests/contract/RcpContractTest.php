<?php
/**
 * Tests de contrat avec Restrict Content Pro.
 *
 * Ces tests s'exécutent contre l'installation réelle de RCP — variante libre
 * ou variante commerciale, indifféremment. Ils constituent le filet de sécurité
 * du choix d'architecture retenu (héritage de `RCP_Payment_Gateway_Stripe`) :
 * toute rupture d'API amont doit être détectée ici, en intégration continue,
 * avant d'atteindre un site en production.
 *
 * @package RCP_Stripe_Sepa
 * @see docs/adr/0001-strategie-integration-rcp.md
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Contract;

use ReflectionClass;
use ReflectionMethod;
use RCP_Stripe_Sepa\Compat\RcpEnvironment;
use WP_UnitTestCase;

/**
 * @group contract
 */
final class RcpContractTest extends WP_UnitTestCase {

	/**
	 * Le registre des passerelles doit être instancié pour que RCP charge les
	 * fichiers de la passerelle Stripe et ses fonctions utilitaires.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		if ( class_exists( 'RCP_Payment_Gateways' ) ) {
			new \RCP_Payment_Gateways();
		}
	}

	public function test_restrict_content_pro_est_charge(): void {
		$this->assertTrue(
			class_exists( 'RCP_Payment_Gateway' ),
			'Restrict Content Pro (ou Restrict Content) doit être actif pour exécuter la suite de contrat.'
		);
	}

	public function test_l_environnement_detecte_est_supporte(): void {
		$environment = RcpEnvironment::detect();

		$this->assertTrue(
			$environment->is_supported(),
			sprintf(
				"Environnement non supporté. Variante : %s, noyau : %s, SDK Stripe : %s. Anomalies : %s",
				$environment->variant(),
				(string) $environment->core_version(),
				(string) $environment->stripe_sdk_version(),
				implode( ', ', $environment->issues() )
			)
		);
	}

	public function test_la_variante_detectee_est_connue(): void {
		// Trace la variante testée dans la sortie de CI : la matrice couvre
		// la version libre et la version commerciale.
		$variant = RcpEnvironment::detect()->variant();

		$this->assertContains(
			$variant,
			array( RcpEnvironment::VARIANT_FREE, RcpEnvironment::VARIANT_PRO ),
			'La variante de RCP installée n\'a pas pu être identifiée.'
		);
	}

	/**
	 * @dataProvider provide_required_methods
	 *
	 * @param string $class  Nom de la classe.
	 * @param string $method Nom de la méthode.
	 */
	public function test_les_methodes_surchargees_existent_et_sont_publiques( string $class, string $method ): void {
		$this->assertTrue( method_exists( $class, $method ), "{$class}::{$method}() a disparu." );

		$reflection = new ReflectionMethod( $class, $method );

		$this->assertTrue(
			$reflection->isPublic() || $reflection->isProtected(),
			"{$class}::{$method}() n'est plus surchargeable."
		);

		$this->assertFalse( $reflection->isFinal(), "{$class}::{$method}() est devenue finale." );
		$this->assertFalse( $reflection->isStatic(), "{$class}::{$method}() est devenue statique." );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_required_methods(): array {
		$cases = array();

		foreach ( RcpEnvironment::REQUIRED_METHODS as $reference ) {
			list( $class, $method ) = explode( '::', $reference, 2 );

			$cases[ $reference ] = array( $class, $method );
		}

		return $cases;
	}

	public function test_la_passerelle_stripe_n_est_pas_finale(): void {
		// L'héritage est la pierre angulaire de l'architecture : si RCP rendait
		// cette classe finale, le plugin ne pourrait plus fonctionner.
		$this->assertFalse(
			( new ReflectionClass( 'RCP_Payment_Gateway_Stripe' ) )->isFinal(),
			'RCP_Payment_Gateway_Stripe est devenue finale : l\'ADR-0001 doit être revu.'
		);
	}

	public function test_la_passerelle_stripe_herite_de_la_classe_abstraite(): void {
		$this->assertTrue(
			is_subclass_of( 'RCP_Payment_Gateway_Stripe', 'RCP_Payment_Gateway' ),
			'La hiérarchie de classes de RCP a changé.'
		);
	}

	public function test_les_capacites_attendues_sont_declarees_par_la_passerelle_stripe(): void {
		// `ajax-payment` conditionne l'appel à process_ajax_signup(), sur lequel
		// repose toute la création d'intention SEPA.
		$gateway = new \RCP_Payment_Gateway_Stripe();
		$gateway->init();

		$supports = new \ReflectionProperty( 'RCP_Payment_Gateway', 'supports' );
		$supports->setAccessible( true );

		$declared = (array) $supports->getValue( $gateway );

		foreach ( array( 'ajax-payment', 'recurring', 'one-time', 'gateway-submits-form', 'card-updates' ) as $capability ) {
			$this->assertContains( $capability, $declared, "La capacité « {$capability} » n'est plus déclarée." );
		}
	}

	public function test_le_filtre_d_enregistrement_des_passerelles_est_applique(): void {
		$marker = 'rcp_stripe_sepa_contract_probe';

		add_filter(
			'rcp_payment_gateways',
			static function ( $gateways ) use ( $marker ) {
				$gateways[ $marker ] = array(
					'label'       => 'Sonde',
					'admin_label' => 'Sonde',
					'class'       => 'RCP_Payment_Gateway_Manual',
				);

				return $gateways;
			}
		);

		$gateways = new \RCP_Payment_Gateways();

		$this->assertArrayHasKey(
			$marker,
			$gateways->available_gateways,
			'Le filtre rcp_payment_gateways ne permet plus d\'enregistrer une passerelle.'
		);
	}

	public function test_la_generation_de_cle_d_idempotence_est_disponible(): void {
		$this->assertTrue( function_exists( 'rcp_stripe_generate_idempotency_key' ) );

		$key = rcp_stripe_generate_idempotency_key( array( 'amount' => 1000, 'currency' => 'eur' ) );

		$this->assertIsString( $key );
		$this->assertNotSame( '', $key );
	}

	public function test_le_sdk_stripe_expose_les_objets_necessaires_au_prelevement_sepa(): void {
		foreach ( array( '\Stripe\PaymentIntent', '\Stripe\SetupIntent', '\Stripe\PaymentMethod', '\Stripe\Mandate', '\Stripe\Subscription', '\Stripe\Webhook' ) as $class ) {
			$this->assertTrue( class_exists( $class ), "{$class} est absente du SDK Stripe fourni par RCP." );
		}
	}

	public function test_la_version_d_api_du_plugin_est_compatible_avec_le_sdk_embarque(): void {
		// R-API-2 : le plugin transmet sa propre version d'API à chaque requête.
		// Elle ne doit jamais dépasser celle que le SDK embarqué sait décoder.
		$this->assertTrue( defined( 'RCP_SEPA_STRIPE_API_VERSION' ) );

		$sdk_version = \Stripe\Util\ApiVersion::CURRENT;

		$this->assertLessThanOrEqual(
			$sdk_version,
			RCP_SEPA_STRIPE_API_VERSION,
			sprintf(
				'RCP_SEPA_STRIPE_API_VERSION (%s) est postérieure à la version du SDK embarqué (%s).',
				RCP_SEPA_STRIPE_API_VERSION,
				$sdk_version
			)
		);
	}

	public function test_rcp_ne_modifie_pas_la_version_d_api_attendue_par_le_plugin(): void {
		// R-API-1 : RCP fixe la version globale. Le plugin ne doit pas s'y fier,
		// ce test documente et surveille la valeur imposée.
		$gateway = new \RCP_Payment_Gateway_Stripe();
		$gateway->init();

		$this->assertNotEmpty(
			\Stripe\Stripe::getApiVersion(),
			'RCP ne fixe plus de version d\'API globale : vérifier que le plugin transmet toujours la sienne.'
		);
	}
}
