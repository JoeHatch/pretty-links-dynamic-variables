<?php
/**
 * Plain-PHP test runner: `php tests/run.php`. Exits non-zero on any failure.
 *
 * Covers the production-hardened paths: click-ID entropy, token encryption +
 * rotation, mapping resolution (incl. numeric-only), and the dual-contract
 * redirect handler (array vs string), record-once memoization, idempotent
 * injection, capture-context recording, and sent_value reconciliation.
 *
 * @package PrettyLinksDV
 */

require __DIR__ . '/bootstrap.php';

use PrettyLinksDV\Click_Id;
use PrettyLinksDV\Crypto;
use PrettyLinksDV\Mappings;
use PrettyLinksDV\Settings;
use PrettyLinksDV\DB;
use PrettyLinksDV\Recorder;

$tests  = [];
$passed = 0;
$failed = 0;

function test( string $name, callable $fn ): void {
	global $tests;
	$tests[ $name ] = $fn;
}
function ok( $cond, string $msg ): void {
	if ( ! $cond ) {
		throw new Exception( $msg );
	}
}
function eq( $expected, $actual, string $msg ): void {
	if ( $expected !== $actual ) {
		throw new Exception( $msg . ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

/** Reset shared state between cases. */
function reset_world(): void {
	$GLOBALS['pldv_test_options'] = [];
	$GLOBALS['pldv_test_meta']    = [];
	$GLOBALS['wpdb']->reset();
	$GLOBALS['wpdb']->links = [];
	$_GET                   = [];
	$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
}

/** Find a tracked (non-numeric) and a numeric-only platform slug from real data. */
function sample_slugs(): array {
	$m       = new Mappings();
	$tracked = null;
	$numeric = null;
	foreach ( $m->all() as $slug => $p ) {
		$type = $p['value_constraint']['type'] ?? '';
		if ( ! $tracked && ! empty( $p['token_param'] ) && ! in_array( $type, [ 'numeric_only', 'needs_config' ], true ) ) {
			$tracked = [ $slug, $p['token_param'] ];
		}
		if ( ! $numeric && 'numeric_only' === $type ) {
			$numeric = [ $slug, $p['token_param'] ];
		}
	}
	return [ $tracked, $numeric ];
}

function new_recorder(): Recorder {
	return new Recorder( new Settings(), new Mappings(), new DB() );
}

/* ------------------------------------------------------------------ */
/* Click_Id                                                            */
/* ------------------------------------------------------------------ */

test( 'click_id: 32 lowercase hex chars', function () {
	$id = Click_Id::generate();
	ok( (bool) preg_match( '/^[0-9a-f]{32}$/', $id ), "bad click id format: {$id}" );
} );

test( 'click_id: high uniqueness (v1 entropy bug regression)', function () {
	$seen = [];
	for ( $i = 0; $i < 5000; $i++ ) {
		$seen[ Click_Id::generate() ] = true;
	}
	eq( 5000, count( $seen ), 'click ids collided' );
} );

test( 'click_id: numeric is 18 digits', function () {
	$n = Click_Id::generate_numeric();
	ok( (bool) preg_match( '/^\d{18}$/', $n ), "bad numeric id: {$n}" );
	ok( ctype_digit( $n ), 'numeric id not all digits' );
} );

/* ------------------------------------------------------------------ */
/* Crypto                                                              */
/* ------------------------------------------------------------------ */

test( 'crypto: encrypt round-trips back to click id', function () {
	reset_world();
	Crypto::ensure_key();
	$click = Click_Id::generate();
	$built = Crypto::build_token( $click, true );
	ok( $built['encrypted'], 'token should be encrypted when libsodium present' );
	ok( $built['value'] !== $click, 'encrypted token should not equal plaintext click id' );
	eq( $click, Crypto::open_token( $built['value'] ), 'round-trip failed' );
} );

test( 'crypto: token is url-safe (base64url, no +/=)', function () {
	reset_world();
	Crypto::ensure_key();
	$built = Crypto::build_token( Click_Id::generate(), true );
	ok( ! preg_match( '/[+\/=]/', $built['value'] ), 'token not url-safe: ' . $built['value'] );
} );

test( 'crypto: encrypt=false yields plaintext', function () {
	reset_world();
	Crypto::ensure_key();
	$click = Click_Id::generate();
	$built = Crypto::build_token( $click, false );
	ok( ! $built['encrypted'], 'should not be encrypted' );
	eq( $click, $built['value'], 'plaintext value should equal click id' );
} );

test( 'crypto: old key still decrypts after rotation', function () {
	reset_world();
	// Issue a token under key A.
	$keyA = base64_encode( sodium_crypto_secretbox_keygen() );
	update_option( Crypto::KEY_OPTION, $keyA );
	$click = Click_Id::generate();
	$token = Crypto::build_token( $click, true )['value'];
	// Rotate: new current key B, A demoted to old.
	$keyB = base64_encode( sodium_crypto_secretbox_keygen() );
	update_option( Crypto::KEY_OPTION, $keyB );
	update_option( Crypto::KEY_OPTION_OLD, $keyA );
	eq( $click, Crypto::open_token( $token ), 'token issued before rotation should still decrypt' );
} );

test( 'crypto: operational reflects key presence', function () {
	reset_world();
	ok( ! Crypto::operational(), 'should not be operational with no key' );
	Crypto::ensure_key();
	ok( Crypto::operational(), 'should be operational once key exists' );
} );

/* ------------------------------------------------------------------ */
/* Mappings::resolve_injection                                         */
/* ------------------------------------------------------------------ */

test( 'mappings: tracked platform injects token param', function () {
	reset_world();
	list( $tracked ) = sample_slugs();
	ok( $tracked, 'no tracked sample platform found' );
	$m   = new Mappings();
	$res = $m->resolve_injection( $tracked[0], 'TOKENVALUE', '123' );
	eq( 'tracked', $res['status'], 'should be tracked' );
	eq( $tracked[1], $res['param'], 'param mismatch' );
	eq( 'TOKENVALUE', $res['value'], 'value should be the opaque token' );
	ok( strpos( $res['query'], rawurlencode( $tracked[1] ) . '=' ) === 0, 'query should start with param=' );
} );

test( 'mappings: unknown slug is no_mapping', function () {
	reset_world();
	$m   = new Mappings();
	$res = $m->resolve_injection( 'definitely-not-a-real-slug', 'TOK', '123' );
	eq( 'no_mapping', $res['status'], 'unknown slug should be no_mapping' );
	eq( null, $res['value'], 'no value for no_mapping' );
} );

test( 'mappings: numeric-only uses numeric token, not the opaque one', function () {
	reset_world();
	list( , $numeric ) = sample_slugs();
	ok( $numeric, 'no numeric-only sample platform found' );
	$m   = new Mappings();
	$res = $m->resolve_injection( $numeric[0], 'NON_NUMERIC_TOKEN', '987654321012345678' );
	eq( 'tracked', $res['status'], 'numeric-only with numeric token should track' );
	eq( '987654321012345678', $res['value'], 'should send the numeric value' );
	ok( ctype_digit( $res['value'] ), 'numeric value must be all digits' );
} );

test( 'mappings: numeric-only without numeric token is unsupported_value', function () {
	reset_world();
	list( , $numeric ) = sample_slugs();
	ok( $numeric, 'no numeric-only sample platform found' );
	$m   = new Mappings();
	$res = $m->resolve_injection( $numeric[0], 'NON_NUMERIC_TOKEN', 'not-numeric' );
	eq( 'unsupported_value', $res['status'], 'non-numeric value should be unsupported' );
} );

/* ------------------------------------------------------------------ */
/* Recorder — the #1 dual-contract fix                                 */
/* ------------------------------------------------------------------ */

test( 'recorder: array contract injects token and records once', function () {
	reset_world();
	list( $tracked ) = sample_slugs();
	$GLOBALS['wpdb']->links[10] = (object) [ 'id' => 10, 'slug' => 'go-acme', 'link_cpt_id' => 0 ];
	$GLOBALS['pldv_test_meta'][10]['_pldv_software'] = $tracked[0];

	$rec = new_recorder();
	$out = $rec->handle( [ 'url' => 'https://t.example/visit?x=1', 'link_id' => 10 ] );

	ok( is_array( $out ), 'array contract should return an array' );
	ok( strpos( $out['url'], $tracked[1] . '=' ) !== false, 'token param not injected: ' . $out['url'] );
	eq( 1, count( $GLOBALS['wpdb']->inserts ), 'should record exactly one click' );
	$row = $GLOBALS['wpdb']->inserts[0];
	eq( 'tracked', $row['mapping_status'], 'status should be tracked' );
	eq( $tracked[0], $row['software'], 'software slug mismatch' );
	eq( 'go-acme', $row['link_slug'], 'link slug mismatch' );
	ok( ! empty( $row['sent_value'] ), 'sent_value must be stored for reconciliation' );
} );

test( 'recorder: string contract (legacy prli_redirect_url) works', function () {
	reset_world();
	list( $tracked ) = sample_slugs();
	$link = (object) [ 'id' => 22, 'slug' => 'go-legacy', 'link_cpt_id' => 0 ];
	$GLOBALS['wpdb']->links[22]                      = $link;
	$GLOBALS['pldv_test_meta'][22]['_pldv_software'] = $tracked[0];

	$rec = new_recorder();
	$out = $rec->handle( 'https://t.example/legacy', $link );

	ok( is_string( $out ), 'string contract should return a string' );
	ok( strpos( $out, $tracked[1] . '=' ) !== false, 'token param not injected (string path): ' . $out );
	eq( 1, count( $GLOBALS['wpdb']->inserts ), 'should record exactly one click (string path)' );
} );

test( 'recorder: re-firing the same URL records only once (memoization)', function () {
	reset_world();
	list( $tracked ) = sample_slugs();
	$GLOBALS['wpdb']->links[10] = (object) [ 'id' => 10, 'slug' => 'go-acme', 'link_cpt_id' => 0 ];
	$GLOBALS['pldv_test_meta'][10]['_pldv_software'] = $tracked[0];

	$rec   = new_recorder();
	$input = [ 'url' => 'https://t.example/visit', 'link_id' => 10 ];
	$first = $rec->handle( $input );
	$again = $rec->handle( $input );

	eq( 1, count( $GLOBALS['wpdb']->inserts ), 'memo must prevent a second insert' );
	eq( $first['url'], $again['url'], 'memo must return a stable URL' );
} );

test( 'recorder: injection is idempotent when param already present', function () {
	reset_world();
	list( $tracked ) = sample_slugs();
	$GLOBALS['wpdb']->links[10] = (object) [ 'id' => 10, 'slug' => 'go-acme', 'link_cpt_id' => 0 ];
	$GLOBALS['pldv_test_meta'][10]['_pldv_software'] = $tracked[0];

	$rec = new_recorder();
	$url = 'https://t.example/visit?' . $tracked[1] . '=ALREADYHERE';
	$out = $rec->handle( [ 'url' => $url, 'link_id' => 10 ] );

	$count = substr_count( $out['url'], $tracked[1] . '=' );
	eq( 1, $count, 'param must not be injected twice: ' . $out['url'] );
} );

test( 'recorder: no-software link still records (lossless), no injection', function () {
	reset_world();
	$GLOBALS['wpdb']->links[5] = (object) [ 'id' => 5, 'slug' => 'go-plain', 'link_cpt_id' => 0 ];
	// No _pldv_software meta.
	$rec = new_recorder();
	$out = $rec->handle( [ 'url' => 'https://t.example/plain', 'link_id' => 5 ] );

	eq( 1, count( $GLOBALS['wpdb']->inserts ), 'click should still be recorded' );
	eq( 'no_software', $GLOBALS['wpdb']->inserts[0]['mapping_status'], 'status should be no_software' );
	eq( 'https://t.example/plain', $out['url'], 'URL should be unchanged when no software' );
} );

test( 'recorder: captures position/page/operator from request', function () {
	reset_world();
	$GLOBALS['wpdb']->links[5]  = (object) [ 'id' => 5, 'slug' => 'go-plain', 'link_cpt_id' => 0 ];
	$_GET['pldv_pos']           = '3';
	$_GET['pldv_pg']            = 'best-casinos';
	$_GET['pldv_op']            = 'Thrill';

	$rec = new_recorder();
	$rec->handle( [ 'url' => 'https://t.example/plain', 'link_id' => 5 ] );

	$row = $GLOBALS['wpdb']->inserts[0];
	eq( 3, $row['clicked_position'], 'position not captured' );
	eq( 'best-casinos', $row['page'], 'page not captured' );
	eq( 'Thrill', $row['operator'], 'operator not captured' );
} );

test( 'recorder: strips pldv_ capture params from outbound URL', function () {
	reset_world();
	$GLOBALS['wpdb']->links[5] = (object) [ 'id' => 5, 'slug' => 'go-plain', 'link_cpt_id' => 0 ];
	$rec = new_recorder();
	$ref = new ReflectionMethod( Recorder::class, 'strip_capture_params' );
	$ref->setAccessible( true );
	$out = $ref->invoke( $rec, 'https://t.example/x?pldv_pg=foo&keep=1&pldv_pos=2' );
	ok( strpos( $out, 'pldv_' ) === false, 'pldv_ params should be stripped: ' . $out );
	ok( strpos( $out, 'keep=1' ) !== false, 'non-pldv params should be preserved: ' . $out );
} );

test( 'recorder: tracking failure never breaks the redirect', function () {
	reset_world();
	// link_id points at a row that get_row cannot resolve -> still must return data.
	$rec = new_recorder();
	$out = $rec->handle( [ 'url' => 'https://t.example/safe', 'link_id' => 999 ] );
	ok( is_array( $out ) && ! empty( $out['url'] ), 'handler must always return usable data' );
} );

/* ------------------------------------------------------------------ */
/* Run                                                                 */
/* ------------------------------------------------------------------ */

echo "Running " . count( $tests ) . " tests...\n\n";
foreach ( $tests as $name => $fn ) {
	try {
		$fn();
		echo "  \033[32m✓\033[0m {$name}\n";
		$passed++;
	} catch ( Throwable $e ) {
		echo "  \033[31m✗\033[0m {$name}\n      " . $e->getMessage() . "\n";
		$failed++;
	}
}

echo "\n" . ( $failed ? "\033[31m" : "\033[32m" ) . "{$passed} passed, {$failed} failed\033[0m\n";
exit( $failed ? 1 : 0 );
