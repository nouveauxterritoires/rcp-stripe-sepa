<?php
/**
 * Génère la matrice de traçabilité exigences ↔ tests (annexe B du CDC).
 *
 * Les exigences sont lues dans le cahier des charges, les couvertures dans les
 * annotations `@group` des tests. `@group` n'est pas un choix décoratif : c'est
 * l'annotation native de PHPUnit, ce qui rend chaque exigence exécutable —
 * `vendor/bin/phpunit --group RG-01` rejoue exactement ce qui l'établit.
 *
 * Usage : php bin/traceability.php [--check]
 *
 * `--check` n'écrit rien et sort en erreur si le fichier généré diffère de
 * celui versionné, ou si une exigence n'est couverte par aucun test.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

const ROOT        = __DIR__ . '/..';
const SPEC        = ROOT . '/docs/cahier-des-charges.md';
const OUTPUT      = ROOT . '/docs/traceability.md';
const TEST_DIRS   = array( '/tests/Unit', '/tests/Integration', '/tests/Contract', '/tests/Webhooks' );
const E2E_DIR     = '/tests/e2e';
const FAMILIES    = array(
	'F'   => 'Exigences fonctionnelles',
	'RG'  => 'Règles de gestion',
	'SEC' => 'Exigences de sécurité',
	'CNF' => 'Exigences de conformité',
	'I'   => 'Idempotence et robustesse',
);

/**
 * Exigences qu'aucun test ne peut établir, et ce qui les établit à sa place.
 *
 * Y figurent celles que garantit une porte d'intégration continue, et celles
 * qui relèvent d'un réglage tiers ou d'un acte humain. Les y inscrire est un
 * engagement : rien ne doit rejoindre cette liste faute d'avoir su écrire le
 * test.
 */
const TOOLING = array(
	'F-14'    => 'La chaîne d\'intégration continue elle-même : toute exécution installe la pile et rejoue les quatre suites.',
	'SEC-19'  => 'PHPCS, jeu de règles `WordPress.DB.PreparedSQL` — tâche « Standards et analyse statique ».',
	'SEC-25'  => '`composer audit` et `npm audit` — tâche « Sécurité ».',
	'SEC-28'  => 'Acte humain : checklist de l\'annexe D, passée avant chaque version.',
	'CNF-03'  => 'Réglage du compte Stripe, hors du code. Vérifié à la mise en production (annexe C.1).',
);

/**
 * Lit les exigences du cahier des charges.
 *
 * Deux écritures coexistent : une ligne de tableau `| F-01 | libellé |` et une
 * puce `- **RG-01** : libellé`.
 *
 * @return array<string, string> Identifiant => libellé abrégé.
 */
function read_requirements(): array {
	$lines        = file( SPEC, FILE_IGNORE_NEW_LINES );
	$requirements = array();

	foreach ( $lines as $line ) {
		if ( preg_match( '/^\|\s*((?:F|RG|SEC|CNF|I)-\d+)\s*\|\s*(.+?)\s*\|/u', $line, $m ) ) {
			$requirements[ $m[1] ] = summarise( $m[2] );
			continue;
		}

		if ( preg_match( '/^-\s+\*\*((?:F|RG|SEC|CNF|I)-\d+)\*\*\s*:?\s*(.+)$/u', $line, $m ) ) {
			$requirements[ $m[1] ] = summarise( $m[2] );
		}
	}

	return $requirements;
}

/**
 * Abrège un libellé d'exigence pour tenir dans une cellule.
 *
 * @param string $label Libellé brut, en Markdown.
 * @return string
 */
function summarise( string $label ): string {
	$label = str_replace( array( '**', '`' ), '', $label );
	$label = preg_replace( '/\s+/u', ' ', trim( $label ) );

	if ( mb_strlen( $label ) <= 90 ) {
		return $label;
	}

	return mb_substr( $label, 0, 87 ) . '…';
}

