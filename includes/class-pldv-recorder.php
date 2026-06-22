<?php
/**
 * Redirect recorder.
 *
 * Hooks Pretty Links' prli_redirect_url filter and applies the core principle:
 * ALWAYS record the click, CONDITIONALLY inject the DV token. A missing/unknown
 * mapping never drops a click — it is recorded with a mapping_status so coverage
 * gaps are visible, not lost. We do not intercept before Pretty Links (the v1
 * approach bypassed its native click tracking and hammered the DB on every page);
 * all work happens inside the redirect filter, so normal page loads cost nothing.
 *
 * @package PrettyLinksDV
 */

namespace PrettyLinksDV;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recorder {

	/** @var Settings */
	private $settings;

	/** @var Mappings */
	private $mappings;

	/** @var DB */
	private $db;

	public function __construct( Settings $settings, Mappings $mappings, DB $db ) {
		$this->settings = $settings;
		$this->mappings = $mappings;
		$this->db       = $db;
	}

	/**
	 * Per-request memo of computed outbound URLs, keyed by the original URL.
	 * Pretty Links may apply the redirect filter more than once per request (and
	 * across more than one filter name); memoizing guarantees we record the click
	 * exactly once and return a stable, identically-injected URL every time.
	 *
	 * @var array<string,string>
	 */
	private $memo = [];

	public function register(): void {
		// Pretty Links' outbound-URL filter. The filter NAME and SIGNATURE have
		// differed across Pretty Links versions: older builds expose
		// `prli_redirect_url` with ( string $url, object $link ); other builds pass
		// a single array( 'url', 'link_id', ... ). Rather than bet on one shape we
		// hook both names and normalize whatever arrives (string or array) in
		// handle(). Whichever Pretty Links actually fires, we record and inject;
		// the memo guards against double-recording if both fire. Hooking late
		// (priority 20) keeps Pretty Links' own click recording intact.
		add_filter( 'prli_target_url', [ $this, 'handle' ], 20, 2 );
		add_filter( 'prli_redirect_url', [ $this, 'handle' ], 20, 2 );
	}

	/**
	 * Normalize the two known Pretty Links filter contracts, inject the DV token,
	 * and record the click. Accepts either a single array
	 * ( 'url' => string, 'link_id' => int, ... ) or ( string $url, object $link ).
	 * Returns the same shape it received. Never throws.
	 *
	 * @param array|string $data     Array payload, or the target URL string.
	 * @param mixed        $link_arg Link object/id when called as ( $url, $link ).
	 * @return array|string Same shape as $data, with a token-augmented URL.
	 */
	public function handle( $data, $link_arg = null ) {
		try {
			$is_array = is_array( $data );
			$url      = $is_array ? (string) ( $data['url'] ?? '' ) : (string) $data;
			if ( '' === $url ) {
				return $data;
			}

			// Memoized: return the already-computed URL without re-recording.
			if ( array_key_exists( $url, $this->memo ) ) {
				$final = $this->memo[ $url ];
			} else {
				$final            = $this->process( $url, $this->resolve_link_id( $data, $link_arg, $is_array ) );
				$this->memo[ $url ] = $final;
			}

			if ( $is_array ) {
				$data['url'] = $final;
				return $data;
			}
			return $final;
		} catch ( \Throwable $e ) {
			// A tracking failure must never break the redirect.
			return $data;
		}
	}

	/**
	 * Resolve the Pretty Links link id from whichever contract was used.
	 */
	private function resolve_link_id( $data, $link_arg, bool $is_array ): int {
		if ( $is_array && isset( $data['link_id'] ) ) {
			return (int) $data['link_id'];
		}
		if ( is_object( $link_arg ) && isset( $link_arg->id ) ) {
			return (int) $link_arg->id;
		}
		if ( is_numeric( $link_arg ) ) {
			return (int) $link_arg;
		}
		return 0;
	}

