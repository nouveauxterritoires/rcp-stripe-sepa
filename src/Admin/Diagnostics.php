<?php
/**
 * Rapport de diagnostic du prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Admin;

use RCP_Stripe_Sepa\Compat\RcpEnvironment;
use RCP_Stripe_Sepa\Gateway\GatewayDefinition;
use RCP_Stripe_Sepa\Webhook\EventStore;
use RCP_Stripe_Sepa\Webhook\Settings;
use RCP_Stripe_Sepa\Webhook\WebhookSecret;

/**
 * Établit l'état de santé de la configuration.
 *
 * Une intégration de paiement échoue rarement bruyamment : elle échoue
 * silencieusement, et l'on s'en aperçoit lorsque des adhésions restent en
 * attente. Ce rapport rassemble en un écran ce qu'il faudrait autrement aller
 * chercher dans les réglages, la base et le tableau de bord Stripe.
 *
 * La construction du rapport est purement fonctionnelle : elle reçoit un
 * contexte et le traduit en contrôles, ce qui permet d'éprouver chaque règle
 * sans installation.
 *
 * @see docs/cahier-des-charges.md §6.5
 */
final class Diagnostics {

	public const STATUS_OK      = 'ok';
	public const STATUS_WARNING = 'warning';
	public const STATUS_ERROR   = 'error';

	/**
	 * Gravité relative des statuts, pour la synthèse et le tri.
	 *
	 * @var array<string, int>
	 */
	private const SEVERITY = array(
		self::STATUS_OK      => 0,
		self::STATUS_WARNING => 1,
		self::STATUS_ERROR   => 2,
	);

	/**
	 * Rassemble l'état réel de l'installation.
	 *
	 * @return array
	 */
	public static function report(): array {
		return self::build( self::collect() );
	}

	/**
	 * Construit le rapport à partir d'un contexte.
	 *
	 * Le contexte fourni n'est jamais modifié.
	 *
	 * @param array $context État de l'installation.
	 * @return array
	 */
	public static function build( array $context ): array {
		$checks = array(
			self::check_rcp( $context ),
			self::check_gateway( $context ),
			self::check_currency( $context ),
			self::check_https( $context ),
			self::check_webhook_secret( $context ),
			self::check_events( $context ),
			self::check_stale_payments( $context ),
		);

		$problems = array_values(
			array_filter(
				$checks,
				static function ( array $check ): bool {
					return self::STATUS_OK !== $check['status'];
				}
			)
		);

		usort(
			$problems,
			static function ( array $a, array $b ): int {
				return self::SEVERITY[ $b['status'] ] <=> self::SEVERITY[ $a['status'] ];
			}
		);

		return array(
			'status'    => self::worst( $checks ),
			'checks'    => $checks,
			'problems'  => $problems,
			'test_mode' => (bool) ( $context['test_mode'] ?? true ),
		);
	}

	// -- Contrôles ---------------------------------------------------------------

	/**
	 * Compatibilité avec Restrict Content Pro.
	 *
	 * @param array $context État de l'installation.
	 * @return array
	 */
	private static function check_rcp( array $context ): array {
		if ( ! empty( $context['rcp_supported'] ) ) {
			return self::ok( 'rcp', __( 'Restrict Content Pro', 'rcp-stripe-sepa' ), __( 'Compatible.', 'rcp-stripe-sepa' ) );
		}

		return self::error(
			'rcp',
			__( 'Restrict Content Pro', 'rcp-stripe-sepa' ),
			sprintf(
				/* translators: %s: liste des anomalies détectées. */
				__( 'Environnement incompatible : %s.', 'rcp-stripe-sepa' ),
				implode( ', ', (array) ( $context['rcp_issues'] ?? array() ) )
			)
		);
	}

	/**
	 * Activation de la passerelle dans les réglages de RCP.
	 *
	 * @param array $context État de l'installation.
	 * @return array
	 */
	private static function check_gateway( array $context ): array {
		$label = __( 'Passerelle', 'rcp-stripe-sepa' );

		if ( ! empty( $context['gateway_enabled'] ) ) {
			return self::ok( 'gateway', $label, __( 'Activée dans les réglages de RCP.', 'rcp-stripe-sepa' ) );
		}

		return self::warning(
			'gateway',
			$label,
			__( 'Désactivée : le prélèvement SEPA n\'est pas proposé à l\'inscription.', 'rcp-stripe-sepa' )
		);
	}

