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
	<h3><?php esc_html_e( 'Passer au prélèvement SEPA', 'rcp-stripe-sepa' ); ?></h3>

	<p class="rcp-stripe-sepa-migration-intro">
		<?php
		printf(
			/* translators: %s: nom du niveau d'adhésion. */
			esc_html__( 'Adhésion concernée : %s. Le prix et la date de prochaine échéance restent inchangés.', 'rcp-stripe-sepa' ),
			esc_html( $membership->get_membership_level_name() )
		);
		?>
	</p>

	<p class="rcp-stripe-sepa-holder">
		<label for="<?php echo esc_attr( $rcp_sepa_form_id ); ?>-holder">
			<?php esc_html_e( 'Nom du titulaire du compte', 'rcp-stripe-sepa' ); ?>
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
			/* translators: %s: nom du site, agissant comme créancier. */
			__(
				'En fournissant votre IBAN et en confirmant ce mandat, vous autorisez %s et Stripe, notre prestataire de paiement, à envoyer des instructions à votre banque pour débiter votre compte, et votre banque à débiter votre compte conformément à ces instructions. Vous bénéficiez d\'un droit à remboursement par votre banque selon les conditions décrites dans la convention que vous avez passée avec elle. Toute demande de remboursement doit être présentée dans les 8 semaines suivant la date de débit de votre compte.',
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
			<?php esc_html_e( 'Confirmer le mandat', 'rcp-stripe-sepa' ); ?>
		</button>
	</p>
</section>
