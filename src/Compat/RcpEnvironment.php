<?php
/**
 * Détection de l'environnement Restrict Content Pro.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Compat;

/**
 * Instantané immuable de l'environnement RCP présent sur le site.
 *
 * Le plugin s'appuie sur des API internes de RCP (héritage de
 * `RCP_Payment_Gateway_Stripe`). Deux variantes du produit exposent ces API :
 *
 *  - la variante libre « Restrict Content » / « Kadence Memberships », publiée
 *    sur WordPress.org, qui embarque le noyau RCP dans `core/` ;
 *  - la variante commerciale « Restrict Content Pro ».
 *
 * Les deux définissent les mêmes constantes (`RCP_PLUGIN_VERSION`,
 * `RCP_PLUGIN_DIR`) et la même classe `Restrict_Content_Pro`. La variante
 * détectée sert donc au **diagnostic uniquement** : le support est décidé par
 * la présence effective des capacités utilisées, jamais par un numéro de
 * version ni par un nom de plugin.
 *
 * Deux constats motivent ce choix :
 *
 *  1. la variante libre 4.0.4 déclare `RCP_PLUGIN_VERSION = 4.0.7` — les
 *     numéros de version ne sont pas fiables d'une variante à l'autre ;
 *  2. la variante libre peut fonctionner en « mode legacy »
 *     (option `restrict_content_chosen_version`), où le noyau RCP n'est pas
 *     chargé du tout et où aucune passerelle Stripe n'existe.
 *
 * @see docs/cahier-des-charges.md §3.6 et §5.1
 */
final class RcpEnvironment {

	public const VARIANT_PRO     = 'pro';
	public const VARIANT_FREE    = 'free';
	public const VARIANT_UNKNOWN = 'unknown';

	public const ISSUE_RCP_MISSING        = 'rcp_missing';
	public const ISSUE_LEGACY_MODE        = 'legacy_mode';
	public const ISSUE_CORE_TOO_OLD       = 'core_too_old';
	public const ISSUE_STRIPE_SDK_MISSING = 'stripe_sdk_missing';
	public const ISSUE_STRIPE_SDK_TOO_OLD = 'stripe_sdk_too_old';

	/**
	 * Version minimale du noyau RCP, lorsqu'elle est déclarée.
	 *
	 * 3.5 est la version qui a remplacé l'API Charges par les PaymentIntents.
	 */
	public const MINIMUM_CORE_VERSION = '3.5';

	/**
	 * Version minimale du SDK Stripe embarqué par RCP.
	 *
	 * Le SDK 10.x est le premier à exposer les objets `Mandate` et `SetupIntent`
	 * dans la forme utilisée par le plugin.
	 */
	public const MINIMUM_STRIPE_SDK_VERSION = '10.0.0';

	/**
	 * Chemins, relatifs au répertoire de RCP, où chercher le SDK Stripe embarqué.
	 *
	 * La variante libre place le noyau sous `core/` ; la variante commerciale
	 * l'expose à la racine. Les deux chemins sont testés.
	 *
	 * @var string[]
	 */
	private const STRIPE_SDK_PATHS = array(
		'core/includes/libraries/stripe/init.php',
		'includes/libraries/stripe/init.php',
	);

	/**
	 * Classes dont le plugin dépend directement.
	 *
	 * @var string[]
	 */
	public const REQUIRED_CLASSES = array(
		'RCP_Payment_Gateway',
		'RCP_Payment_Gateway_Stripe',
		'RCP_Payment_Gateways',
		'RCP_Payments',
	);

	/**
	 * Méthodes surchargées ou appelées par héritage.
	 *
	 * Toute disparition côté RCP doit être détectée avant que le plugin ne
	 * s'enregistre, et non au moment d'une inscription.
	 *
	 * @var string[]
	 */
	public const REQUIRED_METHODS = array(
		'RCP_Payment_Gateway_Stripe::init',
		'RCP_Payment_Gateway_Stripe::process_ajax_signup',
		'RCP_Payment_Gateway_Stripe::process_signup',
		'RCP_Payment_Gateway_Stripe::process_webhooks',
		'RCP_Payment_Gateway_Stripe::fields',
		'RCP_Payment_Gateway_Stripe::scripts',
		'RCP_Payment_Gateway_Stripe::validate_fields',
		'RCP_Payment_Gateway_Stripe::update_card_fields',
		'RCP_Payment_Gateway_Stripe::get_or_create_customer',
		'RCP_Payment_Gateway_Stripe::maybe_create_plan',
		'RCP_Payment_Gateway::supports',
	);

