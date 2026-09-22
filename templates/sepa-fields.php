<?php
/**
 * Formulaire de collecte du mandat SEPA.
 *
 * Aucune donnée bancaire ne transite par ce formulaire : l'IBAN est saisi dans
 * un Stripe Element, c'est-à-dire une iframe servie par Stripe. Le serveur
 * WordPress ne voit jamais l'IBAN complet.
 *
 * @package RCP_Stripe_Sepa
 * @see docs/cahier-des-charges.md §9.4 SEC-12
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

?>
<fieldset class="rcp-stripe-sepa-fields">
	<legend class="screen-reader-text"><?php esc_html_e( 'Bank details', 'rcp-stripe-sepa' ); ?></legend>

	<?php
	// Bandeau de mode test : déjà échappé, et vide hors bac à sable (SEC-23).
	echo \RCP_Stripe_Sepa\Mode\TestBanner::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>

	<p class="rcp_card_fields rcp-stripe-sepa-holder">
		<label for="rcp-stripe-sepa-holder-name">
			<?php esc_html_e( 'Account holder’s name', 'rcp-stripe-sepa' ); ?>
			<span class="rcp-required" aria-hidden="true">*</span>
		</label>
		<input
			type="text"
			id="rcp-stripe-sepa-holder-name"
			class="rcp-stripe-sepa-holder-name"
			name="rcp_stripe_sepa_holder_name"
			autocomplete="name"
			required
		/>
	</p>

	<p class="rcp_card_fields rcp-stripe-sepa-iban">
		<label for="rcp-stripe-sepa-iban-element">
			<?php esc_html_e( 'IBAN', 'rcp-stripe-sepa' ); ?>
			<span class="rcp-required" aria-hidden="true">*</span>
		</label>
		<span id="rcp-stripe-sepa-iban-element" class="rcp-stripe-sepa-element"></span>
	</p>

	<div
		id="rcp-stripe-sepa-errors"
		class="rcp-stripe-sepa-errors"
		role="alert"
		aria-live="polite"
	></div>

	<div class="rcp-stripe-sepa-mandate">
		<?php
		/*
		 * Mentions imposées par le schéma SEPA : identité du créancier, nature
		 * récurrente ou ponctuelle du prélèvement, et droit au remboursement
		 * dans les huit semaines. La soumission du formulaire vaut signature.
		 */
		$rcp_sepa_mandate_text = sprintf(
			/* translators: %s: site name, acting as the creditor. */
			__(
				'By providing your IBAN and confirming this payment, you authorise %s and Stripe, our payment provider, to send instructions to your bank to debit your account, and your bank to debit your account in accordance with those instructions. You are entitled to a refund from your bank under the terms of your agreement with it. A refund must be claimed within 8 weeks of the date on which your account was debited.',
				'rcp-stripe-sepa'
			),
			esc_html( get_bloginfo( 'name' ) )
		);

		/**
		 * Filtre le texte du mandat SEPA présenté au débiteur.
		 *
		 * Toute personnalisation doit conserver les mentions obligatoires :
		 * identité du créancier, nature du prélèvement et droit au
		 * remboursement sous huit semaines.
		 *
		 * @since 0.1.0
		 *
		 * @param string $rcp_sepa_mandate_text Texte du mandat.
		 */
		echo wp_kses_post( apply_filters( 'rcp_stripe_sepa_mandate_text', $rcp_sepa_mandate_text ) );
		?>
	</div>
</fieldset>
