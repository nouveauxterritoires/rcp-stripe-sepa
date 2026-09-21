<?php
/**
 * Transition d'état déduite d'un événement Stripe.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Membership;

/**
 * Résultat immuable de l'analyse d'un événement.
 *
 * Une transition décrit ce qu'il faut changer, jamais comment le changer : elle
 * ne touche ni WordPress ni RCP. Un statut à `null` signifie « ne rien
 * modifier », ce qui n'est pas la même chose qu'un événement ignoré.
 */
final class Transition {

	/**
	 * Nouveau statut d'adhésion, ou null si inchangé.
	 *
	 * @var string|null
	 */
	private $membership_status;

	/**
	 * Nouveau statut de paiement, ou null si inchangé.
	 *
	 * @var string|null
	 */
	private $payment_status;

	/**
	 * Raison lisible, destinée au journal et aux notes d'adhésion.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * L'événement est-il ignoré ?
	 *
	 * @var bool
	 */
	private $skipped;

	/**
	 * Construit une transition.
	 *
	 * @param string|null $membership_status Statut d'adhésion visé.
	 * @param string|null $payment_status    Statut de paiement visé.
	 * @param string      $reason            Raison lisible.
	 * @param bool        $skipped           L'événement est-il ignoré.
	 */
	private function __construct( ?string $membership_status, ?string $payment_status, string $reason, bool $skipped ) {
		$this->membership_status = $membership_status;
		$this->payment_status    = $payment_status;
		$this->reason            = $reason;
		$this->skipped           = $skipped;
	}

	/**
	 * Transition à appliquer.
	 *
	 * @param string|null $membership_status Statut d'adhésion visé, ou null.
	 * @param string|null $payment_status    Statut de paiement visé, ou null.
	 * @param string      $reason            Raison lisible.
	 * @return self
	 */
	public static function to( ?string $membership_status, ?string $payment_status, string $reason ): self {
		return new self( $membership_status, $payment_status, $reason, false );
	}

	/**
	 * Événement reçu mais volontairement sans effet.
	 *
	 * @param string $reason Raison lisible.
	 * @return self
	 */
	public static function skip( string $reason ): self {
		return new self( null, null, $reason, true );
	}

	/**
	 * L'événement est-il ignoré ?
	 *
	 * @return bool
	 */
	public function is_skipped(): bool {
		return $this->skipped;
	}

	/**
	 * Nouveau statut d'adhésion, ou null si inchangé.
	 *
	 * @return string|null
	 */
	public function membership_status(): ?string {
		return $this->membership_status;
	}

	/**
	 * Nouveau statut de paiement, ou null si inchangé.
	 *
	 * @return string|null
	 */
	public function payment_status(): ?string {
		return $this->payment_status;
	}

	/**
	 * Raison lisible de la transition.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}
}