	/**
	 * Strip capture params, inject the DV token when mapped, record the click once,
	 * and return the final outbound URL. Pure given the request context.
	 */
	private function process( string $url, int $link_id ): string {
		$link     = $link_id ? $this->link_by_id( $link_id ) : null;
		$software = $link ? $this->link_software( $link ) : '';
		$context  = $this->read_context();
		$final_url = $this->strip_capture_params( $url );

		// Capture scope: 'all' records every click; 'tracked' needs software or a CTA context.
		$is_tracked_context = ( '' !== $software ) || $context['has_cta'];
		if ( ! $this->settings->capture_all() && ! $is_tracked_context ) {
			return $final_url;
		}

		$click_id = Click_Id::generate();
		$built    = Crypto::build_token( $click_id, $this->settings->encrypt_token() );

		// Decide injection. sent_value is the exact value placed on the wire, so a
		// network postback can always be reconciled back to this row (critical for
		// numeric-only platforms, where the wire value differs from the token).
		$status     = '';
		$param_sent = null;
		$sent_value = null;

		if ( '' === $software ) {
			$status = 'no_software';
		} else {
			$inj    = $this->mappings->resolve_injection( $software, $built['value'], Click_Id::generate_numeric() );
			$status = $inj['status'];

			if ( 'tracked' === $status && ! empty( $inj['query'] ) ) {
				$param_sent = $inj['param'];
				$sent_value = $inj['value'];
				// Idempotent: never inject the param twice if the filter re-fires.
				if ( ! $this->url_has_param( $final_url, (string) $inj['param'] ) ) {
					$separator  = ( strpos( $final_url, '?' ) === false ) ? '?' : '&';
					$final_url .= $separator . $inj['query'];
				}
			}
		}

		// Always record.
		$this->db->insert( [
			'click_id'         => $click_id,
			'token'            => $built['value'],
			'sent_value'       => $sent_value,
			'link_id'          => $link_id ?: null,
			'link_slug'        => ( $link && isset( $link->slug ) ) ? (string) $link->slug : null,
			'software'         => '' !== $software ? $software : null,
			'mapping_status'   => $status,
			'param_sent'       => $param_sent,
			'page'             => $context['page'],
			'clicked_position' => $context['position'],
			'original_order'   => $context['original_order'],
			'placement'        => $context['placement'],
			'context'          => $context['context'],
			'operator'         => $context['operator'],
			'target_url'       => $final_url,
			'ip_hash'          => $this->ip_value(),
			'user_agent'       => $this->user_agent_value(),
			'is_test'          => 0,
		] );

		return $final_url;
	}

