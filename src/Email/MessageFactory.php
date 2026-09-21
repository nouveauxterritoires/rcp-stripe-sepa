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
						/* translators: %s: nom du niveau d'adhésion. */
						__( 'Votre prélèvement SEPA pour « %s » est en cours', 'rcp-stripe-sepa' ),
						$level
					),
					'body'    => sprintf(
						/* translators: 1: niveau d'adhésion, 2: nombre de jours, 3: nom du site. */
						__(
							"Nous avons bien enregistré votre mandat pour l'adhésion « %1\$s ».\n\nUn prélèvement SEPA demande jusqu'à %2\$d jours ouvrés pour être encaissé par votre banque. Votre adhésion sera activée dès que nous aurons reçu la confirmation du paiement — vous n'avez rien d'autre à faire.\n\nL'équipe de %3\$s",
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
						/* translators: %s: nom du niveau d'adhésion. */
						__( 'Votre prélèvement SEPA pour « %s » a été refusé', 'rcp-stripe-sepa' ),
						$level
					),
					'body'    => self::squeeze(
						sprintf(
							/* translators: 1: niveau d'adhésion, 2: motif du refus, 3: nom du site. */
							__(
								"Le prélèvement pour votre adhésion « %1\$s » n'a pas pu être encaissé. %2\$s\n\nVous pouvez reprendre votre inscription ou nous contacter si vous pensez qu'il s'agit d'une erreur.\n\nL'équipe de %3\$s",
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
					'subject' => __( 'Votre nouveau mandat SEPA est enregistré', 'rcp-stripe-sepa' ),
					'body'    => sprintf(
						/* translators: 1: niveau d'adhésion, 2: nom du site. */
						__(
							"Votre adhésion « %1\$s » est désormais réglée par prélèvement SEPA.\n\nLe montant et la date de votre prochaine échéance sont inchangés.\n\nL'équipe de %2\$s",
							'rcp-stripe-sepa'
						),
						$level,
						$site
					),
				);

			case self::DISPUTE_OPENED:
				return array(
					'subject' => __( 'Litige SEPA ouvert sur une adhésion', 'rcp-stripe-sepa' ),
					'body'    => sprintf(
						/* translators: %d: identifiant de l'adhésion. */
						__(
							"Un débiteur a contesté un prélèvement. L'adhésion concernée porte l'identifiant #%d.\n\nConsultez votre tableau de bord Stripe pour répondre au litige dans les délais impartis.",
							'rcp-stripe-sepa'
						),
						(int) ( $context['membership_id'] ?? 0 )
					),
				);

			case self::EVENT_ABANDONED:
				return array(
					'subject' => __( 'Un événement Stripe n\'a pas pu être traité', 'rcp-stripe-sepa' ),
					'body'    => sprintf(
						/* translators: 1: identifiant de l'événement, 2: type d'événement. */
						__(
							"L'événement %1\$s (%2\$s) a été abandonné après plusieurs tentatives.\n\nOuvrez l'écran « Prélèvement SEPA » de l'administration pour en consulter le détail et le rejouer.",
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