	/**
	 * Fonctions globales de RCP utilisées par le plugin.
	 *
	 * @var string[]
	 */
	public const REQUIRED_FUNCTIONS = array(
		'rcp_log',
		'rcp_get_currency',
		'rcp_get_membership',
		'rcp_stripe_get_currency_multiplier',
		'rcp_stripe_generate_idempotency_key',
	);

	/**
	 * Correspondance entre un nom de répertoire de plugin et une variante.
	 *
	 * L'ordre compte : la variante commerciale prime si les deux marqueurs sont
	 * présents, le nom de la variante libre étant un préfixe de l'autre.
	 *
	 * @var array<string, string>
	 */
	private const VARIANT_MARKERS = array(
		'restrict-content-pro' => self::VARIANT_PRO,
		'restrict-content'     => self::VARIANT_FREE,
	);

	/**
	 * Instantané normalisé de l'environnement.
	 *
	 * @var array
	 */
	private $snapshot;

	/**
	 * Anomalies constatées, calculées une fois à la construction.
	 *
	 * @var string[]
	 */
	private $issues;

	/**
	 * Construit un environnement déjà analysé.
	 *
	 * @param array    $snapshot Instantané normalisé.
	 * @param string[] $issues   Anomalies constatées.
	 */
	private function __construct( array $snapshot, array $issues ) {
		$this->snapshot = $snapshot;
		$this->issues   = $issues;
	}

	/**
	 * Construit l'environnement à partir d'un instantané.
	 *
	 * L'instantané fourni n'est jamais modifié.
	 *
	 * @param array $snapshot Instantané brut.
	 * @return self
	 */
	public static function from_snapshot( array $snapshot ): self {
		$normalized = self::normalize( $snapshot );

		return new self( $normalized, self::collect_issues( $normalized ) );
	}

	/**
	 * Construit l'environnement à partir de l'état réel du site.
	 *
	 * @return self
	 */
	public static function detect(): self {
		return self::from_snapshot(
			array(
				'active_plugins'     => self::detect_active_plugins(),
				'constants'          => self::detect_constants(),
				'classes'            => self::detect_classes(),
				'methods'            => self::detect_methods(),
				'functions'          => self::detect_functions(),
				'stripe_sdk_version' => self::detect_stripe_sdk_version(),
				'stripe_sdk_path'    => self::detect_stripe_sdk_path(),
				'chosen_version'     => self::detect_chosen_version(),
			)
		);
	}

	// -- Lecture ---------------------------------------------------------------

	/**
	 * Le noyau RCP est-il chargé ?
	 *
	 * @return bool
	 */
	public function is_rcp_loaded(): bool {
		return ! empty( $this->snapshot['classes']['RCP_Payment_Gateway'] );
	}

	/**
	 * La variante libre tourne-t-elle en mode legacy (noyau RCP non chargé) ?
	 *
	 * @return bool
	 */
	public function is_legacy_mode(): bool {
		return 'legacy' === $this->snapshot['chosen_version'];
	}

	/**
	 * Variante détectée — à usage de diagnostic uniquement.
	 *
	 * @return string
	 */
	public function variant(): string {
		/*
		 * Le répertoire d'où RCP a été chargé fait foi. `active_plugins` n'est
		 * pas toujours renseignée — must-use plugin, harnais de tests,
		 * bootstrap applicatif — alors que `RCP_PLUGIN_DIR` est défini par RCP
		 * lui-même à partir du fichier réellement chargé.
		 */
		$directory = basename( rtrim( (string) ( $this->snapshot['constants']['RCP_PLUGIN_DIR'] ?? '' ), '/' ) );

		foreach ( self::VARIANT_MARKERS as $marker => $variant ) {
			if ( $marker === $directory ) {
				return $variant;
			}
		}

		foreach ( self::VARIANT_MARKERS as $marker => $variant ) {
			foreach ( $this->snapshot['active_plugins'] as $plugin ) {
				if ( 0 === strpos( $plugin, $marker . '/' ) ) {
					return $variant;
				}
			}
		}

		// Dernier indice : seule la variante libre définit cette constante.
		if ( isset( $this->snapshot['constants']['RCF_VERSION'] ) ) {
			return self::VARIANT_FREE;
		}

		return self::VARIANT_UNKNOWN;
	}

	/**
	 * Version du noyau RCP telle que déclarée, ou null.
	 *
	 * @return string|null
	 */
	public function core_version(): ?string {
		$version = $this->snapshot['constants']['RCP_PLUGIN_VERSION'] ?? null;

		return is_string( $version ) && '' !== $version ? $version : null;
	}

