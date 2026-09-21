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
			esc_html__( 'Prélèvement SEPA pour Restrict Content Pro : le plugin est inactif.', 'rcp-stripe-sepa' ),
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
			'Restrict Content fonctionne en mode « legacy », qui ne comporte aucune passerelle de paiement. Basculez sur la version complète dans les réglages de Restrict Content pour activer le prélèvement SEPA.',
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
			'Restrict Content Pro (ou Restrict Content) doit être installé et actif pour utiliser le prélèvement SEPA.',
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
			/* translators: 1: version requise, 2: version détectée. */
			__( 'Restrict Content Pro %1$s ou supérieur est requis ; la version %2$s est installée.', 'rcp-stripe-sepa' ),
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
				/* translators: %s: version minimale du SDK Stripe. */
				__( 'Le SDK Stripe fourni par Restrict Content Pro est introuvable. La version %s ou supérieure est requise.', 'rcp-stripe-sepa' ),
				RcpEnvironment::MINIMUM_STRIPE_SDK_VERSION
			);
		}

		return sprintf(
			/* translators: 1: version requise, 2: version détectée. */
			__( 'Le SDK Stripe fourni par Restrict Content Pro est trop ancien : version %1$s ou supérieure requise, %2$s détectée. Mettez Restrict Content Pro à jour.', 'rcp-stripe-sepa' ),
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
			/* translators: %s: liste d'éléments manquants, séparés par des virgules. */
			__( 'Cette version de Restrict Content Pro n\'expose plus les éléments attendus par le plugin : %s. Signalez cette incompatibilité au mainteneur du plugin.', 'rcp-stripe-sepa' ),
			implode( ', ', $names )
		);
	}
}