	/**
	 * Whether $url already carries a given query parameter (avoids double injection).
	 */
	private function url_has_param( string $url, string $param ): bool {
		if ( '' === $param ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['query'] ) ) {
			return false;
		}
		parse_str( $parts['query'], $query );
		return array_key_exists( $param, $query );
	}

	/**
	 * Fetch a Pretty Links link row by id (for slug + link_cpt_id resolution).
	 *
	 * @return object|null
	 */
	private function link_by_id( int $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'prli_links';
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT id, slug, link_cpt_id FROM {$table} WHERE id = %d",
			$id
		) );
	}

	/**
	 * Dry-run the full pipeline for a given target URL + software + simulated
	 * context, WITHOUT inserting a row or redirecting. Powers the Test tool.
	 *
	 * @param string $target_url Destination URL to simulate against.
	 * @param string $software   Software slug (may be empty).
	 * @param array  $context    Simulated capture context (page, position, ...).
	 * @return array Full computed result incl. final_url, token, decrypted payload, status.
	 */
	public function simulate( string $target_url, string $software, array $context = [] ): array {
		$context  = array_merge(
			[
				'page'           => null,
				'position'       => null,
				'original_order' => null,
				'placement'      => null,
				'context'        => null,
				'operator'       => null,
			],
			$context
		);

		$click_id = Click_Id::generate();
		$built    = Crypto::build_token( $click_id, $this->settings->encrypt_token() );

		$status     = '';
		$param_sent = null;
		$sent_value = null;
		$final_url  = $this->strip_capture_params( $target_url );

		if ( '' === $software ) {
			$status = 'no_software';
		} else {
			$inj    = $this->mappings->resolve_injection( $software, $built['value'], Click_Id::generate_numeric() );
			$status = $inj['status'];
			if ( 'tracked' === $status && ! empty( $inj['query'] ) ) {
				$param_sent = $inj['param'];
				$sent_value = $inj['value'];
				$separator  = ( strpos( $final_url, '?' ) === false ) ? '?' : '&';
				$final_url .= $separator . $inj['query'];
			}
		}

		return [
			'click_id'       => $click_id,
			'token'          => $built['value'],
			'encrypted'      => $built['encrypted'],
			'decrypted'      => $built['encrypted'] ? Crypto::open_token( $built['value'] ) : $built['value'],
			'token_length'   => strlen( $built['value'] ),
			'software'       => $software,
			'mapping_status' => $status,
			'param_sent'     => $param_sent,
			'sent_value'     => $sent_value,
			'final_url'      => $final_url,
			'context'        => $context,
		];
	}

	/**
	 * Resolve the software slug configured on a link (link id first, then the
	 * underlying WP post via link_cpt_id).
	 */
	private function link_software( $link ): string {
		$software = get_post_meta( $link->id, '_pldv_software', true );

		if ( ! $software && ! empty( $link->link_cpt_id ) ) {
			$software = get_post_meta( $link->link_cpt_id, '_pldv_software', true );
		}

		return is_string( $software ) ? trim( $software ) : '';
	}

	/**
	 * Read and sanitize the capture context (page, position, operator, etc.) from
	 * the request. Param names are prefixed (default pldv_).
	 *
	 * @return array{page:?string,position:?int,original_order:?int,placement:?string,context:?string,operator:?string,has_cta:bool}
	 */
	private function read_context(): array {
		$p = $this->settings->param_prefix();

		$page     = $this->req_text( $p . 'pg', 190 );
		$position = $this->req_int( $p . 'pos' );
		$order    = $this->req_int( $p . 'ord' );
		$placement = $this->req_text( $p . 'pl', 32 );
		$context  = $this->req_text( $p . 'ctx', 64 );
		$operator = $this->req_text( $p . 'op', 64 );

		$has_cta = ( null !== $operator ) || ( null !== $placement ) || ( null !== $context );

		return [
			'page'           => $page,
			'position'       => $position,
			'original_order' => $order,
			'placement'      => $placement,
			'context'        => $context,
			'operator'       => $operator,
			'has_cta'        => $has_cta,
		];
	}

	private function req_text( string $key, int $max ): ?string {
		if ( ! isset( $_GET[ $key ] ) ) {
			return null;
		}
		$val = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
		$val = trim( $val );
		if ( '' === $val ) {
			return null;
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $val, 0, $max ) : substr( $val, 0, $max );
	}

	private function req_int( string $key ): ?int {
		if ( ! isset( $_GET[ $key ] ) ) {
			return null;
		}
		$raw = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
		if ( '' === $raw ) {
			return null;
		}
		// Clamp to the SMALLINT UNSIGNED column range.
		return min( absint( $raw ), 65535 );
	}

	/**
	 * Remove our capture params from a URL so they never leak to the network.
	 */
	private function strip_capture_params( string $url ): string {
		$p     = $this->settings->param_prefix();
		$parts = wp_parse_url( $url );
		if ( empty( $parts['query'] ) ) {
			return $url;
		}

		parse_str( $parts['query'], $query );
		foreach ( array_keys( $query ) as $key ) {
			if ( strpos( $key, $p ) === 0 ) {
				unset( $query[ $key ] );
			}
		}

		$scheme   = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$host     = $parts['host'] ?? '';
		$port     = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path     = $parts['path'] ?? '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
		$new_qs   = http_build_query( $query );

		return $scheme . $host . $port . $path . ( $new_qs ? '?' . $new_qs : '' ) . $fragment;
	}

	private function ip_value(): ?string {
		$mode = $this->settings->ip_mode();
		if ( 'off' === $mode ) {
			return null;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		if ( '' === $ip ) {
			return null;
		}

		if ( 'raw' === $mode ) {
			// Stored in the same column; raw mode is opt-in for non-EU use.
			return substr( $ip, 0, 64 );
		}

		// Default: keyed HMAC (GDPR-friendly, still de-dupes). A dedicated secret
		// resists brute-forcing the low-entropy IPv4 space better than a plain
		// salted hash; falls back to wp_salt if the secret was never generated.
		$secret = get_option( 'pldv_ip_secret' );
		if ( ! $secret ) {
			try {
				$secret = bin2hex( random_bytes( 32 ) );
				add_option( 'pldv_ip_secret', $secret, '', 'yes' );
			} catch ( \Exception $e ) {
				$secret = wp_salt( 'nonce' );
			}
		}
		return hash_hmac( 'sha256', $ip, $secret );
	}

	private function user_agent_value(): ?string {
		if ( ! $this->settings->get( 'store_user_agent' ) ) {
			return null;
		}
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return null;
		}
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		return substr( $ua, 0, 255 );
	}
}
