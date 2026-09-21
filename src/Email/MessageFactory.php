<?php
/**
 * Composition des e-mails transactionnels du prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Email;

/**
 * Compose objet et corps des messages, sans les envoyer.
 *
 * Le prélèvement SEPA crée un moment que la carte ne connaît pas : entre la
 * signature du mandat et l'encaissement s'écoulent plusieurs jours, pendant
 * lesquels l'adhérent croit son adhésion active. L'expliquer par écrit évite
 * autant de messages au support que de contestations.
 *
 * Chaque message est filtrable : un site a sa propre voix.
 */
final class MessageFactory {

	public const DEBIT_INITIATED = 'debit_initiated';
	public const DEBIT_FAILED    = 'debit_failed';
	public const MANDATE_UPDATED = 'mandate_updated';
	public const DISPUTE_OPENED  = 'dispute_opened';
	public const EVENT_ABANDONED = 'event_abandoned';

	/**
	 * Types de message connus.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array(
			self::DEBIT_INITIATED,
			self::DEBIT_FAILED,
			self::MANDATE_UPDATED,
			self::DISPUTE_OPENED,
			self::EVENT_ABANDONED,
		);
	}

	/**
	 * Compose un message.
	 *
	 * Le contexte fourni n'est jamais modifié.
	 *
	 * @param string $type    Type de message.
	 * @param array  $context Données d'interpolation.
	 * @return array{subject?: string, body?: string} Tableau vide si le type est inconnu.
	 */
	public static function build( string $type, array $context ): array {
		$message = self::compose( $type, $context );

		if ( array() === $message ) {
			return array();
		}

		/**
		 * Filtre un e-mail du prélèvement SEPA avant envoi.
		 *
		 * @since 0.1.0
		 *
		 * @param array  $message Objet et corps du message.
		 * @param string $type    Type de message.
		 * @param array  $context Données d'interpolation.
		 */
		return (array) apply_filters( 'rcp_stripe_sepa_email', $message, $type, $context );
	}

	/**
	 * Compose le message par défaut.
	 *
	 * @param string $type    Type de message.
	 * @param array  $context Données d'interpolation.
	 * @return array
	 */
	private static function compose( string $type, array $context ): array {
		$site  = (string) ( $context['site_name'] ?? '' );
		$level = (string) ( $context['level_name'] ?? '' );

		switch ( $type ) {
			case self::DEBIT_INITIATED:
				return array(
					'subject' => sprintf(
						/* translators: %s: membership level name. */
						__( 'Your SEPA Direct Debit for “%s” is under way', 'rcp-stripe-sepa' ),
						$level
					),
					'body'    => sprintf(
						/* translators: 1: membership level, 2: number of days, 3: site name. */
						__(
							"We have saved your mandate for the “%1\$s” membership.\n\nA SEPA Direct Debit takes up to %2\$d working days for your bank to settle. Your membership will be activated as soon as we receive confirmation of the payment — there is nothing else for you to do.\n\nThe %3\$s team",
							'rcp-stripe-sepa'
						),
						$level,
						(int) ( $context['delay_days'] ?? 14 ),
						$site
					),
				);

			case self::DEBIT_FAILED:
				return array(
					'subject' => sprintf(
						/* translators: %s: membership level name. */
						__( 'Your SEPA Direct Debit for “%s” was declined', 'rcp-stripe-sepa' ),
						$level
					),
					'body'    => self::squeeze(
						sprintf(
							/* translators: 1: membership level, 2: decline reason, 3: site name. */
							__(
								"The debit for your “%1\$s” membership could not be collected. %2\$s\n\nYou can start your registration again, or contact us if you believe this is a mistake.\n\nThe %3\$s team",
								'rcp-stripe-sepa'
							),
							$level,
							(string) ( $context['reason'] ?? '' ),
							$site
						)
					),
				);

			case self::MANDATE_UPDATED:
				return array(
					'subject' => __( 'Your new SEPA mandate has been saved', 'rcp-stripe-sepa' ),
					'body'    => sprintf(
						/* translators: 1: membership level, 2: site name. */
						__(
							"Your “%1\$s” membership is now paid by SEPA Direct Debit.\n\nThe amount and date of your next renewal are unchanged.\n\nThe %2\$s team",
							'rcp-stripe-sepa'
						),
						$level,
						$site
					),
				);

			case self::DISPUTE_OPENED:
				return array(
					'subject' => __( 'SEPA dispute opened on a membership', 'rcp-stripe-sepa' ),
					'body'    => sprintf(
						/* translators: %d: membership identifier. */
						__(
							"A debtor has disputed a debit. The membership concerned has the identifier #%d.\n\nOpen your Stripe dashboard to respond to the dispute within the allotted time.",
							'rcp-stripe-sepa'
						),
						(int) ( $context['membership_id'] ?? 0 )
					),
				);

			case self::EVENT_ABANDONED:
				return array(
					'subject' => __( 'A Stripe event could not be processed', 'rcp-stripe-sepa' ),
					'body'    => sprintf(
						/* translators: 1: event identifier, 2: event type. */
						__(
							"Event %1\$s (%2\$s) was abandoned after several attempts.\n\nOpen the “SEPA Direct Debit” admin screen to review it and replay it.",
							'rcp-stripe-sepa'
						),
						(string) ( $context['event_id'] ?? '' ),
						(string) ( $context['event_type'] ?? '' )
					),
				);

			default:
				return array();
		}
	}

	/**
	 * Supprime les espaces laissés par une interpolation vide.
	 *
	 * @param string $text Texte composé.
	 * @return string
	 */
	private static function squeeze( string $text ): string {
		return (string) preg_replace( '/[ \t]{2,}/', ' ', $text );
	}
}
