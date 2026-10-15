<?php
/**
 * Tests de l'ordonnancement des versions de l'API Stripe.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RCP_Stripe_Sepa\Support\StripeApiVersion;

/**
 * La forme d'une requête dépend de la version visée : une erreur de
 * comparaison ferait refuser toutes les intentions du plugin.
 *
 * @covers \RCP_Stripe_Sepa\Support\StripeApiVersion
 */
final class StripeApiVersionTest extends TestCase {

	// -- Lecture de la version ------------------------------------------------

	public function test_retient_la_date_d_une_version_nommee(): void {
		$this->assertSame( '2026-09-30', StripeApiVersion::date( '2026-09-30.endive' ) );
	}

	public function test_retient_la_date_d_une_version_sans_nom(): void {
		$this->assertSame( '2022-11-15', StripeApiVersion::date( '2022-11-15' ) );
	}

	public function test_ignore_les_espaces_autour_de_la_version(): void {
		$this->assertSame( '2026-09-30', StripeApiVersion::date( "  2026-09-30.endive\n" ) );
	}

	public function test_ne_lit_aucune_date_d_une_version_illisible(): void {
		$this->assertSame( '', StripeApiVersion::date( 'endive' ) );
	}

	// -- Choix du paramètre ---------------------------------------------------

	public function test_la_version_par_defaut_du_plugin_emploie_le_parametre_historique(): void {
		// Celle que le SDK embarqué par RCP sait servir aujourd'hui.
		$this->assertSame(
			'payment_method_types',
			StripeApiVersion::intent_payment_method_parameter( '2022-11-15' )
		);
	}

	public function test_la_version_endive_emploie_le_parametre_qui_lui_succede(): void {
		$this->assertSame(
			'allowed_payment_method_types',
			StripeApiVersion::intent_payment_method_parameter( '2026-09-30.endive' )
		);
	}

	/**
	 * Le retrait est entré en vigueur dans le canal bêta, un mois avant la
	 * version stable : le jalon lui-même bascule déjà.
	 */
	public function test_le_jalon_du_retrait_bascule_des_sa_propre_date(): void {
		$this->assertSame(
			'allowed_payment_method_types',
			StripeApiVersion::intent_payment_method_parameter( '2026-08-26.preview' )
		);
	}

	public function test_la_veille_du_retrait_conserve_le_parametre_historique(): void {
		$this->assertSame(
			'payment_method_types',
			StripeApiVersion::intent_payment_method_parameter( '2026-08-25' )
		);
	}

	/**
	 * Le cycle dahlia introduit le nouveau paramètre sans retirer l'ancien :
	 * rien n'impose d'en changer tant que le retrait n'a pas eu lieu.
	 */
	public function test_la_version_dahlia_conserve_le_parametre_historique(): void {
		$this->assertSame(
			'payment_method_types',
			StripeApiVersion::intent_payment_method_parameter( '2026-07-29.dahlia' )
		);
	}

	/**
	 * Mieux vaut la forme de la version par défaut qu'une forme devinée : une
	 * valeur illisible vient d'une constante mal renseignée dans
	 * `wp-config.php`, pas d'une version réelle de l'API.
	 */
	public function test_une_version_illisible_conserve_le_parametre_historique(): void {
		$this->assertSame(
			'payment_method_types',
			StripeApiVersion::intent_payment_method_parameter( 'dernière' )
		);
	}

	// -- Comparaison ----------------------------------------------------------

	public function test_une_version_posterieure_atteint_le_jalon(): void {
		$this->assertTrue( StripeApiVersion::is_at_least( '2027-01-15.figue', '2026-08-26' ) );
	}

	public function test_une_version_anterieure_n_atteint_pas_le_jalon(): void {
		$this->assertFalse( StripeApiVersion::is_at_least( '2020-08-27', '2026-08-26' ) );
	}
}
