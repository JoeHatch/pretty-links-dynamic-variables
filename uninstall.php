<?php
/**
 * Uninstall cleanup.
 *
 * Runs only on plugin deletion. Removes options and (opt-in) the clicks table.
 * The clicks data is the operator's tracking history, so the table is dropped
 * ONLY when the PLDV_DROP_DATA_ON_UNINSTALL constant is true — otherwise it is
 * preserved so an accidental delete does not destroy reporting history.
 *
 * @package PrettyLinksDV
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Always remove plugin options.
$options = [ 'pldv_settings', 'pldv_mappings', 'pldv_db_version', 'pldv_secret_key', 'pldv_secret_key_old', 'pldv_ip_secret' ];
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Drop click history only if explicitly opted in.
if ( defined( 'PLDV_DROP_DATA_ON_UNINSTALL' ) && PLDV_DROP_DATA_ON_UNINSTALL ) {
	$table = $wpdb->prefix . 'pldv_clicks';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

// Per-link software selections (_pldv_software post meta) are intentionally left
// in place; they are cheap and useful if the plugin is reinstalled.
