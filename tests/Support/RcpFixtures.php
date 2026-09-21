<?php
/**
 * Fabrique de données RCP pour les tests.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Tests\Support;

/**
 * Crée utilisateurs, clients, niveaux et adhésions RCP.
 *
 * Les tables de RCP ne sont pas restaurées entre deux tests, alors que celles
 * de WordPress le sont : les identifiants d'utilisateur se recyclent et
 * heurtent des enregistrements résiduels. Chaque fabrique compose donc avec
 * l'existant plutôt que de le supposer absent, et tout ce qui doit être unique
 * l'est explicitement.
 */
trait RcpFixtures {

	/**
	 * Base d'API Stripe d'origine, restaurée après le test.
	 *
	 * @var string
	 */
	private $stripe_api_base_before = '';

	/**
	 * Réglages Stripe minimaux attendus par RCP.
	 *
	 * RCP lit ces options sans vérifier leur présence, et certains de ses
	 * chemins d'erreur supposent qu'une requête a abouti. Un site réel les a
	 * toujours renseignées.
	 *
	 * @return void
	 */
	protected function configure_rcp_stripe(): void {
		global $rcp_options;

		$rcp_options = is_array( $rcp_options ) ? $rcp_options : array();

		$rcp_options['sandbox']                 = 1;
		$rcp_options['currency']                = 'EUR';
		$rcp_options['stripe_test_secret']      = 'sk_test_123456789';
		$rcp_options['stripe_test_publishable'] = 'pk_test_123456789';
		$rcp_options['stripe_live_secret']      = '';
		$rcp_options['stripe_live_publishable'] = '';
	}

	/**
	 * Dirige le SDK Stripe vers stripe-mock.
	 *
	 * Modifier une adhésion conduit RCP à appeler Stripe — pour résilier un
	 * abonnement, par exemple. Sans serveur en face, sa gestion d'erreur
	 * échoue sur une réponse vide.
	 *
	 * @return void
	 */
	protected function redirect_stripe_to_mock(): void {
		\RCP_Stripe_Sepa\Support\StripeSdk::ensure_loaded();

		$this->stripe_api_base_before = \Stripe\Stripe::$apiBase;

		\Stripe\Stripe::$apiBase = $this->stripe_mock_base();

		\Stripe\Stripe::setApiKey( 'sk_test_123456789' );
	}

	/**
	 * Restaure la base d'API Stripe.
	 *
	 * @return void
	 */
	protected function restore_stripe_api_base(): void {
		if ( '' !== $this->stripe_api_base_before ) {
			\Stripe\Stripe::$apiBase = $this->stripe_api_base_before;
		}
	}

	/**
	 * Adresse de stripe-mock.
	 *
	 * @return string
	 */
	protected function stripe_mock_base(): string {
		$host = getenv( 'STRIPE_MOCK_HOST' ) ?: 'stripe-mock';
		$port = getenv( 'STRIPE_MOCK_PORT' ) ?: '12111';

		return 'http://' . $host . ':' . $port;
	}

	/**
	 * Crée un utilisateur WordPress avec une adresse unique.
	 *
	 * @return int
	 */
	protected function create_user(): int {
		$user_id = self::factory()->user->create(
			array( 'user_email' => 'membre+' . wp_generate_password( 10, false ) . '@example.test' )
		);

		$this->assertNotWPError( $user_id, 'Création d\'utilisateur' );
		$this->assertGreaterThan( 0, (int) $user_id );

		return (int) $user_id;
	}

	/**
	 * Crée un administrateur habilité à gérer Restrict Content Pro.
	 *
	 * RCP attribue ses capacités lors de son activation, qui ne se produit pas
	 * dans le harnais de tests : un simple administrateur WordPress n'a donc
	 * pas `rcp_manage_settings`.
	 *
	 * @return int
	 */
	protected function create_rcp_admin(): int {
		$user_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

		$user = get_userdata( $user_id );
		$user->add_cap( 'rcp_manage_settings' );
		$user->add_cap( 'rcp_view_members' );

		return $user_id;
	}

	/**
	 * Crée le client RCP d'un utilisateur, ou renvoie celui qui existe déjà.
	 *
	 * @param int $user_id Utilisateur WordPress.
	 * @return int
	 */
	protected function create_customer( int $user_id ): int {
		$existing = rcp_get_customer_by_user_id( $user_id );

		if ( ! empty( $existing ) ) {
			return (int) $existing->get_id();
		}

		$customer_id = rcp_add_customer( array( 'user_id' => $user_id ) );

		$this->assertNotWPError( $customer_id, 'Création de client' );
		$this->assertGreaterThan( 0, (int) $customer_id, 'Client non créé pour l\'utilisateur #' . $user_id );

		return (int) $customer_id;
	}

	/**
	 * Crée un niveau d'adhésion portant un nom unique.
	 *
	 * @param array $overrides Valeurs à remplacer.
	 * @return int
	 */
	protected function create_level( array $overrides = array() ): int {
		$level_id = rcp_add_membership_level(
			array_merge(
				array(
					'name'          => 'Niveau ' . wp_generate_password( 8, false ),
					'price'         => 10,
					'duration'      => 1,
					'duration_unit' => 'month',
					'status'        => 'active',
				),
				$overrides
			)
		);

		$this->assertNotWPError( $level_id, 'Création de niveau' );
		$this->assertGreaterThan( 0, (int) $level_id );

		return (int) $level_id;
	}

	/**
	 * Crée une adhésion complète : utilisateur, client, niveau et adhésion.
	 *
	 * @param array $overrides Valeurs à remplacer dans l'adhésion.
	 * @return int
	 */
	protected function create_membership( array $overrides = array() ): int {
		$user_id = isset( $overrides['user_id'] ) ? (int) $overrides['user_id'] : $this->create_user();

		unset( $overrides['user_id'] );

		$membership_id = rcp_add_membership(
			array_merge(
				array(
					'customer_id'      => $this->create_customer( $user_id ),
					'object_id'        => $this->create_level(),
					'object_type'      => 'membership',
					'status'           => 'pending',
					'subscription_key' => 'key_' . wp_generate_password( 12, false ),
					'auto_renew'       => 1,
				),
				$overrides
			)
		);

		$this->assertNotWPError( $membership_id, 'Création d\'adhésion' );
		$this->assertGreaterThan( 0, (int) $membership_id );

		return (int) $membership_id;
	}
}
