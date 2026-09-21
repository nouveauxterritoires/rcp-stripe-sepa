<?php
/**
 * Bascule vers le prélèvement SEPA depuis la page « Mon compte ».
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Migration;

use RCP_Membership;

/**
 * Propose la migration à l'adhérent, sur la page de ses adhésions.
 *
 * Aucune page supplémentaire n'est créée : le formulaire s'insère dans le
 * gabarit de RCP, ce qui évite à l'administrateur une configuration de plus et
 * garde le parcours au même endroit que les autres actions sur l'adhésion.
 */
final class AccountPage {

	/**
	 * Action nonce partagée par les deux étapes.
	 */
	public const NONCE_ACTION = 'rcp_stripe_sepa_migration';

	/**
	 * Branche l'affichage sur les gabarits de RCP.
	 *
	 * @return void
	 */
	public static function register(): void {
		/*
		 * `rcp_subscription_details_action_links` est une action, malgré son
		 * nom : RCP l'invoque par `do_action()` pour permettre d'ajouter du
		 * HTML dans la colonne « Actions ». Un filtre renvoyant un tableau y
		 * serait sans effet.
		 */
		add_action( 'rcp_subscription_details_action_links', array( self::class, 'render_action_link' ), 10, 2 );
		add_action( 'rcp_subscription_details_bottom', array( self::class, 'render_form' ) );
	}

	/**
	 * Affiche le bouton de bascule dans la colonne « Actions ».
	 *
	 * @param array          $links      Liens déjà rendus par RCP.
	 * @param RCP_Membership $membership Adhésion concernée.
	 * @return void
	 */
	public static function render_action_link( $links = array(), $membership = null ): void {
		if ( ! self::is_offered( $membership ) ) {
			return;
		}

		printf(
			'<br/><button type="button" class="rcp-stripe-sepa-migrate-toggle" data-membership="%1$d" aria-expanded="false" aria-controls="rcp-stripe-sepa-migration-%1$d">%2$s</button>',
			(int) $membership->get_id(),
			esc_html__( 'Switch to SEPA Direct Debit', 'rcp-stripe-sepa' )
		);
	}

	/**
	 * Affiche le formulaire de collecte du nouveau mandat.
	 *
	 * @return void
	 */
	public static function render_form(): void {
		$customer = rcp_get_customer_by_user_id( get_current_user_id() );

		if ( empty( $customer ) ) {
			return;
		}

		$memberships = array_filter(
			(array) rcp_get_customer_memberships( $customer->get_id() ),
			array( self::class, 'is_offered' )
		);

		if ( array() === $memberships ) {
			return;
		}

		self::enqueue_assets();

		foreach ( $memberships as $membership ) {
			require __DIR__ . '/../../templates/migration-form.php';
		}
	}

	/**
	 * La bascule est-elle proposée pour cette adhésion ?
	 *
	 * @param mixed $membership Adhésion concernée.
	 * @return bool
	 */
	public static function is_offered( $membership ): bool {
		if ( ! $membership instanceof RCP_Membership ) {
			return false;
		}

		return Eligibility::assess( Migrator::snapshot( $membership ) )->is_allowed();
	}

	/**
	 * Charge Stripe.js et le script de migration.
	 *
	 * @return void
	 */
	private static function enqueue_assets(): void {
		if ( wp_script_is( 'rcp-stripe-sepa-migration', 'enqueued' ) ) {
			return;
		}

		global $rcp_options;

		$publishable = (string) ( $rcp_options['stripe_test_publishable'] ?? '' );

		if ( empty( $rcp_options['sandbox'] ) ) {
			$publishable = (string) ( $rcp_options['stripe_live_publishable'] ?? '' );
		}

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_script( 'stripe-js-v3', 'https://js.stripe.com/v3/', array(), null, true );

		wp_enqueue_script(
			'rcp-stripe-sepa-migration',
			RCP_SEPA_PLUGIN_URL . 'assets/js/migration.js',
			array( 'stripe-js-v3', 'jquery' ),
			RCP_SEPA_VERSION,
			true
		);

		wp_localize_script(
			'rcp-stripe-sepa-migration',
			'rcpStripeSepaMigration',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'publishableKey' => $publishable,
				'locale'         => substr( (string) get_locale(), 0, 2 ),
				'strings'        => array(
					'missingName' => __( 'Please enter the account holder’s name.', 'rcp-stripe-sepa' ),
					'working'     => __( 'Saving your mandate…', 'rcp-stripe-sepa' ),
					'success'     => __( 'Your membership is now paid by SEPA Direct Debit. The price and next renewal date are unchanged.', 'rcp-stripe-sepa' ),
					'failure'     => __( 'Switching to SEPA Direct Debit failed. Please try again, or contact us if the problem persists.', 'rcp-stripe-sepa' ),
				),
			)
		);

		wp_enqueue_style(
			'rcp-stripe-sepa',
			RCP_SEPA_PLUGIN_URL . 'assets/css/register.css',
			array(),
			RCP_SEPA_VERSION
		);
	}
}
