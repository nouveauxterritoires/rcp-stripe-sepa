<?php
/**
 * Traduction des événements Stripe en transitions d'adhésion.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Membership;

/**
 * Règles de gestion du cycle de vie d'une adhésion payée par prélèvement SEPA.
 *
 * Cette classe est purement fonctionnelle : elle ne lit ni n'écrit rien, ce qui
 * permet de couvrir l'intégralité des règles par des tests rapides et de les
 * relire sans connaître RCP.
 *
 * Le fait structurant est l'asynchronisme du prélèvement SEPA : entre le mandat
 * et l'encaissement s'écoulent deux à quatorze jours ouvrés, pendant lesquels
 * l'abonnement Stripe est déjà `active`. Le statut d'abonnement ne peut donc
 * jamais décider seul de l'accès au contenu.
 *
 * @see docs/cahier-des-charges.md §8.3
 * @see docs/environnement-stripe-test.md §4
 */
final class StateMachine {

	// Statuts d'adhésion RCP.
	public const MEMBERSHIP_PENDING = 'pending';
	public const MEMBERSHIP_ACTIVE  = 'active';

	/*
	 * Révoquer un accès, c'est `expired`, jamais `cancelled`.
	 *
	 * `RCP_Membership::is_active()` tient pour actives les adhésions `active`
	 * *et* `cancelled` dont l'échéance n'est pas passée : une adhésion
	 * résiliée garde l'accès jusqu'au terme de la période, puisqu'elle a été
	 * réglée. Poser `cancelled` là où rien n'a été encaissé — ou là où les
	 * fonds ont été repris — laisse donc le contenu grand ouvert.
	 *
	 * Restrict Content Pro lui-même appelle `expire()` sur `charge.refunded`.
	 *
	 * `cancelled` reste juste pour `customer.subscription.deleted` : l'adhérent
	 * a réglé sa période et se désabonne pour la suite.
	 *
	 * Révélé par un test de bout en bout ; les tests d'intégration
	 * vérifiaient notre statut, pas la règle d'accès de RCP.
	 */
	public const MEMBERSHIP_CANCELLED = 'cancelled';
	public const MEMBERSHIP_EXPIRED   = 'expired';

	// Statuts de paiement RCP.
	public const PAYMENT_PENDING  = 'pending';
	public const PAYMENT_COMPLETE = 'complete';
	public const PAYMENT_FAILED   = 'failed';
	public const PAYMENT_REFUNDED = 'refunded';

	// Politiques d'accès au contenu pendant le traitement du prélèvement.
	public const ACCESS_STRICT     = 'strict';
	public const ACCESS_OPTIMISTIC = 'optimistic';

	// Conduite à tenir en cas de litige.
	public const DISPUTE_REVOKE = 'revoke';
	public const DISPUTE_NOTIFY = 'notify';

	/**
	 * Événements dont la classe sait déduire une transition.
	 *
	 * Tout autre événement est ignoré et acquitté par un 200 : il ne doit ni
	 * échouer ni être rejoué indéfiniment par Stripe.
	 *
	 * @return string[]
	 */
	public static function handled_events(): array {
		return array(
			'payment_intent.processing',
			'payment_intent.succeeded',
			'payment_intent.payment_failed',
			'setup_intent.succeeded',
			'setup_intent.setup_failed',
			'invoice.paid',
			'invoice.payment_failed',
			'charge.dispute.created',
			'charge.refunded',
			'customer.subscription.deleted',
		);
	}

	/**
	 * Déduit la transition à appliquer.
	 *
	 * @param string $event_type         Type d'événement Stripe.
	 * @param array  $stripe_object             Objet porté par l'événement.
	 * @param string $membership_status  Statut actuel de l'adhésion.
	 * @param string $access_policy      Politique d'accès configurée.
	 * @param string $dispute_policy     Conduite à tenir en cas de litige.
	 * @return Transition
	 */
	public static function resolve(
		string $event_type,
		array $stripe_object,
		string $membership_status,
		string $access_policy = self::ACCESS_STRICT,
		string $dispute_policy = self::DISPUTE_REVOKE
	): Transition {
		switch ( $event_type ) {
			case 'payment_intent.processing':
				return self::processing( $membership_status, $access_policy );

			case 'payment_intent.succeeded':
			case 'invoice.paid':
				return Transition::to(
					self::MEMBERSHIP_ACTIVE,
					self::PAYMENT_COMPLETE,
					'SEPA Direct Debit encaissé.'
				);

			case 'payment_intent.payment_failed':
				return self::failed( $membership_status );

			case 'invoice.payment_failed':
				return self::invoice_failed( $stripe_object, $membership_status );

			case 'setup_intent.succeeded':
				return Transition::to( null, null, 'Mandat SEPA enregistré.' );

			case 'setup_intent.setup_failed':
				return Transition::to(
					self::MEMBERSHIP_EXPIRED,
					self::PAYMENT_FAILED,
					'Mandat SEPA refusé.'
				);

			case 'charge.dispute.created':
				return self::dispute( $dispute_policy );

			case 'charge.refunded':
				return self::refund( $stripe_object );

			case 'customer.subscription.deleted':
				return Transition::to(
					self::MEMBERSHIP_CANCELLED,
					null,
					'Stripe subscription résilié.'
				);

			default:
				return Transition::skip( sprintf( 'Événement non géré : %s.', $event_type ) );
		}
	}

