<?php
/**
 * Mandat SEPA sur la fiche d'adhésion.
 *
 * @package RCP_Stripe_Sepa
 *
 * @var array<string, string> $rows  Informations du mandat.
 * @var array<string, string> $links Liens vers le tableau de bord Stripe.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

?>
<h3><?php esc_html_e( 'Mandat de prélèvement SEPA', 'rcp-stripe-sepa' ); ?></h3>

<table class="form-table rcp-stripe-sepa-mandate-table">
	<tbody>
		<?php foreach ( $rows as $rcp_sepa_label => $rcp_sepa_value ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html( $rcp_sepa_label ); ?></th>
				<td><?php echo esc_html( $rcp_sepa_value ); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php if ( ! empty( $links ) ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Chez Stripe', 'rcp-stripe-sepa' ); ?></th>
				<td>
					<?php foreach ( $links as $rcp_sepa_label => $rcp_sepa_url ) : ?>
						<a href="<?php echo esc_url( $rcp_sepa_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $rcp_sepa_label ); ?>
						</a><br/>
					<?php endforeach; ?>
				</td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>
