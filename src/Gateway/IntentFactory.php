<?php
/**
 * Construction des intentions Stripe pour le prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Gateway;

/**
 * Fabrique les arguments des PaymentIntent et SetupIntent.
 *
 * La classe est purement fonctionnelle : elle n'appelle pas Stripe, ce qui
 * permet de couvrir par des tests rapides les règles qui distinguent réellement
 * un prélèvement SEPA d'un paiement par carte.
 *
 * Restrict Content Pro n'expose aucun filtre sur les arguments de SetupIntent,
 * ce qui interdit de se greffer sur sa passerelle carte et impose cette
 * construction séparée.
 *
 * @see docs/cahier-des-charges.md §3.6 et §6.1
 */
final class IntentFactory {

	/**
	 * Seul moyen de paiement accepté par cette passerelle.
	 *
	 * Laisser Stripe décider proposerait la carte, que le formulaire et le
	 * script de confirmation du plugin ne savent pas traiter.
	 */
	public const PAYMENT_METHOD_TYPE = 'sepa_debit';

	/**
	 * Faut-il encaisser immédiatement, ou seulement recueillir un mandat ?
	 *
	 * @param array $context Contexte d'inscription.
	 * @return bool
	 */
	public static function needs_payment_intent( array $context ): bool {
		return self::amount( $context ) > 0;
	}

	/**
	 * Arguments d'un PaymentIntent.
	 *
	 * @param array $context Contexte d'inscription.
	 * @return array
	 */
	public static function payment_intent( array $context ): array {
		$args = array_merge(
			self::common( $context ),
			array(
				'amount'   => self::amount( $context ),
				'currency' => self::currency( $context ),
			)
		);

		/*
		 * Une adhésion à vie ne doit pas laisser derrière elle un mandat
		 * réutilisable : le débiteur n'a autorisé qu'un seul prélèvement.
		 */
		if ( ! empty( $context['recurring'] ) ) {
			$args['setup_future_usage'] = 'off_session';
		}

		return self::with_mandate_options( $args, $context );
	}

	/**
	 * Arguments d'un SetupIntent.
	 *
	 * Utilisé lorsqu'il n'y a rien à encaisser aujourd'hui — adhésion gratuite,
	 * remise de 100 %, ou changement de moyen de paiement — mais qu'un mandat
	 * doit être recueilli pour les échéances suivantes.
	 *
	 * @param array $context Contexte d'inscription.
	 * @return array
	 */
	public static function setup_intent( array $context ): array {
		return array_merge(
			self::common( $context ),
			array( 'usage' => 'off_session' )
		);
	}

	// -- Fragments communs --------------------------------------------------------

	/**
	 * Arguments communs aux deux types d'intention.
	 *
	 * @param array $context Contexte d'inscription.
	 * @return array
	 */
	private static function common( array $context ): array {
		$args = array(
			'customer'             => (string) ( $context['customer_id'] ?? '' ),
			'description'          => (string) ( $context['description'] ?? '' ),
			'payment_method_types' => array( self::PAYMENT_METHOD_TYPE ),
			'metadata'             => (array) ( $context['metadata'] ?? array() ),
		);

		/*
		 * La confirmation a lieu dans le navigateur : c'est elle qui recueille
		 * l'acceptation du mandat par le débiteur, laquelle vaut signature
		 * électronique. Confirmer côté serveur priverait le mandat de sa preuve
		 * de consentement.
		 */

		$payment_method = (string) ( $context['payment_method'] ?? '' );

		if ( '' !== $payment_method ) {
			$args['payment_method'] = $payment_method;
		}

		return $args;
	}

	/**
	 * Ajoute le préfixe de référence de mandat, s'il est configuré.
	 *
	 * Ce préfixe apparaît sur le relevé bancaire du débiteur : il lui permet de
	 * reconnaître le prélèvement, ce qui réduit les contestations.
	 *
	 * @param array $args    Arguments en construction.
	 * @param array $context Contexte d'inscription.
	 * @return array
	 */
	private static function with_mandate_options( array $args, array $context ): array {
		$descriptor = trim( (string) ( $context['statement_descriptor'] ?? '' ) );

		if ( '' === $descriptor ) {
			return $args;
		}

		$args['payment_method_options'] = array(
			self::PAYMENT_METHOD_TYPE => array(
				'mandate_options' => array( 'reference_prefix' => $descriptor ),
			),
		);

		return $args;
	}

	/**
	 * Montant à encaisser, en plus petite unité monétaire.
	 *
	 * @param array $context Contexte d'inscription.
	 * @return int
	 */
	private static function amount( array $context ): int {
		return max( 0, (int) ( $context['amount'] ?? 0 ) );
	}

	/**
	 * Devise, normalisée.
	 *
	 * @param array $context Contexte d'inscription.
	 * @return string
	 */
	private static function currency( array $context ): string {
		return strtolower( (string) ( $context['currency'] ?? 'eur' ) );
	}
}
