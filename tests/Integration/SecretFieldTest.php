<?php
/**
 * Tests du champ de réglage du secret de webhook.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Integration;

use RCP_Stripe_Sepa\Admin\DiagnosticsPage;
use RCP_Stripe_Sepa\Admin\SecretField;
use RCP_Stripe_Sepa\Tests\Support\RcpFixtures;
use RCP_Stripe_Sepa\Webhook\WebhookSecret;
use WP_UnitTestCase;

/**
 * @covers \RCP_Stripe_Sepa\Admin\SecretField
 */
final class SecretFieldTest extends WP_UnitTestCase {

	use RcpFixtures;

	/**
	 * Place le site en mode test et efface tout secret résiduel.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $rcp_options;

		$rcp_options            = is_array( $rcp_options ) ? $rcp_options : array();
		$rcp_options['sandbox'] = true;

		delete_option( WebhookSecret::OPTION_TEST );
	}

	/**
	 * Un champ pré-rempli livrerait le secret à l'inspecteur du navigateur et
	 * au gestionnaire de mots de passe.
	 *
	 * @group SEC-03
	 */
	public function test_le_champ_est_masque_et_jamais_pre_rempli(): void {
		update_option( WebhookSecret::OPTION_TEST, 'whsec_valeur_en_place' );

		$attributes = SecretField::attributes();

		$this->assertSame( 'password', $attributes['type'] );
		$this->assertSame( 'off', $attributes['autocomplete'] );
		$this->assertSame( '', $attributes['value'] );
	}

	/**
	 * @group SEC-03
	 */
	public function test_l_ecran_ne_revele_jamais_le_secret(): void {
		update_option( WebhookSecret::OPTION_TEST, 'whsec_valeur_en_place' );

		wp_set_current_user( $this->create_rcp_admin() );
		set_current_screen( 'toplevel_page_' . DiagnosticsPage::PAGE_SLUG );

		ob_start();
		DiagnosticsPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'whsec_valeur_en_place', $html );
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringContainsString( 'autocomplete="off"', $html );
	}

	/**
	 * @group SEC-03
	 */
	public function test_un_champ_laisse_vide_conserve_le_secret(): void {
		update_option( WebhookSecret::OPTION_TEST, 'whsec_valeur_en_place' );

		$result = SecretField::save( '' );

		$this->assertSame( 'info', $result['type'] );
		$this->assertSame( 'whsec_valeur_en_place', get_option( WebhookSecret::OPTION_TEST ) );
	}

	public function test_une_valeur_soumise_remplace_le_secret(): void {
		$result = SecretField::save( 'whsec_nouvelle_valeur' );

		$this->assertSame( 'success', $result['type'] );
		$this->assertSame( 'whsec_nouvelle_valeur', get_option( WebhookSecret::OPTION_TEST ) );
	}

	/**
	 * Une valeur mal recopiée — une clé d'API au lieu du secret, par exemple —
	 * ferait échouer toutes les signatures sans rien indiquer.
	 */
	public function test_une_valeur_qui_n_est_pas_un_secret_est_refusee(): void {
		$result = SecretField::save( 'sk_test_ceci_est_une_cle' );

		$this->assertSame( 'error', $result['type'] );
		$this->assertFalse( get_option( WebhookSecret::OPTION_TEST, false ) );
	}

	/**
	 * Une constante de `wp-config.php` prévaut : proposer la saisie laisserait
	 * croire à une modification qui n'aurait aucun effet.
	 *
	 * @group SEC-03
	 */
	public function test_un_secret_verrouille_par_constante_refuse_la_saisie(): void {
		$result = SecretField::save( 'whsec_tentative_par_l_ecran', true );

		$this->assertSame( 'error', $result['type'] );
		$this->assertStringContainsString( WebhookSecret::CONSTANT_TEST, $result['message'] );
		$this->assertFalse( get_option( WebhookSecret::OPTION_TEST, false ) );
	}

}