/**
 * Relève les tests PHP et leurs groupes.
 *
 * @return array<string, list<string>> Identifiant d'exigence => tests.
 */
function read_php_coverage(): array {
	$coverage = array();

	foreach ( TEST_DIRS as $dir ) {
		$path = ROOT . $dir;

		if ( ! is_dir( $path ) ) {
			continue;
		}

		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ) );
		$paths = array();

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$paths[] = (string) $file->getPathname();
		}

		/*
		 * L'ordre de parcours d'un répertoire dépend du système de fichiers :
		 * sans tri, le document généré sur macOS diffère de celui généré sur
		 * Linux, et la porte d'intégration continue échoue sans qu'aucune
		 * exigence ait bougé.
		 */
		sort( $paths, SORT_STRING );

		foreach ( $paths as $file_path ) {
			collect_groups( $file_path, $coverage );
		}
	}

	return $coverage;
}

/**
 * Extrait les `@group` d'un fichier de test, et les rattache à leur méthode.
 *
 * Un `@group` posé sur la classe vaut pour toutes ses méthodes de test.
 *
 * @param string                      $path     Chemin du fichier.
 * @param array<string, list<string>> $coverage Accumulateur, modifié sur place.
 * @return void
 */
function collect_groups( string $path, array &$coverage ): void {
	$source   = (string) file_get_contents( $path );
	$relative = ltrim( str_replace( realpath( ROOT ) ?: ROOT, '', realpath( $path ) ?: $path ), '/' );

	if ( ! preg_match( '/(?:final\s+)?class\s+(\w+)/', $source, $class_match ) ) {
		return;
	}

	$class = $class_match[1];

	// Groupes déclarés avant la classe : ils valent pour toute la classe.
	$header       = substr( $source, 0, (int) strpos( $source, 'class ' . $class ) );
	$class_groups = extract_groups( $header );

	preg_match_all(
		'#/\*\*(.*?)\*/\s*public function (test_\w+)#s',
		$source,
		$matches,
		PREG_SET_ORDER
	);

	$documented = array();

	foreach ( $matches as $match ) {
		$documented[ $match[2] ] = extract_groups( $match[1] );
	}

	preg_match_all( '/public function (test_\w+)/', $source, $all_methods );

	foreach ( $all_methods[1] as $method ) {
		$groups = array_unique( array_merge( $class_groups, $documented[ $method ] ?? array() ) );

		foreach ( $groups as $group ) {
			$coverage[ $group ][] = sprintf( '`%s::%s`', $class, $method ) . ' — ' . $relative;
		}
	}
}

/**
 * Relève les identifiants d'exigence cités dans un bloc de commentaire.
 *
 * @param string $block Texte à analyser.
 * @return list<string>
 */
function extract_groups( string $block ): array {
	preg_match_all( '/@group\s+((?:F|RG|SEC|CNF|I)-\d+)/', $block, $matches );

	return $matches[1];
}

/**
 * Relève les parcours de bout en bout citant une exigence.
 *
 * Playwright n'a pas de groupes : les parcours annoncent l'exigence dans un
 * commentaire `// @group RG-01`.
 *
 * @return array<string, list<string>>
 */
function read_e2e_coverage(): array {
	$coverage = array();
	$path     = ROOT . E2E_DIR;

	if ( ! is_dir( $path ) ) {
		return $coverage;
	}

	$specs = (array) glob( $path . '/*.spec.js' );
	sort( $specs, SORT_STRING );

	foreach ( $specs as $file ) {
		$lines    = file( (string) $file, FILE_IGNORE_NEW_LINES );
		$relative = 'tests/e2e/' . basename( (string) $file );
		$pending  = array();

		foreach ( $lines as $line ) {
			if ( preg_match_all( '#//\s*@group\s+((?:F|RG|SEC|CNF|I)-\d+)#', $line, $m ) ) {
				$pending = array_merge( $pending, $m[1] );
				continue;
			}

			if ( preg_match( "#^\s*test\(\s*'(.+?)'#u", $line, $m ) && array() !== $pending ) {
				foreach ( array_unique( $pending ) as $group ) {
					$coverage[ $group ][] = sprintf( '« %s » — %s', $m[1], $relative );
				}

				$pending = array();
			}
		}
	}

	return $coverage;
}

