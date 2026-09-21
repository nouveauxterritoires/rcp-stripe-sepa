<?php
/**
 * Outcome du traitement d'un événement.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Webhook;

/**
 * Résultat immuable du traitement d'un événement de webhook.
 */
final class EventResult {

	/**
	 * Statut final, au sens du journal des événements.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Note lisible.
	 *
	 * @var string
	 */
	private $note;

	/**
	 * Construit un résultat.
	 *
	 * @param string $status Statut final.
	 * @param string $note   Note lisible.
	 */
	private function __construct( string $status, string $note ) {
		$this->status = $status;
		$this->note   = $note;
	}

	/**
	 * Événement traité.
	 *
	 * @param string $note Note lisible.
	 * @return self
	 */
	public static function processed( string $note ): self {
		return new self( EventStore::STATUS_PROCESSED, $note );
	}

	/**
	 * Événement volontairement sans effet.
	 *
	 * @param string $note Note lisible.
	 * @return self
	 */
	public static function skipped( string $note ): self {
		return new self( EventStore::STATUS_SKIPPED, $note );
	}

	/**
	 * Statut final.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Note lisible.
	 *
	 * @return string
	 */
	public function note(): string {
		return $this->note;
	}

	/**
	 * L'événement a-t-il été traité ?
	 *
	 * @return bool
	 */
	public function is_processed(): bool {
		return EventStore::STATUS_PROCESSED === $this->status;
	}
}
