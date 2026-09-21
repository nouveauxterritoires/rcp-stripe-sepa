<?php
/**
 * Message d'incompatibilité affiché à l'administrateur.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Compat;

/**
 * Traduit les anomalies d'un environnement en messages actionnables.
 *
 * Le plugin ne provoque jamais d'erreur fatale lorsque RCP est absent ou
 * incompatible : il se désactive silencieusement côté public et explique la
 * situation côté administration.
 *
 * @see docs/cahier-des-charges.md §12 STD-05
 */
final class RequirementsNotice {

	/**
	 * Environnement analysé.
	 *
	 * @var RcpEnvironment
	 */
	private $environment;

	/**
	 * Construit l'avis pour un environnement donné.
	 *
	 * @param RcpEnvironment $environment Environnement analysé.
	 */
	public function __construct( RcpEnvironment $environment ) {
		$this->environment = $environment;
	}

	/**
	 * Messages à présenter, dans l'ordre de priorité.
	 *
	 * @return string[]
	 */
	public function messages(): array {
		$issues = $this->environment->issues();

		if ( array() === $issues ) {
			return array();
		}

		if ( in_array( RcpEnvironment::ISSUE_LEGACY_MODE, $issues, true ) ) {
			return array( $this->legacy_message() );
		}

		if ( in_array( RcpEnvironment::ISSUE_RCP_MISSING, $issues, true ) ) {
			return array( $this->missing_message() );
		}

		return array_values(
			array_filter(
				array(
					$this->core_version_message( $issues ),
					$this->stripe_sdk_message( $issues ),
					$this->capabilities_message( $issues ),
				)
			)
		);
	}

	/**
	 * Rendu HTML de l'avis d'administration, ou chaîne vide.
	 *
	 * @return string
	 */
	public function render(): string {
		$messages = $this->messages();

		if ( array() === $messages ) {
			return '';
		}

		$items = '';

		foreach ( $messages as $message ) {
			$items .= '<li>' . esc_html( $message ) . '</li>';
		}

		return sprintf(
			'<div class="notice notice-error rcp-stripe-sepa-requirements"><p><strong>%s</strong></p><ul>%s</ul></div>',
			esc_html__( 'SEPA Direct Debit for Restrict Content Pro: the plugin is inactive.', 'rcp-stripe-sepa' ),
			$items
		);
	}

	// -- Messages --------------------------------------------------------------

	/**
	 * Message affiché lorsque Restrict Content tourne en mode legacy.
	 *
	 * @return string
	 */
	private function legacy_message(): string {
		return __(
			'Restrict Content is running in legacy mode, which has no payment gateway at all. Switch to the full version in the Restrict Content settings to enable SEPA Direct Debit.',
			'rcp-stripe-sepa'
		);
	}

	/**
	 * Message affiché lorsque Restrict Content Pro est absent.
	 *
	 * @return string
	 */
	private function missing_message(): string {
		return __(
			'Restrict Content Pro (or Restrict Content) must be installed and active to use SEPA Direct Debit.',
			'rcp-stripe-sepa'
		);
	}

	/**
	 * Message affiché lorsque le noyau RCP est trop ancien.
	 *
	 * @param string[] $issues Anomalies constatées.
	 * @return string
	 */
	private function core_version_message( array $issues ): string {
		if ( ! in_array( RcpEnvironment::ISSUE_CORE_TOO_OLD, $issues, true ) ) {
			return '';
		}

		return sprintf(
			/* translators: 1: required version, 2: detected version. */
			__( 'Restrict Content Pro %1$s or later is required; version %2$s is installed.', 'rcp-stripe-sepa' ),
			RcpEnvironment::MINIMUM_CORE_VERSION,
			(string) $this->environment->core_version()
		);
	}

	/**
	 * Message affiché lorsque le SDK Stripe est absent ou trop ancien.
	 *
	 * @param string[] $issues Anomalies constatées.
	 * @return string
	 */
	private function stripe_sdk_message( array $issues ): string {
		$missing = in_array( RcpEnvironment::ISSUE_STRIPE_SDK_MISSING, $issues, true );
		$too_old = in_array( RcpEnvironment::ISSUE_STRIPE_SDK_TOO_OLD, $issues, true );

		if ( ! $missing && ! $too_old ) {
			return '';
		}

		if ( $missing ) {
			return sprintf(
				/* translators: %s: minimum Stripe SDK version. */
				__( 'The Stripe SDK shipped with Restrict Content Pro could not be found. Version %s or later is required.', 'rcp-stripe-sepa' ),
				RcpEnvironment::MINIMUM_STRIPE_SDK_VERSION
			);
		}

		return sprintf(
			/* translators: 1: required version, 2: detected version. */
			__( 'The Stripe SDK shipped with Restrict Content Pro is too old: version %1$s or later is required, %2$s was found. Please update Restrict Content Pro.', 'rcp-stripe-sepa' ),
			RcpEnvironment::MINIMUM_STRIPE_SDK_VERSION,
			(string) $this->environment->stripe_sdk_version()
		);
	}

	/**
	 * Regroupe les capacités absentes en un seul message lisible.
	 *
	 * @param string[] $issues Anomalies constatées.
	 * @return string
	 */
	private function capabilities_message( array $issues ): string {
		$names = array();

		foreach ( $issues as $issue ) {
			$separator = strpos( $issue, ':' );

			if ( false !== $separator ) {
				$names[] = substr( $issue, $separator + 1 );
			}
		}

		if ( array() === $names ) {
			return '';
		}

		return sprintf(
			/* translators: %s: comma-separated list of missing items. */
			__( 'This version of Restrict Content Pro no longer exposes what the plugin relies on: %s. Please report this incompatibility to the plugin maintainer.', 'rcp-stripe-sepa' ),
			implode( ', ', $names )
		);
	}
}
