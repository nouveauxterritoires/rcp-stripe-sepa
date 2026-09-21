<?php
/**
 * Mandat SEPA en vigueur, sur la page « Mon compte ».
 *
 * @package RCP_Stripe_Sepa
 *
 * @var RCP_Membership $membership  Adhésion concernée.
 * @var array          $mandate     Données de mandat.
 * @var string         $masked_iban IBAN masqué.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

?>
<section class="rcp-stripe-sepa-mandate-details">
	<h3><?php esc_html_e( 'Prélèvement SEPA en vigueur', 'rcp-stripe-sepa' ); ?></h3>

	<dl>
		<?php if ( '' !== $masked_iban ) : ?>
			<dt><?php esc_html_e( 'Compte débité', 'rcp-stripe-sepa' ); ?></dt>
			<dd><?php echo esc_html( $masked_iban ); ?></dd>
		<?php endif; ?>

		<?php if ( '' !== (string) $mandate['account_holder_name'] ) : ?>
			<dt><?php esc_html_e( 'Titulaire', 'rcp-stripe-sepa' ); ?></dt>
			<dd><?php echo esc_html( (string) $mandate['account_holder_name'] ); ?></dd>
		<?php endif; ?>

		<?php if ( '' !== (string) $mandate['mandate_reference'] ) : ?>
			<dt><?php esc_html_e( 'Référence du mandat', 'rcp-stripe-sepa' ); ?></dt>
			<dd><?php echo esc_html( (string) $mandate['mandate_reference'] ); ?></dd>
		<?php endif; ?>

		<?php if ( '' !== (string) $mandate['accepted_at'] ) : ?>
			<dt><?php esc_html_e( 'Mandat signé le', 'rcp-stripe-sepa' ); ?></dt>
			<dd>
				<?php
				echo esc_html(
					date_i18n(
						(string) get_option( 'date_format' ),
						(int) strtotime( (string) $mandate['accepted_at'] )
					)
				);
				?>
			</dd>
		<?php endif; ?>
	</dl>

	<?php if ( '' !== (string) $mandate['mandate_url'] ) : ?>
		<p>
			<a href="<?php echo esc_url( (string) $mandate['mandate_url'] ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Consulter le mandat signé', 'rcp-stripe-sepa' ); ?>
			</a>
		</p>
	<?php endif; ?>
</section>