	/**
	 * Devise du site.
	 *
	 * @param array $context État de l'installation.
	 * @return array
	 */
	private static function check_currency( array $context ): array {
		$currency = strtoupper( (string) ( $context['currency'] ?? '' ) );
		$label    = __( 'Devise', 'rcp-stripe-sepa' );

		if ( GatewayDefinition::CURRENCY === $currency ) {
			return self::ok( 'currency', $label, $currency );
		}

		return self::error(
			'currency',
			$label,
			sprintf(
				/* translators: %s: code de la devise configurée. */
				__( 'Le prélèvement SEPA exige l\'euro ; la devise du site est %s.', 'rcp-stripe-sepa' ),
				$currency
			)
		);
	}

	/**
	 * Chiffrement du site.
	 *
	 * @param array $context État de l'installation.
	 * @return array
	 */
	private static function check_https( array $context ): array {
		$label = __( 'HTTPS', 'rcp-stripe-sepa' );

		if ( ! empty( $context['is_https'] ) ) {
			return self::ok( 'https', $label, __( 'Actif.', 'rcp-stripe-sepa' ) );
		}

		// Un environnement local en HTTP est la norme : le signaler comme une
		// erreur noierait les anomalies réelles.
		if ( ! empty( $context['test_mode'] ) ) {
			return self::warning(
				'https',
				$label,
				__( 'Absent. Acceptable en développement, indispensable en production.', 'rcp-stripe-sepa' )
			);
		}

		return self::error(
			'https',
			$label,
			__( 'Absent. Stripe.js et la collecte de mandat l\'exigent.', 'rcp-stripe-sepa' )
		);
	}

	/**
	 * Secret de signature des webhooks.
	 *
	 * @param array $context État de l'installation.
	 * @return array
	 */
	private static function check_webhook_secret( array $context ): array {
		$label = __( 'Secret de webhook', 'rcp-stripe-sepa' );

		if ( empty( $context['secret_present'] ) ) {
			return self::error(
				'webhook_secret',
				$label,
				__( 'Absent : aucun webhook ne peut être authentifié, les adhésions resteront en attente.', 'rcp-stripe-sepa' )
			);
		}

		if ( empty( $context['secret_locked'] ) ) {
			return self::warning(
				'webhook_secret',
				$label,
				__( 'Stocké en base. Préférez une constante dans wp-config.php, qui ne fuite ni dans un export ni dans une sauvegarde.', 'rcp-stripe-sepa' )
			);
		}

		return self::ok( 'webhook_secret', $label, __( 'Défini par constante.', 'rcp-stripe-sepa' ) );
	}

	/**
	 * Événements reçus.
	 *
	 * @param array $context État de l'installation.
	 * @return array
	 */
	private static function check_events( array $context ): array {
		$label  = __( 'Webhooks', 'rcp-stripe-sepa' );
		$failed = (int) ( $context['events_failed'] ?? 0 );
		$total  = (int) ( $context['events_total'] ?? 0 );

		if ( $failed > 0 ) {
			return self::error(
				'events',
				$label,
				sprintf(
					/* translators: %d: nombre d'événements abandonnés. */
					_n(
						'%d événement abandonné après plusieurs tentatives.',
						'%d événements abandonnés après plusieurs tentatives.',
						$failed,
						'rcp-stripe-sepa'
					),
					$failed
				)
			);
		}

		if ( 0 === $total ) {
			return self::warning(
				'events',
				$label,
				__( 'Aucun événement reçu. Vérifiez le point de terminaison déclaré chez Stripe.', 'rcp-stripe-sepa' )
			);
		}

		return self::ok(
			'events',
			$label,
			sprintf(
				/* translators: %d: nombre d'événements traités. */
				_n( '%d événement traité.', '%d événements traités.', $total, 'rcp-stripe-sepa' ),
				$total
			)
		);
	}

