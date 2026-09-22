<?php
/**
 * Formulaire de bascule vers le prélèvement SEPA.
 *
 * Comme à l'inscription, l'IBAN est saisi dans un Stripe Element : il ne
 * transite pas par le serveur WordPress.
 *
 * @package RCP_Stripe_Sepa
 *
 * @var RCP_Membership $membership Adhésion concernée.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$rcp_sepa_form_id = 'rcp-stripe-sepa-migration-' . (int) $membership->get_id();

?>
<section
	id="<?php echo esc_attr( $rcp_sepa_form_id ); ?>"
	class="rcp-stripe-sepa-migration"
	data-membership="<?php echo esc_attr( (string) $membership->get_id() ); ?>"
	hidden
>
	<h3><?php esc_html_e( 'Switch to SEPA Direct Debit', 'rcp-stripe-sepa' ); ?></h3>

	<?php
	// Bandeau de mode test : déjà échappé, et vide hors bac à sable (SEC-23).
	echo \RCP_Stripe_Sepa\Mode\TestBanner::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>

	<p class="rcp-stripe-sepa-migration-intro">
		<?php
		printf(
			/* translators: %s: membership level name. */
			esc_html__( 'Membership concerned: %s. The price and next renewal date stay the same.', 'rcp-stripe-sepa' ),
			esc_html( $membership->get_membership_level_name() )
		);
		?>
	</p>

	<p class="rcp-stripe-sepa-holder">
		<label for="<?php echo esc_attr( $rcp_sepa_form_id ); ?>-holder">
			<?php esc_html_e( 'Account holder’s name', 'rcp-stripe-sepa' ); ?>
			<span class="rcp-required" aria-hidden="true">*</span>
		</label>
		<input
			type="text"
			id="<?php echo esc_attr( $rcp_sepa_form_id ); ?>-holder"
			class="rcp-stripe-sepa-holder-name"
			autocomplete="name"
			required
		/>
	</p>

	<p class="rcp-stripe-sepa-iban">
		<label for="<?php echo esc_attr( $rcp_sepa_form_id ); ?>-iban">
			<?php esc_html_e( 'IBAN', 'rcp-stripe-sepa' ); ?>
			<span class="rcp-required" aria-hidden="true">*</span>
		</label>
		<span
			id="<?php echo esc_attr( $rcp_sepa_form_id ); ?>-iban"
			class="rcp-stripe-sepa-element"
		></span>
	</p>

	<div class="rcp-stripe-sepa-errors" role="alert" aria-live="polite"></div>

	<div class="rcp-stripe-sepa-mandate">
		<?php
		$rcp_sepa_mandate_text = sprintf(
			/* translators: %s: site name, acting as the creditor. */
			__(
				'By providing your IBAN and confirming this mandate, you authorise %s and Stripe, our payment provider, to send instructions to your bank to debit your account, and your bank to debit your account in accordance with those instructions. You are entitled to a refund from your bank under the terms of your agreement with it. A refund must be claimed within 8 weeks of the date on which your account was debited.',
				'rcp-stripe-sepa'
			),
			esc_html( get_bloginfo( 'name' ) )
		);

		/** This filter is documented in templates/sepa-fields.php */
		echo wp_kses_post( apply_filters( 'rcp_stripe_sepa_mandate_text', $rcp_sepa_mandate_text ) );
		?>
	</div>

	<p>
		<button type="button" class="rcp-stripe-sepa-migrate-submit">
			<?php esc_html_e( 'Confirm the mandate', 'rcp-stripe-sepa' ); ?>
		</button>
	</p>
</section>
