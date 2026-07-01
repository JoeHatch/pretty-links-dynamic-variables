<?php
/**
 * Software → DV-parameter mappings.
 *
 * Source of truth is data/dv-mappings.json (imported from the StatsDrone DV
 * sheet). Operators may override/extend via the pldv_mappings option (the P3
 * Mappings editor) and the pldv_software_mappings filter. Each platform records
 * the URL param the opaque token is injected into and any value_constraint that
 * the injector must respect (numeric-only, prefix-required, etc.).
 *
 * @package PrettyLinksDV
 */

namespace PrettyLinksDV;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mappings {

	const OPTION = 'pldv_mappings';

	/** Operator-created software→parameter mappings (full self-contained entries). */
	const CUSTOM_OPTION = 'pldv_custom_mappings';

	/** @var array<string,array>|null Slug-keyed platform map, lazily built. */
	private $map = null;

	/**
	 * @return array<string,array> Platform definitions keyed by slug.
	 */
	public function all(): array {
		if ( null === $this->map ) {
			$this->map = $this->load();
		}
		return $this->map;
	}

	/**
	 * @return array|null Platform definition for a slug, or null if unknown.
	 */
	public function get( string $slug ) {
		$all = $this->all();
		return $all[ $slug ] ?? null;
	}

	private function load(): array {
		$platforms = [];

		// 1. Bundled JSON (source of truth).
		$json_file = PLDV_DIR . 'data/dv-mappings.json';
		if ( is_readable( $json_file ) ) {
			$decoded = json_decode( (string) file_get_contents( $json_file ), true );
			if ( is_array( $decoded ) && ! empty( $decoded['platforms'] ) ) {
				foreach ( $decoded['platforms'] as $p ) {
					if ( ! empty( $p['slug'] ) ) {
						$platforms[ $p['slug'] ] = $p;
					}
				}
			}
		}

		// 2. Operator overrides from the Mappings editor (merge over bundled).
		$override = get_option( self::OPTION );
		if ( is_array( $override ) ) {
			foreach ( $override as $slug => $p ) {
				$platforms[ $slug ] = is_array( $p )
					? array_merge( $platforms[ $slug ] ?? [], $p )
					: $platforms[ $slug ];
			}
		}

		// 3. Operator-created custom mappings (full entries; not in the bundled
		// sheet). Kept in a separate option so "Reset to bundled sheet" (which
		// deletes self::OPTION) leaves them intact.
		$custom = get_option( self::CUSTOM_OPTION );
		if ( is_array( $custom ) ) {
			foreach ( $custom as $slug => $p ) {
				if ( ! is_array( $p ) || empty( $p['token_param'] ) ) {
					continue;
				}
				$p['slug']         = $slug;
				$p['custom']       = true;
				$platforms[ $slug ] = $p;
			}
		}

		/**
		 * Filter the resolved platform map.
		 *
		 * @param array $platforms Slug-keyed platform definitions.
		 */
		return apply_filters( 'pldv_software_mappings', $platforms );
	}

	/**
	 * Allowed URL params a platform can inject into. Multi-param platforms (e.g.
	 * NetRefer var1/var2/subid) list them under 'params'; others fall back to the
	 * single default token_param. Used for per-link param overrides and the
	 * Mappings/Test/meta-box UIs.
	 *
	 * @return string[] Ordered list of url_param names (first = default).
	 */
	public function params_for( string $slug ): array {
		$platform = $this->get( $slug );
		if ( ! $platform ) {
			return [];
		}

		$params = [];
		if ( ! empty( $platform['params'] ) && is_array( $platform['params'] ) ) {
			foreach ( $platform['params'] as $p ) {
				$name = is_array( $p ) ? ( $p['url_param'] ?? '' ) : (string) $p;
				if ( '' !== $name && ! in_array( $name, $params, true ) ) {
					$params[] = $name;
				}
			}
		}

		$default = (string) ( $platform['token_param'] ?? '' );
		if ( '' !== $default && ! in_array( $default, $params, true ) ) {
			array_unshift( $params, $default );
		}

		return $params;
	}

	/**
	 * Resolve how a token should be injected for a given software slug.
	 *
	 * @param string $slug  Software slug stored on the link.
	 * @param string $token Encrypted/opaque token value.
	 * @param string $numeric_token Numeric fallback for numeric-only / length-capped platforms.
	 * @param string $param_override Per-link chosen param; used only if it is one of
	 *                               the platform's allowed params (else the default).
	 * @return array{
	 *   status:string, param:?string, value:?string, query:?string
	 * } status ∈ tracked|no_mapping|disabled|unsupported_value
	 */
	public function resolve_injection( string $slug, string $token, string $numeric_token = '', string $param_override = '' ): array {
		$platform = $this->get( $slug );

		if ( ! $platform || empty( $platform['token_param'] ) ) {
			return [ 'status' => 'no_mapping', 'param' => null, 'value' => null, 'query' => null ];
		}

		// Operator disabled this platform in the Mappings editor: record the click
		// (lossless, with a visible status) but never inject a token.
		if ( ! empty( $platform['disabled'] ) ) {
			return [ 'status' => 'disabled', 'param' => null, 'value' => null, 'query' => null ];
		}

		// Per-link override picks which of the platform's params to use; an unknown
		// override (e.g. mapping edited since) falls back to the default token_param.
		$param = (string) $platform['token_param'];
		if ( '' !== $param_override && in_array( $param_override, $this->params_for( $slug ), true ) ) {
			$param = $param_override;
		}

		$constraint = $platform['value_constraint'] ?? null;
		$value      = $token;

		if ( is_array( $constraint ) && ! empty( $constraint['type'] ) ) {
			switch ( $constraint['type'] ) {
				case 'numeric_only':
					if ( $numeric_token !== '' && ctype_digit( $numeric_token ) ) {
						$value = $numeric_token;
					} else {
						// Token isn't numeric and no numeric fallback supplied.
						return [ 'status' => 'unsupported_value', 'param' => $param, 'value' => null, 'query' => null ];
					}
					break;

				case 'needs_config':
					// URL param unconfirmed for this platform; record but don't inject.
					return [ 'status' => 'unsupported_value', 'param' => $param, 'value' => null, 'query' => null ];

				// comma_joined / merged / max_user_params / param_varies / prefix_required:
				// a single-token injection is still valid; the operator handles the
				// link-side prefix/format. (Full handling lands with the P3 editor.)
			}
		}

		// Character-limit restriction: some programs cap the parameter length. We
		// never truncate (an encrypted token cut mid-string is undecryptable) —
		// instead fall back to the shorter numeric click-id when it fits, else
		// record unsupported_value and inject nothing (lossless).
		$max_length = isset( $platform['max_length'] ) ? (int) $platform['max_length'] : 0;
		if ( $max_length > 0 && strlen( $value ) > $max_length ) {
			if ( '' !== $numeric_token && ctype_digit( $numeric_token ) && strlen( $numeric_token ) <= $max_length ) {
				$value = $numeric_token;
			} else {
				return [ 'status' => 'unsupported_value', 'param' => $param, 'value' => null, 'query' => null ];
			}
		}

		$query = rawurlencode( $param ) . '=' . rawurlencode( $value );

		return [ 'status' => 'tracked', 'param' => $param, 'value' => $value, 'query' => $query ];
	}
}