/**
 * Compose le document.
 *
 * @param array<string, string>       $requirements Exigences.
 * @param array<string, list<string>> $coverage     Couverture.
 * @return string
 */
function render( array $requirements, array $coverage ): string {
	$out  = "# Traçabilité des exigences\n\n";
	$out .= "> Document généré par `bin/traceability.php`. Ne pas modifier à la main.\n\n";
	$out .= "Chaque exigence du [cahier des charges](cahier-des-charges.md) est rattachée aux tests\n";
	$out .= "qui l'établissent, par l'annotation `@group` de PHPUnit. Une exigence est donc\n";
	$out .= "rejouable isolément :\n\n";
	$out .= "```bash\nvendor/bin/phpunit --group RG-01\n```\n\n";

	$total   = count( $requirements );
	$covered = count(
		array_filter(
			array_keys( $requirements ),
			static fn( $id ) => ! empty( $coverage[ $id ] ) || isset( TOOLING[ $id ] )
		)
	);

	$out .= sprintf( "**%d exigences sur %d sont établies.**\n\n", $covered, $total );

	foreach ( FAMILIES as $prefix => $title ) {
		$family = array_filter(
			$requirements,
			static fn( $id ) => str_starts_with( $id, $prefix . '-' ),
			ARRAY_FILTER_USE_KEY
		);

		if ( array() === $family ) {
			continue;
		}

		uksort( $family, static fn( $a, $b ) => (int) substr( $a, strlen( $prefix ) + 1 ) <=> (int) substr( $b, strlen( $prefix ) + 1 ) );

		$out .= sprintf( "## %s\n\n", $title );
		$out .= "| Exigence | Énoncé | Établie par |\n|---|---|---|\n";

		foreach ( $family as $id => $label ) {
			$tests = $coverage[ $id ] ?? array();

			if ( array() !== $tests ) {
				$tests = array_unique( $tests );
				sort( $tests, SORT_STRING );

				$cell = implode( '<br>', $tests );
			} elseif ( isset( TOOLING[ $id ] ) ) {
				$cell = '*' . TOOLING[ $id ] . '*';
			} else {
				$cell = '**aucun test**';
			}
			$out  .= sprintf( "| **%s** | %s | %s |\n", $id, $label, $cell );
		}

		$out .= "\n";
	}

	return $out;
}

$requirements = read_requirements();
$coverage     = array_merge_recursive( read_php_coverage(), read_e2e_coverage() );
$document     = render( $requirements, $coverage );

$check   = in_array( '--check', $argv, true );
$missing = array_values(
	array_filter(
		array_keys( $requirements ),
		static fn( $id ) => empty( $coverage[ $id ] ) && ! isset( TOOLING[ $id ] )
	)
);

if ( ! $check ) {
	file_put_contents( OUTPUT, $document );
	printf( "docs/traceability.md écrit — %d exigences, %d sans preuve.\n", count( $requirements ), count( $missing ) );

	if ( array() !== $missing ) {
		printf( "Sans test : %s\n", implode( ', ', $missing ) );
	}

	exit( 0 );
}

$status = 0;

if ( ! is_file( OUTPUT ) || file_get_contents( OUTPUT ) !== $document ) {
	fwrite( STDERR, "docs/traceability.md n'est pas à jour. Exécutez : make traceability\n" );
	$status = 1;
}

if ( array() !== $missing ) {
	fwrite( STDERR, sprintf( "Exigences sans test : %s\n", implode( ', ', $missing ) ) );
	$status = 1;
}

if ( 0 === $status ) {
	printf( "Traçabilité à jour — %d exigences, toutes établies.\n", count( $requirements ) );
}

exit( $status );
