<?php
/**
 * Click ID generation.
 *
 * Fixes the v1 entropy bug: v1 ran base_convert() over a 16-hex-digit (64-bit)
 * value, which overflows PHP_INT_MAX and is silently rounded to a float, so
 * distinct random values collapsed to the same ID. We now emit the raw random
 * bytes as hex with no lossy integer conversion.
 *
 * @package PrettyLinksDV
 */

namespace PrettyLinksDV;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Click_Id {

	/**
	 * 128-bit cryptographically secure click ID as 32 lowercase hex chars.
	 */
	public static function generate(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			// Extremely rare: no CSPRNG available. Fall back without integer math.
			return md5( uniqid( (string) mt_rand(), true ) );
		}
	}

	/**
	 * Numeric-only click ID for platforms that reject non-numeric DV values
	 * (e.g. Real Time Gaming). Returns an 18-digit decimal string.
	 */
	public static function generate_numeric(): string {
		try {
			// 7 random bytes -> up to ~56 bits, comfortably within an 18-digit range.
			$n = 0;
			foreach ( str_split( random_bytes( 7 ) ) as $byte ) {
				$n = ( $n << 8 ) | ord( $byte );
			}
		} catch ( \Exception $e ) {
			$n = mt_rand();
		}

		return str_pad( (string) abs( (int) $n ), 18, '0', STR_PAD_LEFT );
	}
}