	/**
	 * Version du SDK Stripe, ou null s'il n'est pas encore chargé.
	 *
	 * @return string|null
	 */
	public function stripe_sdk_version(): ?string {
		return $this->snapshot['stripe_sdk_version'];
	}

	/**
	 * Le SDK Stripe est-il présent mais pas encore chargé ?
	 *
	 * RCP ne charge son SDK qu'au premier appel de
	 * `RCP_Payment_Gateway_Stripe::init()`. Au démarrage du plugin, sur
	 * `plugins_loaded`, la classe `\Stripe\Stripe` n'existe donc pas encore.
	 * Exiger sa présence à cet instant rendrait le plugin inutilisable : la
	 * version réelle est contrôlée à l'initialisation de la passerelle.
	 *
	 * @return bool
	 */
	public function is_stripe_sdk_deferred(): bool {
		return null === $this->snapshot['stripe_sdk_version']
			&& null !== $this->snapshot['stripe_sdk_path'];
	}

	/**
	 * Chemin du SDK Stripe embarqué par RCP, ou null.
	 *
	 * @return string|null
	 */
	public function stripe_sdk_path(): ?string {
		return $this->snapshot['stripe_sdk_path'];
	}

	/**
	 * Anomalies constatées.
	 *
	 * @return string[]
	 */
	public function issues(): array {
		return $this->issues;
	}

	/**
	 * L'environnement permet-il d'enregistrer la passerelle ?
	 *
	 * @return bool
	 */
	public function is_supported(): bool {
		return array() === $this->issues;
	}

	/**
	 * Diagnostic destiné à l'écran de support.
	 *
	 * Ne contient que des informations non sensibles : aucune clé, aucun secret,
	 * aucune donnée personnelle.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'variant'            => $this->variant(),
			'core_version'       => $this->core_version(),
			'stripe_sdk_version' => $this->stripe_sdk_version(),
			'stripe_sdk_loaded'  => null !== $this->stripe_sdk_version(),
			'stripe_sdk_found'   => null !== $this->stripe_sdk_path(),
			'legacy_mode'        => $this->is_legacy_mode(),
			'rcp_loaded'         => $this->is_rcp_loaded(),
			'supported'          => $this->is_supported(),
			'issues'             => $this->issues,
		);
	}

	// -- Construction ----------------------------------------------------------

	/**
	 * Complète un instantané partiel avec des valeurs par défaut sûres.
	 *
	 * @param array $snapshot Instantané brut.
	 * @return array
	 */
	private static function normalize( array $snapshot ): array {
		return array(
			'active_plugins'     => (array) ( $snapshot['active_plugins'] ?? array() ),
			'constants'          => (array) ( $snapshot['constants'] ?? array() ),
			'classes'            => (array) ( $snapshot['classes'] ?? array() ),
			'methods'            => (array) ( $snapshot['methods'] ?? array() ),
			'functions'          => (array) ( $snapshot['functions'] ?? array() ),
			'stripe_sdk_version' => isset( $snapshot['stripe_sdk_version'] ) && is_string( $snapshot['stripe_sdk_version'] )
				? $snapshot['stripe_sdk_version']
				: null,
			'stripe_sdk_path'    => isset( $snapshot['stripe_sdk_path'] ) && is_string( $snapshot['stripe_sdk_path'] )
				? $snapshot['stripe_sdk_path']
				: null,
			'chosen_version'     => (string) ( $snapshot['chosen_version'] ?? '' ),
		);
	}

	/**
	 * Calcule la liste des anomalies bloquantes.
	 *
	 * Les cas « RCP absent » et « mode legacy » court-circuitent le reste :
	 * détailler chaque capacité manquante noierait le seul message actionnable.
	 *
	 * @param array $snapshot Instantané normalisé.
	 * @return string[]
	 */
	private static function collect_issues( array $snapshot ): array {
		if ( 'legacy' === $snapshot['chosen_version'] ) {
			return array( self::ISSUE_LEGACY_MODE );
		}

		if ( empty( $snapshot['classes']['RCP_Payment_Gateway'] ) ) {
			return array( self::ISSUE_RCP_MISSING );
		}

		return array_merge(
			self::missing_capabilities( $snapshot ),
			self::version_issues( $snapshot )
		);
	}

