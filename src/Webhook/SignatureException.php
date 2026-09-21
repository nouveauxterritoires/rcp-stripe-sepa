<?php
/**
 * Échec de vérification de signature.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Webhook;

use RuntimeException;

/**
 * Levée lorsqu'une charge utile de webhook ne peut pas être authentifiée.
 *
 * Le message est destiné au journal du serveur, jamais à la réponse HTTP :
 * détailler la cause d'un rejet renseignerait un attaquant sur l'écart entre
 * ce qu'il envoie et ce qui est attendu.
 *
 * @see docs/cahier-des-charges.md §9.3 SEC-10
 */
final class SignatureException extends RuntimeException {
}
