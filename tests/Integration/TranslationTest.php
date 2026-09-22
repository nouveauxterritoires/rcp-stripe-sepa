<?php
/**
 * Tests de l'internationalisation.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Integration;

use MO;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
final class TranslationTest extends WP_UnitTestCase {

	/**
	 * Répertoire des traductions.
	 *
	 * @return string
	 */
	private function languages_dir(): string {
		return dirname( __DIR__, 2 ) . '/languages';
	}

	/**
	 * Charge le catalogue français.
	 *
	 * @return MO
	 */
	private function french_catalogue(): MO {
		$path = $this->languages_dir() . '/rcp-stripe-sepa-fr_FR.mo';

		$this->assertFileExists( $path, 'La traduction française doit être compilée et versionnée.' );

		$catalogue = new MO();
		$catalogue->import_from_file( $path );

		return $catalogue;
	}

	// -- Présence des fichiers ----------------------------------------------------

	public function test_le_modele_de_traduction_est_versionne(): void {
		$this->assertFileExists( $this->languages_dir() . '/rcp-stripe-sepa.pot' );
	}

	public function test_la_traduction_francaise_est_compilee(): void {
		// Un .po non compilé n'est jamais chargé par WordPress.
		$this->assertFileExists( $this->languages_dir() . '/rcp-stripe-sepa-fr_FR.po' );
		$this->assertFileExists( $this->languages_dir() . '/rcp-stripe-sepa-fr_FR.mo' );
	}

	// -- Couverture de la traduction ------------------------------------------------

	public function test_la_traduction_couvre_les_chaines_du_modele(): void {
		$catalogue = $this->french_catalogue();
		$template  = file_get_contents( $this->languages_dir() . '/rcp-stripe-sepa.pot' );

		preg_match_all( '/^msgid "((?:[^"\\\\]|\\\\.)+)"$/m', (string) $template, $matches );

		$missing = array();

		foreach ( $matches[1] as $msgid ) {
			$decoded = stripcslashes( $msgid );

			// Les valeurs d'en-tête du plugin ne se traduisent pas.
			if ( 'Nouveaux Territoires' === $decoded || 0 === strpos( $decoded, 'https://' ) ) {
				continue;
			}

			if ( ! isset( $catalogue->entries[ $decoded ] ) ) {
				$missing[] = $decoded;
			}
		}

		$this->assertSame( array(), $missing, 'Chaînes sans traduction française.' );
	}

	public function test_aucune_traduction_n_est_vide(): void {
		foreach ( $this->french_catalogue()->entries as $singular => $entry ) {
			$this->assertNotSame( '', trim( (string) $entry->translations[0] ), $singular );
		}
	}

	// -- Intégrité des interpolations -------------------------------------------------

	public function test_les_marqueurs_de_substitution_sont_preserves(): void {
		/*
		 * Une traduction qui perd un `%s` ou en intervertit l'ordre produit une
		 * erreur de `sprintf()` en production, c'est-à-dire un message
		 * tronqué — ou un avertissement PHP sur la page.
		 */
		foreach ( $this->french_catalogue()->entries as $singular => $entry ) {
			foreach ( $entry->translations as $translation ) {
				$this->assertSame(
					$this->placeholders( (string) $singular ),
					$this->placeholders( (string) $translation ),
					'Marqueurs divergents pour : ' . $singular
				);
			}
		}
	}

	/**
	 * Marqueurs de substitution d'une chaîne, triés.
	 *
	 * @param string $text Chaîne analysée.
	 * @return string[]
	 */
	private function placeholders( string $text ): array {
		preg_match_all( '/%(?:\d+\$)?[sd]/', $text, $matches );

		$found = $matches[0];

		sort( $found );

		return $found;
	}

	// -- Langue source -------------------------------------------------------------------

	public function test_les_chaines_source_sont_en_anglais(): void {
		/*
		 * Le code, les commentaires et la documentation sont en français ; les
		 * chaînes visibles, elles, suivent l'usage de WordPress. Ce test
		 * empêche qu'une chaîne française réapparaisse par inadvertance.
		 */
		$template = (string) file_get_contents( $this->languages_dir() . '/rcp-stripe-sepa.pot' );

		preg_match_all( '/^msgid "((?:[^"\\\\]|\\\\.)+)"$/m', $template, $matches );

		$suspects = array();

		foreach ( $matches[1] as $msgid ) {
			if ( preg_match( '/[éèêàçùôûî]/u', stripcslashes( $msgid ) ) ) {
				$suspects[] = $msgid;
			}
		}

		$this->assertSame( array(), $suspects, 'Chaînes source encore en français.' );
	}

	public function test_le_plugin_declare_son_repertoire_de_traductions(): void {
		$headers = get_file_data(
			dirname( __DIR__, 2 ) . '/rcp-stripe-sepa.php',
			array(
				'TextDomain' => 'Text Domain',
				'DomainPath' => 'Domain Path',
			)
		);

		$this->assertSame( 'rcp-stripe-sepa', $headers['TextDomain'] );
		$this->assertSame( '/languages', $headers['DomainPath'] );
	}
}
