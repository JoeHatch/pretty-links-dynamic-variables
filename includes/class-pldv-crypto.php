<?php
/**
 * Token encryption and key management.
 *
 * Builds the opaque "DV token" that is sent to the affiliate network. By default
 * the token wraps only the click ID (short, dodges subid length truncation); the
 * page/position/etc. live in our DB keyed by click_id. Authenticated encryption
 * via libsodium secretbox (bundled in PHP 7.2+). Output is base64url(nonce ‖ ct).
 *
 * Key resolution order:
 *   1. PLDV_SECRET_KEY constant (wp-config.php) — preferred, out of the DB.
 *   2. pldv_secret_key option — auto-generated on activation.
 * Rotation: PLDV_SECRET_KEY_OLD (or pldv_secret_key_old) is also tried on decrypt
 * so tokens issued before a rotation still resolve.
 *
 * @package PrettyLinksDV
 */

namespace PrettyLinksDV;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crypto {

	const KEY_OPTION     = 'pldv_secret_key';
	const KEY_OPTION_OLD = 'pldv_secret_key_old';

	/**
	 * Whether libsodium secretbox is available.
	 */
	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
	}

	/**
	 * Whether encryption is actually operational (libsodium present AND a usable
	 * key resolvable). When this is false but encryption is enabled in settings,
	 * tokens are emitted in plaintext — callers should warn the operator rather
	 * than silently downgrade.
	 */
	public static function operational(): bool {
		return self::available() && ! empty( self::keys() );
	}

	/**
	 * Ensure an encryption key exists (called on activation).
	 */
	public static function ensure_key(): void {
		if ( defined( 'PLDV_SECRET_KEY' ) && PLDV_SECRET_KEY ) {
			return;
		}
		if ( get_option( self::KEY_OPTION ) ) {
			return;
		}
		try {
			$key = self::available()
				? sodium_crypto_secretbox_keygen()
				: random_bytes( 32 );
			// Store base64-encoded; autoload no (read only at redirect time? actually hot path — keep autoloaded).
			add_option( self::KEY_OPTION, base64_encode( $key ), '', 'yes' );
		} catch ( \Exception $e ) {
			// Leave unset; build_token() will degrade to plaintext and flag.
		}
	}

	/**
	 * @return string[] Raw 32-byte keys to try (current first, then old).
	 */
	private static function keys(): array {
		$keys = [];

		if ( defined( 'PLDV_SECRET_KEY' ) && PLDV_SECRET_KEY ) {
			$keys[] = self::normalize_key( PLDV_SECRET_KEY );
		}
		$opt = get_option( self::KEY_OPTION );
		if ( $opt ) {
			$keys[] = self::normalize_key( $opt );
		}
		if ( defined( 'PLDV_SECRET_KEY_OLD' ) && PLDV_SECRET_KEY_OLD ) {
			$keys[] = self::normalize_key( PLDV_SECRET_KEY_OLD );
		}
		$opt_old = get_option( self::KEY_OPTION_OLD );
		if ( $opt_old ) {
			$keys[] = self::normalize_key( $opt_old );
		}

		return array_values( array_filter( $keys ) );
	}

	/**
	 * Accept a base64 32-byte key, a raw 32-byte string, or derive 32 bytes via
	 * SHA-256 from an arbitrary passphrase constant.
	 */
	private static function normalize_key( string $key ): ?string {
		$decoded = base64_decode( $key, true );
		if ( false !== $decoded && strlen( $decoded ) === 32 ) {
			return $decoded;
		}
		if ( strlen( $key ) === 32 ) {
			return $key;
		}
		// Passphrase of any other length: derive a stable 32-byte key.
		return hash( 'sha256', $key, true );
	}

	/**
	 * Build the token to send to the network.
	 *
	 * @param string $click_id   Raw click ID.
	 * @param bool   $encrypt    Whether to encrypt (settings-driven).
	 * @return array{value:string, encrypted:bool} The wire value and whether it was encrypted.
	 */
	public static function build_token( string $click_id, bool $encrypt = true ): array {
		if ( ! $encrypt || ! self::available() ) {
			return [ 'value' => $click_id, 'encrypted' => false ];
		}

		$keys = self::keys();
		if ( empty( $keys ) ) {
			return [ 'value' => $click_id, 'encrypted' => false ];
		}

		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $click_id, $nonce, $keys[0] );
			return [ 'value' => self::b64url_encode( $nonce . $cipher ), 'encrypted' => true ];
		} catch ( \Exception $e ) {
			return [ 'value' => $click_id, 'encrypted' => false ];
		}
	}

	/**
	 * Decrypt a token back to its click ID. Returns null if it cannot be resolved
	 * with any active key. (Used by the Test tool and the future postback loop.)
	 */
	public static function open_token( string $token ): ?string {
		if ( ! self::available() ) {
			return null;
		}

		$raw   = self::b64url_decode( $token );
		$nbytes = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if ( null === $raw || strlen( $raw ) <= $nbytes ) {
			return null;
		}

		$nonce  = substr( $raw, 0, $nbytes );
		$cipher = substr( $raw, $nbytes );

		foreach ( self::keys() as $key ) {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			if ( false !== $plain ) {
				return $plain;
			}
		}

		return null;
	}

	private static function b64url_encode( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	private static function b64url_decode( string $str ): ?string {
		$str = strtr( $str, '-_', '+/' );
		$pad = strlen( $str ) % 4;
		if ( $pad ) {
			$str .= str_repeat( '=', 4 - $pad );
		}
		$out = base64_decode( $str, true );
		return false === $out ? null : $out;
	}
}
