<?php
/**
 * Désinstallation du plugin.
 *
 * Supprime les options et métadonnées créées par le plugin. Les données
 * présentes chez Stripe ne sont jamais supprimées : elles relèvent des
 * obligations comptables du site.
 *
 * Définir `RCP_SEPA_KEEP_DATA_ON_UNINSTALL` dans `wp-config.php` conserve tout.
 *
 * @package RCP_Stripe_Sepa
 * @see docs/cahier-des-charges.md §9.2 SEC-05
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'RCP_SEPA_KEEP_DATA_ON_UNINSTALL' ) && RCP_SEPA_KEEP_DATA_ON_UNINSTALL ) {
	return;
}

global $wpdb;

$rcp_sepa_options = array(
	'rcp_stripe_sepa_settings',
	'rcp_stripe_sepa_db_version',
	'rcp_stripe_sepa_webhook_secret_test',
	'rcp_stripe_sepa_webhook_secret_live',
);

foreach ( $rcp_sepa_options as $rcp_sepa_option ) {
	delete_option( $rcp_sepa_option );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'rcp_sepa_webhook_events`' );