	// -- Règles ----------------------------------------------------------------

	/**
	 * Prélèvement engagé, encaissement non confirmé.
	 *
	 * @param string $membership_status Statut actuel.
	 * @param string $access_policy     Politique d'accès.
	 * @return Transition
	 */
	private static function processing( string $membership_status, string $access_policy ): Transition {
		if ( self::ACCESS_OPTIMISTIC === $access_policy ) {
			return Transition::to(
				self::MEMBERSHIP_ACTIVE,
				self::PAYMENT_PENDING,
				'SEPA Direct Debit engagé, accès ouvert par anticipation.'
			);
		}

		// Un renouvellement ne doit jamais retirer un accès déjà accordé.
		$target = self::MEMBERSHIP_ACTIVE === $membership_status
			? null
			: self::MEMBERSHIP_PENDING;

		return Transition::to(
			$target,
			self::PAYMENT_PENDING,
			'SEPA Direct Debit engagé, encaissement en attente.'
		);
	}

	/**
	 * Prélèvement refusé.
	 *
	 * @param string $membership_status Statut actuel.
	 * @return Transition
	 */
	private static function failed( string $membership_status ): Transition {
		if ( self::MEMBERSHIP_ACTIVE === $membership_status ) {
			// Renouvellement : la période en cours est réglée, l'adhésion
			// expirera d'elle-même si aucune relance n'aboutit.
			return Transition::to(
				null,
				self::PAYMENT_FAILED,
				'Renouvellement SEPA refusé, adhésion maintenue jusqu\'à son terme.'
			);
		}

		// Voir la note sur la révocation d'accès, en tête de classe.
		return Transition::to(
			self::MEMBERSHIP_EXPIRED,
			self::PAYMENT_FAILED,
			'Premier prélèvement SEPA refusé.'
		);
	}

	/**
	 * Facture en échec.
	 *
	 * @param array  $invoice           Objet facture.
	 * @param string $membership_status Statut actuel.
	 * @return Transition
	 */
	private static function invoice_failed( array $invoice, string $membership_status ): Transition {
		if ( ! self::was_debit_attempted( $invoice ) ) {
			/*
			 * À la création d'un abonnement SEPA, Stripe émet cet événement
			 * avant toute tentative de prélèvement, la facture attendant la
			 * confirmation du mandat. Le traiter comme un impayé résilierait
			 * des adhésions valides dès l'inscription.
			 */
			return Transition::skip( 'Facture en attente de confirmation du mandat, aucun prélèvement tenté.' );
		}

		return self::failed( $membership_status );
	}

	/**
	 * Un prélèvement a-t-il réellement été tenté pour cette facture ?
	 *
	 * @param array $invoice Objet facture.
	 * @return bool
	 */
	private static function was_debit_attempted( array $invoice ): bool {
		return (int) ( $invoice['attempt_count'] ?? 0 ) > 0
			&& ! empty( $invoice['charge'] );
	}

	/**
	 * Litige ouvert par le débiteur.
	 *
	 * @param string $dispute_policy Conduite à tenir.
	 * @return Transition
	 */
	private static function dispute( string $dispute_policy ): Transition {
		if ( self::DISPUTE_NOTIFY === $dispute_policy ) {
			return Transition::to( null, null, 'Litige SEPA ouvert, accès maintenu selon la configuration.' );
		}

		return Transition::to(
			self::MEMBERSHIP_EXPIRED,
			null,
			'Litige SEPA ouvert, accès révoqué.'
		);
	}

	/**
	 * Remboursement, total ou partiel.
	 *
	 * @param array $charge Objet charge.
	 * @return Transition
	 */
	private static function refund( array $charge ): Transition {
		$amount   = (int) ( $charge['amount'] ?? 0 );
		$refunded = (int) ( $charge['amount_refunded'] ?? 0 );
		$is_full  = ! empty( $charge['refunded'] ) || ( $amount > 0 && $refunded >= $amount );

		if ( $is_full ) {
			return Transition::to(
				self::MEMBERSHIP_EXPIRED,
				self::PAYMENT_REFUNDED,
				'SEPA Direct Debit intégralement remboursé.'
			);
		}

		return Transition::to( null, self::PAYMENT_REFUNDED, 'SEPA Direct Debit partiellement remboursé.' );
	}
}
