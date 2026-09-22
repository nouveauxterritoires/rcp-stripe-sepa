<?php
/**
 * Exigences portant sur la chaîne d'approvisionnement et la désinstallation.
 *
 * Ces exigences ne se vérifient pas en sollicitant une fonction : elles portent
 * sur l'état du dépôt et sur ce que le plugin charge. Elles sont donc éprouvées
 * en lisant les fichiers livrés, ce qu'aucune relecture humaine ne fera à
 * chaque version.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Integration;

use WP_UnitTestCase;

/**
 * @coversNothing
 */
final class SupplyChainTest extends WP_UnitTestCase {

	/**
	 * Racine du plugin.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Fichiers PHP et JavaScript livrés.
	 *
	 * @return list<string>
	 */
	private function shipped_sources(): array {
		$files = array();

		foreach ( array( '/src', '/templates', '/assets' ) as $dir ) {
			$path = $this->root() . $dir;

			if ( ! is_dir( $path ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path ) );

			foreach ( $iterator as $file ) {
				if ( $file->isFile() && in_array( $file->getExtension(), array( 'php', 'js', 'css' ), true ) ) {
					$files[] = (string) $file->getPathname();
				}
			}
		}

		$files[] = $this->root() . '/rcp-stripe-sepa.php';
		$files[] = $this->root() . '/uninstall.php';

		return $files;
	}

	/**
	 * @group SEC-26
	 */
	public function test_les_versions_des_dependances_sont_verrouillees(): void {
		foreach ( array( 'composer.lock', 'package-lock.json' ) as $lock ) {
			$path = $this->root() . '/' . $lock;

			$this->assertFileExists( $path, $lock . ' doit être versionné.' );
			$this->assertNotEmpty( (string) file_get_contents( $path ) );
		}

		$ignored = (string) file_get_contents( $this->root() . '/.gitignore' );

		foreach ( array( 'composer.lock', 'package-lock.json' ) as $lock ) {
			$this->assertDoesNotMatchRegularExpression(
				'/^\/?' . preg_quote( $lock, '/' ) . '\s*$/m',
				$ignored,
				$lock . ' ne doit pas être ignoré par Git.'
			);
		}
	}

	/**
	 * Stripe interdit d'héberger `js.stripe.com` soi-même : c'est la seule
	 * origine tierce tolérée. Toute autre trahirait une dépendance que le site
	 * ne maîtrise pas, et qui pourrait servir un script modifié.
	 *
	 * Seules les URL en position de chargement sont examinées : celles d'un
	 * en-tête de plugin ou d'un lien de documentation ne font rien charger.
	 *
	 * @group SEC-27
	 */
	public function test_aucune_ressource_tierce_hors_stripe(): void {
		$patterns = array(
			'~<script[^>]+src\s*=\s*[\'"](https?://[^\'"]+)~i',
			'~<link[^>]+href\s*=\s*[\'"](https?://[^\'"]+)~i',
			'~<img[^>]+src\s*=\s*[\'"](https?://[^\'"]+)~i',
			'~@import\s+[\'"](https?://[^\'"]+)~i',
			'~\bfetch\(\s*[\'"](https?://[^\'"]+)~i',
		);

		$offenders = array();

		foreach ( $this->shipped_sources() as $file ) {
			$source = (string) file_get_contents( $file );

			foreach ( $patterns as $pattern ) {
				if ( ! preg_match_all( $pattern, $source, $matches ) ) {
					continue;
				}

				foreach ( $matches[1] as $url ) {
					if ( str_starts_with( $url, 'https://js.stripe.com/' ) ) {
						continue;
					}

					$offenders[] = basename( $file ) . ' → ' . $url;
				}
			}
		}

		$this->assertSame(
			array(),
			array_unique( $offenders ),
			"Ressource tierce inattendue :\n" . implode( "\n", array_unique( $offenders ) )
		);
	}

	/**
	 * @group SEC-27
	 */
	public function test_seul_le_script_de_stripe_est_charge_depuis_un_tiers(): void {
		$external = array();

		foreach ( $this->shipped_sources() as $file ) {
			$source = (string) file_get_contents( $file );

			if ( preg_match_all( '#wp_(?:enqueue|register)_(?:script|style)\(\s*[^,]+,\s*[\'"](https?://[^\'"]+)#i', $source, $matches ) ) {
				$external = array_merge( $external, $matches[1] );
			}
		}

		$this->assertNotEmpty( $external, 'Le script de Stripe doit bien être chargé depuis Stripe.' );

		foreach ( array_unique( $external ) as $url ) {
			$this->assertStringStartsWith(
				'https://js.stripe.com/',
				$url,
				'Seul js.stripe.com peut être chargé depuis un tiers.'
			);
		}
	}

