<?php
/**
 * Lecture des versions de l'API Stripe.
 *
 * @package RCP_Stripe_Sepa
 */

declare( strict_types = 1 );

namespace RCP_Stripe_Sepa\Support;

/**
 * Ordonne les versions d'API Stripe et en déduit la forme des arguments.
 *
 * Stripe nomme ses versions `AAAA-MM-JJ`, éventuellement suivies d'un nom de
 * cycle (`2026-09-30.endive`). Seule la date les ordonne entre elles : le nom
 * ne désigne que le cycle de publication.
 *
 * Classe purement fonctionnelle — ni réseau, ni état — afin que la règle qui
 * décide de la forme d'une requête reste éprouvable sans appeler Stripe.
 *
 * @see docs/cahier-des-charges.md §3.3 règle R-API-4
 */
final class StripeApiVersion {

	/**
	 * Paramètre historique restreignant les moyens de paiement d'une intention.
	 */
	public const LEGACY_INTENT_PARAMETER = 'payment_method_types';

	/**
	 * Paramètre qui lui succède, introduit par le cycle dahlia.
	 *
	 * Il ne se comporte pas tout à fait comme son prédécesseur : Stripe en
	 * écarte silencieusement les moyens incompatibles au lieu de refuser la
	 * requête. Un prélèvement SEPA hors zone euro ne provoquerait donc plus
	 * d'erreur à la création — c'est le contrôle de devise du plugin (RG-02)
	 * qui l'intercepte, en amont.
	 */
	public const CURRENT_INTENT_PARAMETER = 'allowed_payment_method_types';

	/**
	 * Première version où `payment_method_types` n'est plus inscriptible.
	 *
	 * `2026-08-26.preview` retire le paramètre à la création, à la mise à jour
	 * et à la confirmation d'un PaymentIntent comme d'un SetupIntent ; la
	 * version `2026-09-30.endive` reprend ce retrait dans le canal stable. Le
	 * transmettre au-delà renvoie une erreur 400 de code
	 * `payment_method_types_no_longer_supported`.
	 *
	 * Le paramètre reste lisible sur les objets renvoyés : il y reflète
	 * désormais la liste calculée des moyens de paiement disponibles.
	 */
	public const INTENT_PAYMENT_METHOD_TYPES_REMOVED = '2026-08-26';

	/**
	 * Nom du paramètre restreignant les moyens de paiement d'une intention.
	 *
	 * @param string $version Version d'API visée.
	 * @return string
	 */
	public static function intent_payment_method_parameter( string $version ): string {
		return self::is_at_least( $version, self::INTENT_PAYMENT_METHOD_TYPES_REMOVED )
			? self::CURRENT_INTENT_PARAMETER
			: self::LEGACY_INTENT_PARAMETER;
	}

	/**
	 * La version visée atteint-elle le jalon donné ?
	 *
	 * Une version illisible est tenue pour antérieure : le plugin conserve
	 * alors la forme historique, celle de sa version par défaut.
	 *
	 * @param string $version  Version d'API visée.
	 * @param string $milestone Jalon, au format `AAAA-MM-JJ`.
	 * @return bool
	 */
	public static function is_at_least( string $version, string $milestone ): bool {
		$date = self::date( $version );

		return '' !== $date && $date >= $milestone;
	}

	/**
	 * Date d'une version d'API, nom de cycle retiré.
	 *
	 * @param string $version Version d'API, par exemple `2026-09-30.endive`.
	 * @return string Chaîne vide si la version n'est pas lisible.
	 */
	public static function date( string $version ): string {
		return preg_match( '/^(\d{4}-\d{2}-\d{2})/', trim( $version ), $matches ) ? $matches[1] : '';
	}
}
