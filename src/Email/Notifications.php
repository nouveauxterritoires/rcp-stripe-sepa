<?php
/**
 * Envoi des e-mails transactionnels du prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Email;

use RCP_Membership;
use RCP_Stripe_Sepa\Membership\StateMachine;
use RCP_Stripe_Sepa\Membership\Transition;
use RCP_Stripe_Sepa\Webhook\Settings;

/**
 * Relie les événements du plugin aux messages à envoyer.
 *
 * Les notifications de RCP couvrent déjà l'activation et l'expiration d'une
 * adhésion : celles-ci ne les doublonnent pas. Elles comblent ce que RCP ne
 * connaît pas — l'attente propre au prélèvement, son refus, et les incidents
 * qui appellent une intervention.
 */
final class Notifications {

	/**
	 * Branche les envois sur les événements du plugin.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rcp_stripe_sepa_transition_applied', array( self::class, 'on_transition' ), 10, 3 );
		add_action( 'rcp_stripe_sepa_migrated', array( self::class, 'on_migration' ), 10, 1 );
		add_action( 'rcp_stripe_sepa_webhook_abandoned', array( self::class, 'on_abandoned' ), 10, 2 );
	}

	/**
	 * Notifie l'adhérent d'un changement d'état de son prélèvement.
	 *
	 * @param mixed $membership Adhésion concernée.
	 * @param mixed $transition Transition appliquée.
	 * @param array $event      Événement Stripe.
	 * @return void
	 */
	public static function on_transition( $membership, $transition, $event = array() ): void {
		if ( ! $membership instanceof RCP_Membership || ! $transition instanceof Transition ) {
			return;
		}

		$type = (string) ( $event['type'] ?? '' );

		if ( 'charge.dispute.created' === $type ) {
			self::notify_admin( MessageFactory::DISPUTE_OPENED, array( 'membership_id' => $membership->get_id() ) );

			return;
		}

		$message_type = self::message_for( $transition );

		if ( '' === $message_type ) {
			return;
		}

		self::notify_member(
			$membership,
			$message_type,
			array( 'reason' => $transition->reason() )
		);
	}

	/**
	 * Confirme à l'adhérent la bascule de son moyen de paiement.
	 *
	 * @param mixed $membership Adhésion migrée.
	 * @return void
	 */
	public static function on_migration( $membership ): void {
		if ( $membership instanceof RCP_Membership ) {
			self::notify_member( $membership, MessageFactory::MANDATE_UPDATED );
		}
	}

	/**
	 * Alerte l'administrateur d'un événement abandonné.
	 *
	 * @param string $event_id   Identifiant de l'événement.
	 * @param string $event_type Type d'événement.
	 * @return void
	 */
	public static function on_abandoned( $event_id = '', $event_type = '' ): void {
		self::notify_admin(
			MessageFactory::EVENT_ABANDONED,
			array(
				'event_id'   => (string) $event_id,
				'event_type' => (string) $event_type,
			)
		);
	}

	// -- Choix du message -----------------------------------------------------------

	/**
	 * Message correspondant à une transition, ou chaîne vide.
	 *
	 * @param Transition $transition Transition appliquée.
	 * @return string
	 */
	private static function message_for( Transition $transition ): string {
		switch ( $transition->payment_status() ) {
			case StateMachine::PAYMENT_PENDING:
				return MessageFactory::DEBIT_INITIATED;

			case StateMachine::PAYMENT_FAILED:
				return MessageFactory::DEBIT_FAILED;

			default:
				// L'encaissement réussi déclenche déjà l'e-mail d'activation de
				// RCP : en ajouter un serait redondant.
				return '';
		}
	}

	// -- Envoi ------------------------------------------------------------------------

	/**
	 * Envoie un message à l'adhérent.
	 *
	 * @param RCP_Membership $membership Adhésion concernée.
	 * @param string         $type       Type de message.
	 * @param array          $extra      Données supplémentaires.
	 * @return void
	 */
	private static function notify_member( RCP_Membership $membership, string $type, array $extra = array() ): void {
		$user = get_userdata( (int) $membership->get_user_id() );

		if ( ! $user || '' === (string) $user->user_email ) {
			return;
		}

		self::send( $user->user_email, $type, array_merge( self::context( $membership ), $extra ) );
	}

	/**
	 * Envoie un message à l'adresse d'administration.
	 *
	 * @param string $type  Type de message.
	 * @param array  $extra Données supplémentaires.
	 * @return void
	 */
	private static function notify_admin( string $type, array $extra = array() ): void {
		/**
		 * Filtre l'adresse recevant les alertes du prélèvement SEPA.
		 *
		 * @since 0.1.0
		 *
		 * @param string $email Adresse de destination.
		 */
		$email = (string) apply_filters( 'rcp_stripe_sepa_admin_email', get_option( 'admin_email' ) );

		if ( '' === $email ) {
			return;
		}

		self::send( $email, $type, array_merge( self::context(), $extra ) );
	}

	/**
	 * Contexte commun aux messages.
	 *
	 * @param RCP_Membership|null $membership Adhésion concernée, le cas échéant.
	 * @return array
	 */
	private static function context( ?RCP_Membership $membership = null ): array {
		return array(
			'site_name'  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'level_name' => null !== $membership ? (string) $membership->get_membership_level_name() : '',
			'delay_days' => Settings::pending_alert_days(),
		);
	}

	/**
	 * Compose puis envoie un message.
	 *
	 * @param string $to      Destinataire.
	 * @param string $type    Type de message.
	 * @param array  $context Données d'interpolation.
	 * @return void
	 */
	private static function send( string $to, string $type, array $context ): void {
		$message = MessageFactory::build( $type, $context );

		if ( array() === $message ) {
			return;
		}

		wp_mail( $to, $message['subject'], $message['body'] );

		/**
		 * Se déclenche après l'envoi d'un e-mail du prélèvement SEPA.
		 *
		 * @since 0.1.0
		 *
		 * @param string $to      Destinataire.
		 * @param string $type    Type de message.
		 * @param array  $message Objet et corps envoyés.
		 */
		do_action( 'rcp_stripe_sepa_email_sent', $to, $type, $message );
	}
}
