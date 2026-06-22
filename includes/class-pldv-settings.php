<?php
/**
 * Plugin settings.
 *
 * Backed by a single autoloaded option (pldv_settings). Provides defaults and
 * typed getters; the managed Settings UI (class-pldv-admin.php) reads and writes
 * through here so the runtime is fully configuration-driven.
 *
 * @package PrettyLinksDV
 */

namespace PrettyLinksDV;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION = 'pldv_settings';

	/** @var array */
	private $values;

	public function __construct() {
		$stored       = get_option( self::OPTION );
		$this->values = array_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
	}

	public static function defaults(): array {
		return [
			// Capture: 'all' Pretty Link clicks, or 'tracked' (software/CTA-flagged only).
			'capture_scope'   => 'all',
			// Encrypt the DV token sent to the network.
			'encrypt_token'   => true,
			// IP storage: 'off' | 'hash' | 'raw'.
			'ip_mode'         => 'hash',
			// Store the User-Agent string.
			'store_user_agent' => false,
			// Row retention in days (0 = keep forever). Enforced by the daily
			// pldv_prune_event cron (see Plugin::prune()).
			'retention_days'  => 0,
			// Request param prefix used by the capture layer.
			'param_prefix'    => 'pldv_',
			// Pretty Link path prefix the JS capture layer matches on (e.g. /go/).
			'link_prefix'     => '/go/',
		];
	}

	public function get( string $key ) {
		return $this->values[ $key ] ?? null;
	}

	public function all(): array {
		return $this->values;
	}

	public function capture_all(): bool {
		return 'all' === $this->get( 'capture_scope' );
	}

	public function encrypt_token(): bool {
		return (bool) $this->get( 'encrypt_token' );
	}

	public function ip_mode(): string {
		return (string) $this->get( 'ip_mode' );
	}

	public function param_prefix(): string {
		$prefix = (string) $this->get( 'param_prefix' );
		return $prefix !== '' ? $prefix : 'pldv_';
	}

	public function link_prefix(): string {
		$prefix = (string) $this->get( 'link_prefix' );
		return $prefix !== '' ? $prefix : '/go/';
	}
}
