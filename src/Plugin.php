<?php
/**
 * Point d'entrée du plugin.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa;

use RCP_Stripe_Sepa\Admin\DiagnosticsPage;
use RCP_Stripe_Sepa\Admin\SecretField;
use RCP_Stripe_Sepa\Admin\MembershipMandate;
use RCP_Stripe_Sepa\Admin\SiteHealth;
use RCP_Stripe_Sepa\Compat\RcpEnvironment;
use RCP_Stripe_Sepa\Email\Notifications;
use RCP_Stripe_Sepa\Frontend\MandateDetails;
use RCP_Stripe_Sepa\Compat\RequirementsNotice;
use RCP_Stripe_Sepa\Gateway\Registrar;
use RCP_Stripe_Sepa\Privacy\Registry as PrivacyRegistry;
use RCP_Stripe_Sepa\Migration\AccountPage;
use RCP_Stripe_Sepa\Mode\ModeNotice;
use RCP_Stripe_Sepa\Mode\TestBanner;
use RCP_Stripe_Sepa\Migration\AjaxController;
use RCP_Stripe_Sepa\Webhook\Endpoint;
use RCP_Stripe_Sepa\Webhook\EventStore;

/**
 * Amorçage : vérifie l'environnement, puis enregistre les composants.
 *
 * Si l'environnement n'est pas compatible, le plugin ne s'enregistre pas et se
 * contente d'expliquer pourquoi dans l'administration. Aucune erreur fatale,
 * aucun effet de bord côté public.
 */
final class Plugin {

	/**
	 * Instance unique, créée par `boot()`.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Environnement RCP détecté au démarrage.
	 *
	 * @var RcpEnvironment
	 */
	private $environment;

	/**
	 * Construit l'instance pour un environnement donné.
	 *
	 * @param RcpEnvironment $environment Environnement détecté.
	 */
	private function __construct( RcpEnvironment $environment ) {
		$this->environment = $environment;
	}

	/**
	 * Démarre le plugin.
	 *
	 * @param RcpEnvironment|null $environment Environnement à utiliser. Détecté si absent.
	 * @return self
	 */
	public static function boot( ?RcpEnvironment $environment = null ): self {
		$instance = new self( $environment ?? RcpEnvironment::detect() );

		self::$instance = $instance;

		$instance->register();

		return $instance;
	}

	/**
	 * Instance courante, ou null si le plugin n'a pas encore démarré.
	 *
	 * @return self|null
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * Environnement détecté au démarrage.
	 *
	 * @return RcpEnvironment
	 */
	public function environment(): RcpEnvironment {
		return $this->environment;
	}

	/**
	 * Le plugin est-il opérationnel ?
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->environment->is_supported();
	}

	/**
	 * Enregistre les composants, ou l'avis d'incompatibilité.
	 *
	 * @return void
	 */
	private function register(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( ! $this->environment->is_supported() ) {
			add_action( 'admin_notices', array( $this, 'render_requirements_notice' ) );

			return;
		}

		Registrar::register();
		AccountPage::register();
		AjaxController::register();
		MandateDetails::register();
		Notifications::register();
		TestBanner::register();
		ModeNotice::register();
		PrivacyRegistry::register();

		if ( is_admin() ) {
			DiagnosticsPage::register();
			SecretField::register();
			SiteHealth::register();
			MembershipMandate::register();
		}

		add_action( 'rest_api_init', array( Endpoint::class, 'register' ) );
		add_action( 'admin_init', array( EventStore::class, 'install' ) );
		add_action( 'rcp_stripe_sepa_purge_webhook_events', array( $this, 'purge_webhook_events' ) );

		if ( ! wp_next_scheduled( 'rcp_stripe_sepa_purge_webhook_events' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rcp_stripe_sepa_purge_webhook_events' );
		}

		/**
		 * Se déclenche lorsque le plugin a démarré dans un environnement compatible.
		 *
		 * @since 0.1.0
		 *
		 * @param Plugin $plugin Instance du plugin.
		 */
		do_action( 'rcp_stripe_sepa_booted', $this );
	}

	/**
	 * Charge les traductions.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'rcp-stripe-sepa',
			false,
			dirname( plugin_basename( RCP_SEPA_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Purge les événements de webhook au-delà de la durée de conservation.
	 *
	 * @return void
	 */
	public function purge_webhook_events(): void {
		EventStore::purge();
	}

	/**
	 * Affiche l'avis d'incompatibilité dans l'administration.
	 *
	 * @return void
	 */
	public function render_requirements_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$html = ( new RequirementsNotice( $this->environment ) )->render();

		if ( '' === $html ) {
			return;
		}

		echo wp_kses_post( $html );
	}
}