	/**
	 * L'IBAN complet ne doit jamais atteindre le serveur : il est saisi dans
	 * une iframe hébergée par Stripe. Aucune validation d'IBAN complet n'a donc
	 * lieu d'exister côté serveur — en écrire une signalerait qu'un IBAN y est
	 * parvenu.
	 *
	 * @group SEC-20
	 */
	public function test_aucun_iban_complet_n_est_recu_ni_valide_cote_serveur(): void {
		$offenders = array();

		foreach ( $this->shipped_sources() as $file ) {
			if ( ! str_ends_with( $file, '.php' ) ) {
				continue;
			}

			$source = (string) file_get_contents( $file );

			foreach ( array( '$_POST', '$_GET', '$_REQUEST' ) as $superglobal ) {
				if ( preg_match( '/' . preg_quote( $superglobal, '/' ) . '\s*\[\s*[\'"][^\'"]*iban[^\'"]*[\'"]/i', $source ) ) {
					$offenders[] = basename( $file ) . ' lit ' . $superglobal . '[…iban…]';
				}
			}
		}

		$this->assertSame( array(), $offenders, implode( "\n", $offenders ) );
	}

	/**
	 * @group SEC-05
	 */
	public function test_la_desinstallation_efface_les_options_et_la_table(): void {
		$uninstall = (string) file_get_contents( $this->root() . '/uninstall.php' );

		$this->assertStringContainsString(
			"defined( 'WP_UNINSTALL_PLUGIN' )",
			$uninstall,
			'Le script doit refuser une exécution directe.'
		);

		$this->assertStringContainsString( 'delete_option', $uninstall );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS', $uninstall );

		$this->assertStringContainsString(
			'RCP_SEPA_KEEP_DATA_ON_UNINSTALL',
			$uninstall,
			'Une installation doit pouvoir conserver ses données.'
		);
	}

	/**
	 * @group F-15
	 */
	public function test_toute_documentation_citee_existe(): void {
		$missing = array();
		$docs    = (array) glob( $this->root() . '/docs/*.md' );
		$sources = array_merge( $docs, array( $this->root() . '/README.md' ) );

		foreach ( $sources as $source ) {
			$content = (string) file_get_contents( (string) $source );

			if ( ! preg_match_all( '~\]\((?!https?://)([^)\#]+\.md)(?:\#[^)]*)?\)~', $content, $matches ) ) {
				continue;
			}

			foreach ( $matches[1] as $link ) {
				$target = dirname( (string) $source ) . '/' . $link;

				if ( ! file_exists( $target ) ) {
					$missing[] = basename( (string) $source ) . ' → ' . $link;
				}
			}
		}

		$this->assertSame( array(), $missing, "Lien de documentation rompu :\n" . implode( "\n", $missing ) );
	}

	/**
	 * @group F-15
	 */
	public function test_la_documentation_administrateur_et_developpeur_est_livree(): void {
		foreach ( array( 'docs/exploitation.md', 'docs/conventions-de-developpement.md', 'docs/cahier-des-charges.md', 'README.md' ) as $doc ) {
			$path = $this->root() . '/' . $doc;

			$this->assertFileExists( $path );
			$this->assertGreaterThan( 500, strlen( (string) file_get_contents( $path ) ), $doc . ' est trop succinct.' );
		}
	}
	/**
	 * Un répertoire requis à l'exécution mais absent de l'archive produit une
	 * erreur fatale sur le site de l'adhérent, et nulle part ailleurs : ni les
	 * suites, ni l'environnement de développement ne l'empruntent depuis
	 * l'archive. `templates/` manquait ainsi, ce qui aurait fait planter le
	 * formulaire d'inscription de toute installation réelle.
	 */
	public function test_l_archive_emporte_tout_ce_que_le_plugin_charge(): void {
		$build    = (string) file_get_contents( $this->root() . '/bin/build.sh' );
		$required = array();

		foreach ( $this->shipped_sources() as $file ) {
			$source = (string) file_get_contents( $file );

			if ( preg_match_all( "~__DIR__ \. '(?:/\.\.)+/([A-Za-z0-9_-]+)/~", $source, $matches ) ) {
				$required = array_merge( $required, $matches[1] );
			}
		}

		$required = array_unique( $required );

		$this->assertNotEmpty( $required, 'Le relevé des répertoires chargés ne doit pas être vide.' );

		foreach ( $required as $directory ) {
			$this->assertMatchesRegularExpression(
				'/\bfor item in .*\b' . preg_quote( $directory, '/' ) . '\b/',
				$build,
				sprintf( 'bin/build.sh doit emporter « %s », que le plugin charge à l’exécution.', $directory )
			);
		}
	}

}
