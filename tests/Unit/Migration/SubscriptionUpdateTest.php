<?php
/**
 * Tests de la charge utile envoyée à Stripe lors d'une bascule.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Gateway\IntentFactory;
use RCP_Stripe_Sepa\Migration\Migrator;

/**
 * @covers \RCP_Stripe_Sepa\Migration\Migrator::subscription_update_args
 */
final class SubscriptionUpdateTest extends TestCase {

	public function test_le_nouveau_mandat_devient_le_moyen_de_paiement_par_defaut(): void {
		$args = Migrator::subscription_update_args( 'pm_sepa_123' );

		$this->assertSame( 'pm_sepa_123', $args['default_payment_method'] );
	}

	public function test_l_abonnement_est_restreint_au_prelevement_sepa(): void {
		$args = Migrator::subscription_update_args( 'pm_sepa_123' );

		$this->assertSame(
			array( IntentFactory::PAYMENT_METHOD_TYPE ),
			$args['payment_settings']['payment_method_types']
		);
	}

	/**
	 * @group RG-06
	 */
	public function test_aucune_proratisation_n_est_demandee(): void {
		$args = Migrator::subscription_update_args( 'pm_sepa_123' );

		$this->assertSame( 'none', $args['proration_behavior'] );
	}

	/**
	 * Une facture déjà émise porte son propre moyen de paiement. Changer le
	 * défaut de l'abonnement ne la touche pas — à condition de ne rien envoyer
	 * qui la concerne.
	 *
	 * @dataProvider provide_invoice_keys
	 * @group RG-08
	 *
	 * @param string $key Clé qui affecterait une facture en cours.
	 */
	public function test_aucune_facture_en_cours_n_est_affectee( string $key ): void {
		$args = Migrator::subscription_update_args( 'pm_sepa_123' );

		$this->assertArrayNotHasKey( $key, $args );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provide_invoice_keys(): array {
		return array(
			'facturation immédiate'    => array( 'billing_cycle_anchor' ),
			'échéance de facture'      => array( 'days_until_due' ),
			'collecte automatique'     => array( 'collection_method' ),
			'facture en attente'       => array( 'pending_invoice_item_interval' ),
			'date de fin d’essai'      => array( 'trial_end' ),
			'articles de l’abonnement' => array( 'items' ),
		);
	}

	/**
	 * Le code de bascule ne doit solliciter aucun objet `Invoice` : c'est ce
	 * qui garantit RG-08 mieux qu'un argument absent.
	 *
	 * @group RG-08
	 */
	public function test_la_bascule_ne_manipule_aucune_facture(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Migration/Migrator.php' );

		$this->assertStringNotContainsString( '\Stripe\Invoice', $source );
		$this->assertStringNotContainsString( 'Invoice::', $source );
	}

	public function test_la_charge_utile_reste_etroite(): void {
		$args = Migrator::subscription_update_args( 'pm_sepa_123' );

		$this->assertSame(
			array( 'default_payment_method', 'payment_settings', 'proration_behavior' ),
			array_keys( $args ),
			'Tout ajout ici doit être pesé : la bascule ne doit rien changer d’autre.'
		);
	}
}