	/**
	 * Prélèvements restés en attente au-delà du délai configuré.
	 *
	 * @param array $context État de l'installation.
	 * @return array
	 */
	private static function check_stale_payments( array $context ): array {
		$label = __( 'Prélèvements en attente', 'rcp-stripe-sepa' );
		$stale = (int) ( $context['stale_payments'] ?? 0 );
		$days  = (int) ( $context['stale_after'] ?? 14 );

		if ( 0 === $stale ) {
			return self::ok( 'stale_payments', $label, __( 'Aucun retard.', 'rcp-stripe-sepa' ) );
		}

		return self::warning(
			'stale_payments',
			$label,
			sprintf(
				/* translators: 1: nombre de paiements, 2: nombre de jours. */
				_n(
					'%1$d prélèvement en attente depuis plus de %2$d jours : un webhook n\'arrive peut-être pas.',
					'%1$d prélèvements en attente depuis plus de %2$d jours : un webhook n\'arrive peut-être pas.',
					$stale,
					'rcp-stripe-sepa'
				),
				$stale,
				$days
			)
		);
	}

	// -- Fabrique de contrôles ------------------------------------------------------

	/**
	 * @param string $id     Identifiant.
	 * @param string $label  Intitulé.
	 * @param string $detail Détail.
	 * @return array
	 */
	private static function ok( string $id, string $label, string $detail ): array {
		return compact( 'id', 'label', 'detail' ) + array( 'status' => self::STATUS_OK );
	}

	/**
	 * @param string $id     Identifiant.
	 * @param string $label  Intitulé.
	 * @param string $detail Détail.
	 * @return array
	 */
	private static function warning( string $id, string $label, string $detail ): array {
		return compact( 'id', 'label', 'detail' ) + array( 'status' => self::STATUS_WARNING );
	}

	/**
	 * @param string $id     Identifiant.
	 * @param string $label  Intitulé.
	 * @param string $detail Détail.
	 * @return array
	 */
	private static function error( string $id, string $label, string $detail ): array {
		return compact( 'id', 'label', 'detail' ) + array( 'status' => self::STATUS_ERROR );
	}

	/**
	 * Statut le plus grave parmi les contrôles.
	 *
	 * @param array[] $checks Contrôles.
	 * @return string
	 */
	private static function worst( array $checks ): string {
		$worst = self::STATUS_OK;

		foreach ( $checks as $check ) {
			if ( self::SEVERITY[ $check['status'] ] > self::SEVERITY[ $worst ] ) {
				$worst = $check['status'];
			}
		}

		return $worst;
	}

	// -- Collecte de l'état réel ------------------------------------------------------

	/**
	 * Rassemble le contexte depuis l'installation.
	 *
	 * @return array
	 */
	private static function collect(): array {
		global $rcp_options;

		$environment = RcpEnvironment::detect();
		$events      = EventStore::recent( 100 );

		$failed = array_filter(
			$events,
			static function ( array $event ): bool {
				return EventStore::STATUS_FAILED === ( $event['status'] ?? '' );
			}
		);

		return array(
			'rcp_supported'   => $environment->is_supported(),
			'rcp_issues'      => $environment->issues(),
			'gateway_enabled' => ! empty( $rcp_options['gateways'][ GatewayDefinition::ID ] ),
			'currency'        => (string) rcp_get_currency(),
			'is_https'        => is_ssl(),
			'test_mode'       => WebhookSecret::is_test_mode(),
			'secret_present'  => '' !== WebhookSecret::get(),
			'secret_locked'   => WebhookSecret::is_locked(),
			'events_total'    => count( $events ),
			'events_failed'   => count( $failed ),
			'stale_payments'  => self::count_stale_payments(),
			'stale_after'     => Settings::pending_alert_days(),
		);
	}

	/**
	 * Compte les paiements SEPA restés en attente au-delà du délai configuré.
	 *
	 * @return int
	 */
	private static function count_stale_payments(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'rcp_payments';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
					WHERE status = %s
					AND gateway = %s
					AND date < DATE_SUB( %s, INTERVAL %d DAY )",
				'pending',
				GatewayDefinition::ID,
				current_time( 'mysql' ),
				Settings::pending_alert_days()
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