	/**
	 * Capacités RCP absentes.
	 *
	 * @param array $snapshot Instantané normalisé.
	 * @return string[]
	 */
	private static function missing_capabilities( array $snapshot ): array {
		$checks = array(
			'class'    => array( self::REQUIRED_CLASSES, $snapshot['classes'] ),
			'method'   => array( self::REQUIRED_METHODS, $snapshot['methods'] ),
			'function' => array( self::REQUIRED_FUNCTIONS, $snapshot['functions'] ),
		);

		$missing = array();

		foreach ( $checks as $kind => $check ) {
			list( $required, $present ) = $check;

			foreach ( $required as $name ) {
				if ( empty( $present[ $name ] ) ) {
					$missing[] = $kind . ':' . $name;
				}
			}
		}

		return $missing;
	}

	/**
	 * Anomalies liées aux versions.
	 *
	 * @param array $snapshot Instantané normalisé.
	 * @return string[]
	 */
	private static function version_issues( array $snapshot ): array {
		$issues = array();

		$core_version = $snapshot['constants']['RCP_PLUGIN_VERSION'] ?? null;

		if ( is_string( $core_version ) && '' !== $core_version
			&& version_compare( $core_version, self::MINIMUM_CORE_VERSION, '<' ) ) {
			$issues[] = self::ISSUE_CORE_TOO_OLD;
		}

		$sdk_version = $snapshot['stripe_sdk_version'];

		if ( null === $sdk_version ) {
			// Le SDK n'est pas chargé : seule son absence du disque est bloquante.
			if ( null === $snapshot['stripe_sdk_path'] ) {
				$issues[] = self::ISSUE_STRIPE_SDK_MISSING;
			}
		} elseif ( version_compare( $sdk_version, self::MINIMUM_STRIPE_SDK_VERSION, '<' ) ) {
			$issues[] = self::ISSUE_STRIPE_SDK_TOO_OLD;
		}

		return $issues;
	}

	// -- Sondes de l'environnement réel ---------------------------------------

	/**
	 * Liste les plugins actifs, y compris ceux activés sur tout le réseau.
	 *
	 * @return string[]
	 */
	private static function detect_active_plugins(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$plugins = (array) get_option( 'active_plugins', array() );

		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		return array_values( array_filter( $plugins, 'is_string' ) );
	}

	/**
	 * Relève les constantes de RCP présentes.
	 *
	 * @return array<string, string>
	 */
	private static function detect_constants(): array {
		$names  = array( 'RCP_PLUGIN_VERSION', 'RCP_PLUGIN_DIR', 'RCF_VERSION' );
		$values = array();

		foreach ( $names as $name ) {
			if ( defined( $name ) ) {
				$values[ $name ] = (string) constant( $name );
			}
		}

		return $values;
	}

	/**
	 * Vérifie la présence de chaque classe requise.
	 *
	 * @return array<string, bool>
	 */
	private static function detect_classes(): array {
		$present = array();

		foreach ( self::REQUIRED_CLASSES as $class ) {
			$present[ $class ] = class_exists( $class );
		}

		return $present;
	}

	/**
	 * Vérifie la présence de chaque méthode requise.
	 *
	 * @return array<string, bool>
	 */
	private static function detect_methods(): array {
		$present = array();

		foreach ( self::REQUIRED_METHODS as $reference ) {
			list( $class, $method ) = explode( '::', $reference, 2 );

			$present[ $reference ] = class_exists( $class ) && method_exists( $class, $method );
		}

		return $present;
	}

	/**
	 * Vérifie la présence de chaque fonction requise.
	 *
	 * @return array<string, bool>
	 */
	private static function detect_functions(): array {
		$present = array();

		foreach ( self::REQUIRED_FUNCTIONS as $function ) {
			$present[ $function ] = function_exists( $function );
		}

		return $present;
	}

	/**
	 * Lit la version du SDK Stripe, s'il est déjà chargé.
	 *
	 * @return string|null
	 */
	private static function detect_stripe_sdk_version(): ?string {
		if ( ! class_exists( '\Stripe\Stripe' ) || ! defined( '\Stripe\Stripe::VERSION' ) ) {
			return null;
		}

		return (string) constant( '\Stripe\Stripe::VERSION' );
	}

	/**
	 * Localise le SDK Stripe embarqué par RCP, sans le charger.
	 *
	 * @return string|null
	 */
	private static function detect_stripe_sdk_path(): ?string {
		if ( ! defined( 'RCP_PLUGIN_DIR' ) ) {
			return null;
		}

		$base = (string) constant( 'RCP_PLUGIN_DIR' );

		foreach ( self::STRIPE_SDK_PATHS as $relative ) {
			$path = $base . $relative;

			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Variante de la version libre retenue par l'administrateur.
	 *
	 * @return string
	 */
	private static function detect_chosen_version(): string {
		if ( ! function_exists( 'get_option' ) ) {
			return '';
		}

		return (string) get_option( 'restrict_content_chosen_version', '' );
	}
}
