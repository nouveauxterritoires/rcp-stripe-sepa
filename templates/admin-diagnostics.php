<?php
/**
 * Écran de diagnostic du prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 *
 * @var array        $report   Rapport de diagnostic.
 * @var array[]      $events   Most recent events.
 * @var string       $endpoint URL du point de terminaison.
 * @var array|false  $notice   Message à afficher, le cas échéant.
 */

declare( strict_types = 1 );

use RCP_Stripe_Sepa\Admin\Diagnostics;
use RCP_Stripe_Sepa\Admin\DiagnosticsPage;
use RCP_Stripe_Sepa\Webhook\EventStore;

defined( 'ABSPATH' ) || exit;

/**
 * Pastille correspondant à un statut.
 *
 * @param string $status Statut du contrôle.
 * @return string
 */
$rcp_sepa_badge = static function ( string $status ): string {
	$badges = array(
		Diagnostics::STATUS_OK      => '<span class="rcp-sepa-badge rcp-sepa-badge-ok" aria-hidden="true">●</span>',
		Diagnostics::STATUS_WARNING => '<span class="rcp-sepa-badge rcp-sepa-badge-warning" aria-hidden="true">●</span>',
		Diagnostics::STATUS_ERROR   => '<span class="rcp-sepa-badge rcp-sepa-badge-error" aria-hidden="true">●</span>',
	);

	return $badges[ $status ] ?? '';
};

/**
 * Libellé accessible d'un statut.
 *
 * @param string $status Statut du contrôle.
 * @return string
 */
$rcp_sepa_status_label = static function ( string $status ): string {
	$labels = array(
		Diagnostics::STATUS_OK      => __( 'Passed', 'rcp-stripe-sepa' ),
		Diagnostics::STATUS_WARNING => __( 'Needs attention', 'rcp-stripe-sepa' ),
		Diagnostics::STATUS_ERROR   => __( 'Blocking', 'rcp-stripe-sepa' ),
	);

	return $labels[ $status ] ?? '';
};

?>
<div class="wrap rcp-stripe-sepa-diagnostics">
	<h1><?php esc_html_e( 'SEPA Direct Debit', 'rcp-stripe-sepa' ); ?></h1>

	<?php if ( is_array( $notice ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $report['test_mode'] ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'Stripe test mode.', 'rcp-stripe-sepa' ); ?></strong>
				<?php esc_html_e( 'No real debit is taken.', 'rcp-stripe-sepa' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Configuration status', 'rcp-stripe-sepa' ); ?></h2>

	<table class="widefat striped">
		<caption class="screen-reader-text">
			<?php esc_html_e( 'SEPA Direct Debit configuration checks', 'rcp-stripe-sepa' ); ?>
		</caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Check', 'rcp-stripe-sepa' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'rcp-stripe-sepa' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Details', 'rcp-stripe-sepa' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $report['checks'] as $rcp_sepa_check ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $rcp_sepa_check['label'] ); ?></th>
					<td>
						<?php echo wp_kses_post( $rcp_sepa_badge( $rcp_sepa_check['status'] ) ); ?>
						<?php echo esc_html( $rcp_sepa_status_label( $rcp_sepa_check['status'] ) ); ?>
					</td>
					<td><?php echo esc_html( $rcp_sepa_check['detail'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Endpoint', 'rcp-stripe-sepa' ); ?></h2>

	<p>
		<?php esc_html_e( 'Declare this URL as a webhook endpoint in your Stripe dashboard:', 'rcp-stripe-sepa' ); ?>
	</p>
	<p><code><?php echo esc_html( $endpoint ); ?></code></p>

	<h2><?php esc_html_e( 'Most recent events', 'rcp-stripe-sepa' ); ?></h2>

	<?php if ( empty( $events ) ) : ?>
		<p><?php esc_html_e( 'No event received yet.', 'rcp-stripe-sepa' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<caption class="screen-reader-text">
				<?php esc_html_e( 'The twenty most recent webhook events received', 'rcp-stripe-sepa' ); ?>
			</caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Received', 'rcp-stripe-sepa' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'rcp-stripe-sepa' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Outcome', 'rcp-stripe-sepa' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Attempts', 'rcp-stripe-sepa' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Note', 'rcp-stripe-sepa' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'rcp-stripe-sepa' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $events as $rcp_sepa_event ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $rcp_sepa_event['created_at'] ); ?></td>
						<td><code><?php echo esc_html( (string) $rcp_sepa_event['event_type'] ); ?></code></td>
						<td>
							<?php
							$rcp_sepa_outcome = (string) $rcp_sepa_event['status'];

							echo wp_kses_post(
								$rcp_sepa_badge(
									EventStore::STATUS_FAILED === $rcp_sepa_outcome
										? Diagnostics::STATUS_ERROR
										: Diagnostics::STATUS_OK
								)
							);
							echo esc_html( $rcp_sepa_outcome );
							?>
						</td>
						<td><?php echo esc_html( (string) $rcp_sepa_event['attempts'] ); ?></td>
						<td><?php echo esc_html( (string) $rcp_sepa_event['note'] ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( DiagnosticsPage::REPLAY_ACTION ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( DiagnosticsPage::REPLAY_ACTION ); ?>" />
								<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $rcp_sepa_event['event_id'] ); ?>" />
								<button type="submit" class="button button-secondary">
									<?php esc_html_e( 'Replay', 'rcp-stripe-sepa' ); ?>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<style>
	.rcp-sepa-badge { font-size: 1.1em; margin-right: 0.3em; }
	.rcp-sepa-badge-ok { color: #00a32a; }
	.rcp-sepa-badge-warning { color: #dba617; }
	.rcp-sepa-badge-error { color: #d63638; }
	.rcp-stripe-sepa-diagnostics table { margin-bottom: 2em; }
	.rcp-stripe-sepa-diagnostics form { margin: 0; }
</style>
