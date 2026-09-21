<?php
/**
 * Intégration à l'outil « Santé du site » de WordPress.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Admin;

/**
 * Expose le diagnostic SEPA dans « Outils › Santé du site ».
 *
 * Un administrateur consulte cet écran ; il ne pensera pas forcément à ouvrir
 * celui du plugin. Y faire remonter les anomalies bloquantes les rend visibles
 * là où l'on regarde déjà.
 */
final class SiteHealth {

	public const TEST_ID = 'rcp_stripe_sepa_configuration';

	/**
	 * Branche le test sur l'outil de WordPress.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'site_status_tests', array( self::class, 'add_test' ) );
	}

	/**
	 * Déclare le test.
	 *
	 * @param array $tests Tests existants.
	 * @return array
	 */
	public static function add_test( $tests ): array {
		$tests = is_array( $tests ) ? $tests : array();

		$tests['direct'][ self::TEST_ID ] = array(
			'label' => __( 'Prélèvement SEPA', 'rcp-stripe-sepa' ),
			'test'  => array( self::class, 'run_test' ),
		);

		return $tests;
	}

	/**
	 * Exécute le test et met en forme son résultat.
	 *
	 * @return array
	 */
	public static function run_test(): array {
		$report = Diagnostics::report();

		$result = array(
			'label'       => __( 'La configuration du prélèvement SEPA est complète', 'rcp-stripe-sepa' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Paiements', 'rcp-stripe-sepa' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Tous les contrôles sont au vert.', 'rcp-stripe-sepa' ) . '</p>',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( DiagnosticsPage::url() ),
				esc_html__( 'Ouvrir le diagnostic détaillé', 'rcp-stripe-sepa' )
			),
			'test'        => self::TEST_ID,
		);

		if ( Diagnostics::STATUS_OK === $report['status'] ) {
			return $result;
		}

		$result['status'] = Diagnostics::STATUS_ERROR === $report['status'] ? 'critical' : 'recommended';
		$result['label']  = Diagnostics::STATUS_ERROR === $report['status']
			? __( 'Le prélèvement SEPA n\'est pas opérationnel', 'rcp-stripe-sepa' )
			: __( 'La configuration du prélèvement SEPA mérite un contrôle', 'rcp-stripe-sepa' );

		$items = '';

		foreach ( $report['problems'] as $problem ) {
			$items .= sprintf(
				'<li><strong>%s</strong> — %s</li>',
				esc_html( $problem['label'] ),
				esc_html( $problem['detail'] )
			);
		}

		$result['description'] = '<ul>' . $items . '</ul>';

		return $result;
	}
}
