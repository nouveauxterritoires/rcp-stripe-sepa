<?php
/**
 * Règles d'éligibilité à la migration vers le prélèvement SEPA.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Migration;

use RCP_Stripe_Sepa\Gateway\GatewayDefinition;
use RCP_Stripe_Sepa\Membership\StateMachine;

/**
 * Décide si une adhésion peut basculer sur un mandat SEPA.
 *
 * La classe est purement fonctionnelle : elle reçoit un instantané de
 * l'adhésion et renvoie une décision motivée. Le motif est un code, traduit
 * ailleurs — ce qui permet de couvrir les règles sans WordPress et de
 * réutiliser la même décision à l'affichage du formulaire et au traitement de
 * la requête.
 *
 * @see docs/cahier-des-charges.md §6.3
 */
final class Eligibility {

	public const REASON_ALLOWED             = 'allowed';
	public const REASON_NOT_ACTIVE          = 'not_active';
	public const REASON_UNSUPPORTED_GATEWAY = 'unsupported_gateway';
	public const REASON_NO_CUSTOMER         = 'no_customer';
	public const REASON_NOT_RECURRING       = 'not_recurring';
	public const REASON_CURRENCY            = 'currency';

	/**
	 * Gateways depuis lesquelles une migration a un sens.
	 *
	 * La passerelle SEPA figure dans la liste : un adhérent qui change de
	 * banque doit pouvoir fournir un nouvel IBAN sans résilier son adhésion.
	 *
	 * @var string[]
	 */
	private const MIGRATABLE_GATEWAYS = array( 'stripe', GatewayDefinition::ID );

	/**
	 * Motif de la décision.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * @param string $reason Motif de la décision.
	 */
	private function __construct( string $reason ) {
		$this->reason = $reason;
	}

	/**
	 * Évalue une adhésion.
	 *
	 * Les contrôles sont ordonnés du plus compréhensible au plus technique :
	 * un adhérent dont l'adhésion est résiliée doit lire cela, et non un motif
	 * portant sur la passerelle.
	 *
	 * @param array $membership Instantané de l'adhésion.
	 * @return self
	 */
	public static function assess( array $membership ): self {
		if ( StateMachine::MEMBERSHIP_ACTIVE !== ( $membership['status'] ?? '' ) ) {
			return new self( self::REASON_NOT_ACTIVE );
		}

		if ( ! in_array( (string) ( $membership['gateway'] ?? '' ), self::MIGRATABLE_GATEWAYS, true ) ) {
			return new self( self::REASON_UNSUPPORTED_GATEWAY );
		}

		if ( '' === (string) ( $membership['gateway_customer_id'] ?? '' ) ) {
			return new self( self::REASON_NO_CUSTOMER );
		}

		if ( 'EUR' !== strtoupper( (string) ( $membership['currency'] ?? '' ) ) ) {
			return new self( self::REASON_CURRENCY );
		}

		// Sans échéance future, changer de moyen de paiement serait sans effet.
		$has_future_payment = ! empty( $membership['auto_renew'] )
			|| '' !== (string) ( $membership['gateway_subscription_id'] ?? '' );

		if ( ! $has_future_payment ) {
			return new self( self::REASON_NOT_RECURRING );
		}

		return new self( self::REASON_ALLOWED );
	}

	/**
	 * La migration est-elle autorisée ?
	 *
	 * @return bool
	 */
	public function is_allowed(): bool {
		return self::REASON_ALLOWED === $this->reason;
	}

	/**
	 * Motif de la décision.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Motifs de refus possibles.
	 *
	 * @return string[]
	 */
	public static function refusal_reasons(): array {
		return array(
			self::REASON_NOT_ACTIVE,
			self::REASON_UNSUPPORTED_GATEWAY,
			self::REASON_NO_CUSTOMER,
			self::REASON_NOT_RECURRING,
			self::REASON_CURRENCY,
		);
	}
}
