<?php
/**
 * Standalone test bootstrap.
 *
 * The plugin has no Composer/PHPUnit dependency, so these tests run on plain PHP
 * with a small set of WordPress function stubs plus an in-memory $wpdb fake. This
 * keeps the suite runnable anywhere PHP 7.4+ is available (`php tests/run.php`)
 * and exercises the real plugin classes — no mocks of our own code.
 *
 * @package PrettyLinksDV
 */

error_reporting( E_ALL & ~E_DEPRECATED );

// WordPress runs PHP in UTC and offsets via current_time(); mirror that so the
// retention-cron timezone math is exercised honestly (not masked by the stub).
date_default_timezone_set( 'UTC' );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'PLDV_DIR', dirname( __DIR__ ) . '/' );
define( 'PLDV_URL', 'http://example.test/wp-content/plugins/pretty-links-dv/' );
define( 'PLDV_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['pldv_test_options'] = [];
$GLOBALS['pldv_test_meta']    = [];

/* ----- minimal WordPress function stubs ----- */

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['pldv_test_options'] )
		? $GLOBALS['pldv_test_options'][ $key ]
		: $default;
}
function add_option( $key, $value, $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $key, $GLOBALS['pldv_test_options'] ) ) {
		return false;
	}
	$GLOBALS['pldv_test_options'][ $key ] = $value;
	return true;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['pldv_test_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['pldv_test_options'][ $key ] );
	return true;
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['pldv_test_meta'][ $id ][ $key ] ?? '';
}
function apply_filters( $tag, $value, ...$args ) {
	return $value;
}
function sanitize_text_field( $s ) {
	$s = is_string( $s ) ? $s : '';
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $s ) ) );
}
function sanitize_key( $k ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
}
function wp_unslash( $v ) {
	return is_array( $v ) ? array_map( 'wp_unslash', $v ) : stripslashes( (string) $v );
}
function wp_parse_url( $url ) {
	return parse_url( $url );
}
function absint( $v ) {
	return abs( (int) $v );
}
function wp_salt( $scheme = '' ) {
	return 'test-salt-' . $scheme;
}
// Simulated site-local offset (seconds) so tests can model a non-UTC site and
// surface timezone bugs. 0 = UTC. Set $GLOBALS['pldv_test_gmt_offset'] in a test.
function current_time( $type ) {
	$base   = $GLOBALS['pldv_test_now_ts'] ?? time(); // freeze the clock in tests.
	$offset = $GLOBALS['pldv_test_gmt_offset'] ?? 0;
	$now    = $base + $offset;
	return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s', $now ) : $now;
}

/* ----- cron / hook stubs (no-ops; prune is invoked directly in tests) ----- */
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {}
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {}
function wp_next_scheduled( $hook ) {
	return false;
}
function wp_schedule_event( $ts, $recurrence, $hook ) {
	return true;
}
function wp_unschedule_event( $ts, $hook ) {
	return true;
}
function wp_clear_scheduled_hook( $hook ) {
	return true;
}

/**
 * In-memory $wpdb fake: records inserts, answers link lookups from a preset map.
 */
class PLDV_Fake_Wpdb {
	public $prefix    = 'wp_';
	public $insert_id = 0;
	public $inserts    = [];
	public $links      = []; // id => row object for prli_links lookups.
	public $last_query = '';

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[dsf]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? '';
				return is_numeric( $v ) ? (string) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$query
		);
	}
	public function get_row( $query ) {
		if ( preg_match( '/id\s*=\s*(\d+)/', $query, $m ) ) {
			return $this->links[ (int) $m[1] ] ?? null;
		}
		return null;
	}
	public function get_var( $query ) {
		return 0;
	}
	public function get_results( $query ) {
		return [];
	}
	public function get_col( $query ) {
		return [];
	}
	public function insert( $table, $row ) {
		$this->inserts[] = $row;
		$this->insert_id = count( $this->inserts );
		return 1;
	}
	public function query( $query ) {
		$this->last_query = $query;
		return 0;
	}
	public function reset() {
		$this->inserts   = [];
		$this->insert_id = 0;
	}
}

$GLOBALS['wpdb'] = new PLDV_Fake_Wpdb();

/* ----- load the classes under test ----- */

require_once PLDV_DIR . 'includes/class-pldv-settings.php';
require_once PLDV_DIR . 'includes/class-pldv-click-id.php';
require_once PLDV_DIR . 'includes/class-pldv-crypto.php';
require_once PLDV_DIR . 'includes/class-pldv-mappings.php';
require_once PLDV_DIR . 'includes/class-pldv-db.php';
require_once PLDV_DIR . 'includes/class-pldv-recorder.php';
require_once PLDV_DIR . 'includes/class-pldv-plugin.php';
