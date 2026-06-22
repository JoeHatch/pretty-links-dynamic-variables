<?php
/**
 * Plugin Name: Pretty Links Dynamic Variables
 * Description: Records every Pretty Link click with full context (click ID, page, list position, operator and more) and injects an encrypted dynamic-variable token into the outbound affiliate URL based on the link's selected software.
 * Version: 2.0.0
 * Requires PHP: 7.4
 * Author: StatsDrone
 * Author URI: https://statsdrone.com
 * Text Domain: pretty-links-dv
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * NOTE: This is the v2 rewrite. The legacy single-file plugin (pldv-main.php)
 * is superseded and must NOT be active at the same time as this file.
 */

namespace PrettyLinksDV;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access not permitted.' );
}

if ( defined( 'PLDV_VERSION' ) ) {
	// Legacy pldv-main.php is loaded. Refuse to double-register and warn.
	add_action( 'admin_notices', static function () {
		echo '<div class="notice notice-error"><p><strong>Pretty Links Dynamic Variables:</strong> the legacy single-file version is also active. Deactivate <code>pldv-main.php</code> and keep only the v2 plugin active.</p></div>';
	} );
	return;
}

define( 'PLDV_VERSION', '2.0.0' );
define( 'PLDV_FILE', __FILE__ );
define( 'PLDV_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLDV_URL', plugin_dir_url( __FILE__ ) );
// Bumped to 2: adds the sent_value column (network-postback reconciliation).
define( 'PLDV_DB_VERSION', '2' );

/**
 * Minimal PSR-4-ish autoloader for the PrettyLinksDV\ namespace.
 * PrettyLinksDV\Foo_Bar -> includes/class-pldv-foo-bar.php
 */
spl_autoload_register( static function ( $class ) {
	if ( strpos( $class, __NAMESPACE__ . '\\' ) !== 0 ) {
		return;
	}

	$relative = substr( $class, strlen( __NAMESPACE__ . '\\' ) );
	$relative = str_replace( '_', '-', strtolower( $relative ) );
	$file     = PLDV_DIR . 'includes/class-pldv-' . $relative . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
} );

/**
 * Activation: create/upgrade the clicks table and ensure an encryption key exists.
 */
register_activation_hook( __FILE__, static function () {
	DB::install();
	Crypto::ensure_key();
	add_option( 'pldv_db_version', PLDV_DB_VERSION );
} );

/**
 * Deactivation: clear the retention-pruning cron so it does not fire while the
 * plugin is inactive. Data and options are left intact (see uninstall.php).
 */
register_deactivation_hook( __FILE__, static function () {
	$timestamp = wp_next_scheduled( Plugin::PRUNE_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, Plugin::PRUNE_HOOK );
	}
	wp_clear_scheduled_hook( Plugin::PRUNE_HOOK );
} );

/**
 * Boot the plugin once all plugins are loaded.
 *
 * Runs late (priority 20) so Pretty Links (priority 10) has registered first;
 * the dependency check then reflects reality.
 */
add_action( 'plugins_loaded', static function () {
	Plugin::instance()->init();
}, 20 );
