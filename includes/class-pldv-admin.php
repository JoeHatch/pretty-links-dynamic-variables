<?php
/**
 * Managed admin application.
 *
 * One top-level "Pretty Links DV" menu with four tabbed screens:
 *   - Reports   : clicks, coverage, dimensional breakdowns, drill-down, CSV.
 *   - Settings  : capture scope, token/format, encryption keys, privacy.
 *   - Mappings  : per-platform DV-parameter config (seeded from the sheet).
 *   - Test      : dry-run / live-test the pipeline on a specific link.
 *
 * Every screen is capability-gated (manage_options), nonce-protected, and fully
 * escaped. Mutations are handled inline with their own nonces; CSV export streams
 * via admin-post.
 *
 * @package PrettyLinksDV
 */

namespace PrettyLinksDV;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	// NOTE: slug deliberately avoids the substring "pretty-link" — Pretty Links
	// detects "its own" admin pages with strstr($screen_id, 'pretty-link'), so a
	// slug containing it would make Pretty Links stamp its footer/branding onto
	// our screens.
	const SLUG = 'pldv-dashboard';
	const CAP  = 'manage_options';

	/** @var Settings */
	private $settings;
	/** @var Mappings */
	private $mappings;
	/** @var Reports */
	private $reports;
	/** @var Recorder */
	private $recorder;

	public function __construct( Settings $settings, Mappings $mappings, Recorder $recorder ) {
		$this->settings = $settings;
		$this->mappings = $mappings;
		$this->recorder = $recorder;
		$this->reports  = new Reports();
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_pldv_export_csv', [ $this, 'export_csv' ] );
		add_action( 'admin_notices', [ $this, 'encryption_notice' ] );
	}

	/**
	 * Warn when encryption is enabled in settings but not actually operational
	 * (missing libsodium or no usable key) — tokens would be sent in plaintext.
	 * Prevents a silent security downgrade.
	 */
	public function encryption_notice(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( ! $this->settings->encrypt_token() || Crypto::operational() ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Pretty Links DV:', 'pretty-links-dv' ) . '</strong> '
			. esc_html__( 'Token encryption is enabled but not operational (libsodium unavailable or no encryption key). Click tokens are currently being sent UNENCRYPTED. Set PLDV_SECRET_KEY in wp-config.php or check your PHP libsodium support.', 'pretty-links-dv' )
			. '</p></div>';
	}

	public function menu(): void {
		add_menu_page(
			__( 'Pretty Links DV', 'pretty-links-dv' ),
			__( 'Pretty Links DV', 'pretty-links-dv' ),
			self::CAP,
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-randomize',
			76
		);
	}

	private function current_tab(): string {
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'reports';
		$allowed = [ 'reports', 'settings', 'mappings', 'test' ];
		return in_array( $tab, $allowed, true ) ? $tab : 'reports';
	}

	private function tab_url( string $tab ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab );
	}

	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'pretty-links-dv' ) );
		}

		$tab = $this->current_tab();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Pretty Links Dynamic Variables', 'pretty-links-dv' ) . '</h1>';

		// Tab nav.
		echo '<nav class="nav-tab-wrapper">';
		foreach ( [
			'reports'  => __( 'Reports', 'pretty-links-dv' ),
			'settings' => __( 'Settings', 'pretty-links-dv' ),
			'mappings' => __( 'Mappings', 'pretty-links-dv' ),
			'test'     => __( 'Test', 'pretty-links-dv' ),
		] as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( $this->tab_url( $key ) ),
				$key === $tab ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		echo '<div style="margin-top:16px;">';
		switch ( $tab ) {
			case 'settings':
				$this->render_settings();
				break;
			case 'mappings':
				$this->render_mappings();
				break;
			case 'test':
				$this->render_test();
				break;
			default:
				$this->render_reports();
		}
		echo '</div></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	private function render_settings(): void {
		if ( isset( $_POST['pldv_settings_submit'] ) && check_admin_referer( 'pldv_save_settings' ) ) {
			$this->save_settings();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'pretty-links-dv' ) . '</p></div>';
			$this->settings = new Settings(); // reload
		}

		$s = $this->settings;

		echo '<form method="post">';
		wp_nonce_field( 'pldv_save_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->select_row(
			__( 'Capture scope', 'pretty-links-dv' ),
			'capture_scope',
			[ 'all' => __( 'All Pretty Link clicks (lossless)', 'pretty-links-dv' ), 'tracked' => __( 'Only software/CTA-flagged links', 'pretty-links-dv' ) ],
			(string) $s->get( 'capture_scope' )
		);

		$this->checkbox_row( __( 'Encrypt DV token', 'pretty-links-dv' ), 'encrypt_token', (bool) $s->get( 'encrypt_token' ),
			Crypto::available() ? __( 'libsodium available.', 'pretty-links-dv' ) : __( 'libsodium NOT available — token will be sent in plaintext.', 'pretty-links-dv' ) );

		$this->select_row(
			__( 'IP storage', 'pretty-links-dv' ),
			'ip_mode',
			[ 'hash' => __( 'Salted hash (GDPR-friendly)', 'pretty-links-dv' ), 'off' => __( 'Do not store', 'pretty-links-dv' ), 'raw' => __( 'Raw IP', 'pretty-links-dv' ) ],
			(string) $s->get( 'ip_mode' )
		);

		$this->checkbox_row( __( 'Store User-Agent', 'pretty-links-dv' ), 'store_user_agent', (bool) $s->get( 'store_user_agent' ), '' );

		$this->text_row( __( 'Request param prefix', 'pretty-links-dv' ), 'param_prefix', (string) $s->get( 'param_prefix' ), 'pldv_' );
		$this->text_row( __( 'Pretty Link path prefix', 'pretty-links-dv' ), 'link_prefix', (string) $s->get( 'link_prefix' ), '/go/' );
		$this->text_row( __( 'Row retention (days, 0 = forever)', 'pretty-links-dv' ), 'retention_days', (string) (int) $s->get( 'retention_days' ), '0' );

		echo '</tbody></table>';

		// Key status (never prints the key itself).
		$key_source = ( defined( 'PLDV_SECRET_KEY' ) && PLDV_SECRET_KEY ) ? 'PLDV_SECRET_KEY constant' : ( get_option( Crypto::KEY_OPTION ) ? 'auto-generated option' : 'none' );
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: key source */ __( 'Encryption key source: %s', 'pretty-links-dv' ), $key_source ) ) . '</p>';

		submit_button( __( 'Save Settings', 'pretty-links-dv' ), 'primary', 'pldv_settings_submit' );
		echo '</form>';
	}

	private function save_settings(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$in  = wp_unslash( $_POST );
		$out = [
			'capture_scope'    => in_array( $in['capture_scope'] ?? 'all', [ 'all', 'tracked' ], true ) ? $in['capture_scope'] : 'all',
			'encrypt_token'    => ! empty( $in['encrypt_token'] ),
			'ip_mode'          => in_array( $in['ip_mode'] ?? 'hash', [ 'hash', 'off', 'raw' ], true ) ? $in['ip_mode'] : 'hash',
			'store_user_agent' => ! empty( $in['store_user_agent'] ),
			'param_prefix'     => sanitize_key( $in['param_prefix'] ?? 'pldv_' ) ?: 'pldv_',
			'link_prefix'      => '/' . trim( sanitize_text_field( $in['link_prefix'] ?? '/go/' ), '/' ) . '/',
			'retention_days'   => absint( $in['retention_days'] ?? 0 ),
		];
		update_option( Settings::OPTION, array_merge( Settings::defaults(), $out ) );
	}

	/* ------------------------------------------------------------------ */
	/* Mappings                                                            */
	/* ------------------------------------------------------------------ */

	private function render_mappings(): void {
		if ( isset( $_POST['pldv_mappings_submit'] ) && check_admin_referer( 'pldv_save_mappings' ) ) {
			$this->save_mappings();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Mappings updated.', 'pretty-links-dv' ) . '</p></div>';
			$this->mappings = new Mappings();
		}
		if ( isset( $_POST['pldv_mappings_reset'] ) && check_admin_referer( 'pldv_save_mappings' ) ) {
			delete_option( Mappings::OPTION );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Mappings reset to the bundled sheet.', 'pretty-links-dv' ) . '</p></div>';
			$this->mappings = new Mappings();
		}
		if ( isset( $_POST['pldv_custom_mappings_submit'] ) && check_admin_referer( 'pldv_save_custom_mappings' ) ) {
			$this->save_custom_mappings();
			$this->mappings = new Mappings();
		}

		echo '<p class="description">' . esc_html__( 'Token parameter(s) are the URL parameter(s) the encrypted DV token is injected into; the first is the default. Tick "Multiple params" for platforms that accept several (e.g. NetRefer var1/subid/clickid) to let editors pick which one each link uses. Seeded from the StatsDrone DV sheet; edits are saved as overrides.', 'pretty-links-dv' ) . '</p>';

		echo '<form method="post">';
		wp_nonce_field( 'pldv_save_mappings' );
		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Platform', 'pretty-links-dv' ) . '</th>';
		echo '<th>' . esc_html__( 'Token parameter(s)', 'pretty-links-dv' ) . '</th>';
		echo '<th>' . esc_html__( 'Multiple params', 'pretty-links-dv' ) . '</th>';
		echo '<th>' . esc_html__( 'Report', 'pretty-links-dv' ) . '</th>';
		echo '<th>' . esc_html__( 'Constraint', 'pretty-links-dv' ) . '</th>';
		echo '<th>' . esc_html__( 'Enabled', 'pretty-links-dv' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $this->mappings->all() as $slug => $p ) {
			if ( ! empty( $p['custom'] ) ) {
				continue; // Operator-created entries are managed in the Custom mappings section below.
			}
			$constraint = ! empty( $p['value_constraint']['type'] ) ? str_replace( '_', ' ', $p['value_constraint']['type'] ) : '—';
			$enabled    = ! isset( $p['disabled'] ) || ! $p['disabled'];
			$params_csv = implode( ', ', $this->mappings->params_for( $slug ) );
			echo '<tr>';
			echo '<td><strong>' . esc_html( $p['label'] ?? $slug ) . '</strong><br><code style="font-size:11px;">' . esc_html( $slug ) . '</code></td>';
			printf(
				'<td><input type="text" name="map[%s][params]" value="%s" class="regular-text" style="width:160px;"></td>',
				esc_attr( $slug ),
				esc_attr( $params_csv )
			);
			printf(
				'<td style="text-align:center;"><input type="checkbox" name="map[%s][multi_param]" value="1" %s></td>',
				esc_attr( $slug ),
				checked( ! empty( $p['multi_param'] ), true, false )
			);
			echo '<td>' . esc_html( $p['report_name'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $constraint ) . '</td>';
			printf(
				'<td><input type="checkbox" name="map[%s][enabled]" value="1" %s></td>',
				esc_attr( $slug ),
				checked( $enabled, true, false )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p>';
		submit_button( __( 'Save Mappings', 'pretty-links-dv' ), 'primary', 'pldv_mappings_submit', false );
		echo ' ';
		submit_button( __( 'Reset to bundled sheet', 'pretty-links-dv' ), 'secondary', 'pldv_mappings_reset', false );
		echo '</p></form>';

		$this->render_custom_mappings();
	}

	/**
	 * Custom mappings: operator-created software → token-parameter pairs for
	 * platforms not in the bundled sheet. Full add / edit / delete; once saved
	 * they behave exactly like bundled platforms (selectable on links, in the
	 * Test tool, and injected at redirect time).
	 */
	private function render_custom_mappings(): void {
		$custom = get_option( Mappings::CUSTOM_OPTION );
		$custom = is_array( $custom ) ? $custom : [];

		echo '<h2 style="margin-top:2em;">' . esc_html__( 'Custom mappings', 'pretty-links-dv' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Add your own affiliate software and the URL parameter(s) its DV token should be injected into, for platforms not in the bundled sheet. These become selectable on links and in the Test tool.', 'pretty-links-dv' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Token parameter(s): comma-separated; the first is the default. Tick "Multiple params" to let editors pick which parameter each link uses (needs 2+ params). Constraint / max length are enforced at injection: numeric-only sends the numeric click-id; over-limit values fall back to the numeric id or, if that is still too long, record unsupported_value and inject nothing (never truncated).', 'pretty-links-dv' ) . '</p>';

		$constraint_opts = [
			''             => __( 'None', 'pretty-links-dv' ),
			'numeric_only' => __( 'Numeric only', 'pretty-links-dv' ),
			'needs_config' => __( "Needs config (don't inject)", 'pretty-links-dv' ),
		];

		echo '<form method="post">';
		wp_nonce_field( 'pldv_save_custom_mappings' );
		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Software name', 'pretty-links-dv' ) . '</th>';
		echo '<th>' . esc_html__( 'Slug', 'pretty-links-dv' ) . '</th>';
		echo '<th>' . esc_html__( 'Token parameter(s)', 'pretty-links-dv' ) . '</th>';
		echo '<th>' . esc_html__( 'Multiple params', 'pretty-links-dv' ) . '</th>';
		echo '<th>' . esc_html__( 'Constraint', 'pretty-links-dv' ) . '</th>';
		echo '<th>' . esc_html__( 'Max length', 'pretty-links-dv' ) . '</th>';
		echo '<th>' . esc_html__( 'Notes (report location)', 'pretty-links-dv' ) . '</th>';
		echo '<th>' . esc_html__( 'Enabled', 'pretty-links-dv' ) . '</th>';
		echo '<th>' . esc_html__( 'Delete', 'pretty-links-dv' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $custom as $slug => $p ) {
			$slug           = sanitize_key( $slug );
			$enabled        = empty( $p['disabled'] );
			$params_csv     = implode( ', ', $this->mappings->params_for( $slug ) );
			$constraint_val = $p['value_constraint']['type'] ?? '';
			$max_length     = ! empty( $p['max_length'] ) ? (int) $p['max_length'] : '';
			echo '<tr>';
			printf(
				'<td><input type="text" name="custom_map[%s][label]" value="%s" class="regular-text" style="width:160px;"></td>',
				esc_attr( $slug ),
				esc_attr( $p['label'] ?? $slug )
			);
			echo '<td><code style="font-size:11px;">' . esc_html( $slug ) . '</code></td>';
			printf(
				'<td><input type="text" name="custom_map[%s][params]" value="%s" class="regular-text" style="width:160px;"></td>',
				esc_attr( $slug ),
				esc_attr( $params_csv )
			);
			printf(
				'<td style="text-align:center;"><input type="checkbox" name="custom_map[%s][multi_param]" value="1" %s></td>',
				esc_attr( $slug ),
				checked( ! empty( $p['multi_param'] ), true, false )
			);
			echo '<td>' . $this->constraint_select( "custom_map[{$slug}][constraint]", (string) $constraint_val, $constraint_opts ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			printf(
				'<td><input type="number" min="0" name="custom_map[%s][max_length]" value="%s" style="width:70px;"></td>',
				esc_attr( $slug ),
				esc_attr( (string) $max_length )
			);
			printf(
				'<td><input type="text" name="custom_map[%s][notes]" value="%s" class="regular-text" style="width:200px;"></td>',
				esc_attr( $slug ),
				esc_attr( $p['notes'] ?? '' )
			);
			printf(
				'<td><input type="checkbox" name="custom_map[%s][enabled]" value="1" %s></td>',
				esc_attr( $slug ),
				checked( $enabled, true, false )
			);
			printf(
				'<td><input type="checkbox" name="custom_map[%s][delete]" value="1"></td>',
				esc_attr( $slug )
			);
			echo '</tr>';
		}

		// Add-new row.
		echo '<tr>';
		echo '<td><input type="text" name="custom_new[label]" value="" placeholder="' . esc_attr__( 'e.g. My Network', 'pretty-links-dv' ) . '" class="regular-text" style="width:160px;"></td>';
		echo '<td><span class="description">' . esc_html__( 'auto', 'pretty-links-dv' ) . '</span></td>';
		echo '<td><input type="text" name="custom_new[params]" value="" placeholder="' . esc_attr__( 'e.g. var1, subid', 'pretty-links-dv' ) . '" class="regular-text" style="width:160px;"></td>';
		echo '<td style="text-align:center;"><input type="checkbox" name="custom_new[multi_param]" value="1"></td>';
		echo '<td>' . $this->constraint_select( 'custom_new[constraint]', '', $constraint_opts ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td><input type="number" min="0" name="custom_new[max_length]" value="" style="width:70px;"></td>';
		echo '<td><input type="text" name="custom_new[notes]" value="" placeholder="' . esc_attr__( 'Where to find it in the report', 'pretty-links-dv' ) . '" class="regular-text" style="width:200px;"></td>';
		echo '<td colspan="2"><span class="description">' . esc_html__( 'new', 'pretty-links-dv' ) . '</span></td>';
		echo '</tr>';

		echo '</tbody></table>';
		echo '<p>';
		submit_button( __( 'Save Custom Mappings', 'pretty-links-dv' ), 'primary', 'pldv_custom_mappings_submit', false );
		echo '</p></form>';
	}

	/** Render a constraint-type <select> for the custom mappings editor. */
	private function constraint_select( string $name, string $selected, array $opts ): string {
		$html = '<select name="' . esc_attr( $name ) . '">';
		foreach ( $opts as $val => $label ) {
			$html .= sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $val ),
				selected( $val, $selected, false ),
				esc_html( $label )
			);
		}
		return $html . '</select>';
	}

	/**
	 * Build a full custom-mapping entry from posted row fields. Parses the
	 * comma-separated params (first = default token_param), the constraint type,
	 * character limit, and notes. Returns null if no usable param was supplied.
	 *
	 * @return array|null
	 */
	private function custom_entry_from_row( string $slug, string $label, array $row, bool $disabled ): ?array {
		$parts  = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $row['params'] ?? '' ) ) ), 'strlen' );
		$parts  = array_values( array_unique( $parts ) );
		if ( empty( $parts ) ) {
			return null;
		}

		$params = array_map( function ( $p ) {
			return [ 'url_param' => $p ];
		}, $parts );

		$type  = sanitize_key( $row['constraint'] ?? '' );
		$entry = [
			'slug'             => $slug,
			'label'            => '' !== $label ? $label : $slug,
			'token_param'      => $parts[0],
			'params'           => $params,
			'value_constraint' => in_array( $type, [ 'numeric_only', 'needs_config' ], true ) ? [ 'type' => $type ] : null,
			'max_length'       => max( 0, (int) ( $row['max_length'] ?? 0 ) ),
			'notes'            => sanitize_text_field( $row['notes'] ?? '' ),
			'multi_param'      => ! empty( $row['multi_param'] ) && count( $params ) > 1,
			'custom'           => true,
			'disabled'         => $disabled,
		];

		return $entry;
	}

	private function save_mappings(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$in       = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : [];
		$override = [];
		foreach ( $in as $slug => $row ) {
			$slug = sanitize_key( $slug );
			if ( ! $slug ) {
				continue;
			}
			$parts = array_values( array_unique( array_filter(
				array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $row['params'] ?? '' ) ) ),
				'strlen'
			) ) );
			$entry = [
				'token_param' => $parts[0] ?? '',
				'disabled'    => empty( $row['enabled'] ),
				'multi_param' => ! empty( $row['multi_param'] ) && count( $parts ) > 1,
			];
			if ( ! empty( $parts ) ) {
				$entry['params'] = array_map( function ( $p ) {
					return [ 'url_param' => $p ];
				}, $parts );
			}
			$override[ $slug ] = $entry;
		}
		update_option( Mappings::OPTION, $override );
	}

	/**
	 * Persist operator-created custom mappings: edit/enable/delete existing rows
	 * and optionally create one new entry. Slug is derived from the name on
	 * creation and then held stable (links reference it).
	 */
	private function save_custom_mappings(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$existing = get_option( Mappings::CUSTOM_OPTION );
		$existing = is_array( $existing ) ? $existing : [];

		$rows   = isset( $_POST['custom_map'] ) && is_array( $_POST['custom_map'] ) ? wp_unslash( $_POST['custom_map'] ) : [];
		$custom = [];
		foreach ( $rows as $slug => $row ) {
			$slug = sanitize_key( $slug );
			if ( ! $slug || ! isset( $existing[ $slug ] ) ) {
				continue; // Only edit rows we already own.
			}
			if ( ! empty( $row['delete'] ) ) {
				continue; // Dropped.
			}
			$entry = $this->custom_entry_from_row( $slug, sanitize_text_field( $row['label'] ?? '' ), $row, empty( $row['enabled'] ) );
			if ( null !== $entry ) {
				$custom[ $slug ] = $entry;
			}
		}

		// Optional new entry.
		$new   = isset( $_POST['custom_new'] ) && is_array( $_POST['custom_new'] ) ? wp_unslash( $_POST['custom_new'] ) : [];
		$name  = sanitize_text_field( $new['label'] ?? '' );
		$param = sanitize_text_field( $new['params'] ?? '' );
		$error = '';
		if ( '' !== $name || '' !== $param ) {
			$new_slug = sanitize_key( sanitize_title( $name ) );
			if ( '' === $new_slug ) {
				$error = __( 'Enter a software name for the new mapping.', 'pretty-links-dv' );
			} elseif ( '' === trim( $param ) ) {
				$error = __( 'Enter at least one token parameter for the new mapping.', 'pretty-links-dv' );
			} elseif ( isset( $custom[ $new_slug ] ) || $this->mappings->get( $new_slug ) ) {
				/* translators: %s: mapping slug. */
				$error = sprintf( __( 'A mapping with the slug "%s" already exists.', 'pretty-links-dv' ), $new_slug );
			} else {
				$entry = $this->custom_entry_from_row( $new_slug, $name, $new, false );
				if ( null === $entry ) {
					$error = __( 'Enter at least one token parameter for the new mapping.', 'pretty-links-dv' );
				} else {
					$custom[ $new_slug ] = $entry;
				}
			}
		}

		update_option( Mappings::CUSTOM_OPTION, $custom );

		if ( '' !== $error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Custom mappings updated.', 'pretty-links-dv' ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Test / simulate                                                     */
	/* ------------------------------------------------------------------ */

	private function render_test(): void {
		$result   = null;
		$live     = false;
		$inputs   = [ 'target_url' => '', 'software' => '', 'param' => '', 'page' => '', 'position' => '', 'operator' => '' ];

		if ( isset( $_POST['pldv_test_submit'] ) && check_admin_referer( 'pldv_run_test' ) ) {
			$in = wp_unslash( $_POST );
			$inputs['target_url'] = esc_url_raw( $in['target_url'] ?? '' );
			$inputs['software']   = sanitize_key( $in['software'] ?? '' );
			$inputs['param']      = sanitize_text_field( $in['param'] ?? '' );
			$inputs['page']       = sanitize_text_field( $in['page'] ?? '' );
			$inputs['position']   = $in['position'] !== '' ? absint( $in['position'] ) : null;
			$inputs['operator']   = sanitize_text_field( $in['operator'] ?? '' );

			if ( $inputs['target_url'] ) {
				$result = $this->recorder->simulate(
					$inputs['target_url'],
					$inputs['software'],
					[
						'page'     => $inputs['page'] ?: null,
						'position' => $inputs['position'],
						'operator' => $inputs['operator'] ?: null,
					],
					$inputs['param']
				);
				$live = ! empty( $in['pldv_live'] ) && current_user_can( self::CAP );
				if ( $live ) {
					( new DB() )->insert( [
						'click_id'         => $result['click_id'],
						'token'            => $result['token'],
						'link_slug'        => null,
						'software'         => $inputs['software'] ?: null,
						'mapping_status'   => $result['mapping_status'],
						'param_sent'       => $result['param_sent'],
						'sent_value'       => $result['sent_value'],
						'page'             => $inputs['page'] ?: null,
						'clicked_position' => $inputs['position'],
						'operator'         => $inputs['operator'] ?: null,
						'target_url'       => $result['final_url'],
						'is_test'          => 1,
					] );
				}
			}
		}

		// Pull a handful of real Pretty Links to make testing easy.
		$links = $this->pretty_links( 100 );

		echo '<form method="post">';
		wp_nonce_field( 'pldv_run_test' );
		echo '<table class="form-table" role="presentation"><tbody>';

		// Quick-pick from existing links (fills the target + software via JS-free reload is overkill; we just list them).
		if ( $links ) {
			echo '<tr><th>' . esc_html__( 'Existing Pretty Links', 'pretty-links-dv' ) . '</th><td>';
			echo '<select onchange="if(this.value){var v=this.value.split(\'|\');document.getElementById(\'pldv_target\').value=v[0];}" style="max-width:520px;">';
			echo '<option value="">' . esc_html__( '— pick to fill target URL —', 'pretty-links-dv' ) . '</option>';
			foreach ( $links as $l ) {
				printf( '<option value="%s">%s</option>', esc_attr( $l->url . '|' . $l->slug ), esc_html( ( $l->slug ?: $l->name ) . ' → ' . $l->url ) );
			}
			echo '</select></td></tr>';
		}

		$this->test_input( 'target_url', __( 'Target URL', 'pretty-links-dv' ), $inputs['target_url'], 'pldv_target', 'https://go.example.com/visit/?bta=123' );

		echo '<tr><th>' . esc_html__( 'Software', 'pretty-links-dv' ) . '</th><td><select name="software">';
		echo '<option value="">' . esc_html__( '— none —', 'pretty-links-dv' ) . '</option>';
		foreach ( $this->mappings->all() as $slug => $p ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $slug ), selected( $slug, $inputs['software'], false ), esc_html( $p['label'] ?? $slug ) );
		}
		echo '</select></td></tr>';

		// Parameter override — only for software with "Multiple parameters" enabled.
		// Options reflect the last run; re-run after changing the software.
		$test_platform = $inputs['software'] ? $this->mappings->get( $inputs['software'] ) : null;
		$test_params   = ( $test_platform && ! empty( $test_platform['multi_param'] ) ) ? $this->mappings->params_for( $inputs['software'] ) : [];
		if ( count( $test_params ) > 1 ) {
			echo '<tr><th>' . esc_html__( 'Parameter', 'pretty-links-dv' ) . '</th><td><select name="param">';
			echo '<option value="">' . esc_html__( '— default —', 'pretty-links-dv' ) . '</option>';
			foreach ( $test_params as $opt ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $opt ), selected( $opt, $inputs['param'], false ), esc_html( $opt ) );
			}
			echo '</select></td></tr>';
		}

		$this->test_input( 'page', __( 'Simulated page', 'pretty-links-dv' ), (string) $inputs['page'], '', 'best-crypto-casinos' );
		$this->test_input( 'position', __( 'Simulated position', 'pretty-links-dv' ), (string) $inputs['position'], '', '1' );
		$this->test_input( 'operator', __( 'Simulated operator', 'pretty-links-dv' ), (string) $inputs['operator'], '', 'Thrill' );

		echo '<tr><th>' . esc_html__( 'Persist a test row', 'pretty-links-dv' ) . '</th><td><label><input type="checkbox" name="pldv_live" value="1"> ' . esc_html__( 'Insert as is_test=1 (excluded from reports)', 'pretty-links-dv' ) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Run test', 'pretty-links-dv' ), 'primary', 'pldv_test_submit' );
		echo '</form>';

		if ( $result ) {
			$this->render_test_result( $result, $live );
		}
	}

	private function render_test_result( array $r, bool $live ): void {
		echo '<h2>' . esc_html__( 'Result', 'pretty-links-dv' ) . ( $live ? ' — ' . esc_html__( 'test row inserted', 'pretty-links-dv' ) : ' — ' . esc_html__( 'dry run (nothing stored)', 'pretty-links-dv' ) ) . '</h2>';

		$status_colors = [ 'tracked' => 'green', 'no_software' => '#999', 'disabled' => '#999', 'no_mapping' => '#d63638', 'unsupported_value' => '#dba617' ];
		$color         = $status_colors[ $r['mapping_status'] ] ?? '#333';

		echo '<table class="widefat striped" style="max-width:900px;"><tbody>';
		$rows = [
			__( 'Mapping status', 'pretty-links-dv' ) => '<strong style="color:' . esc_attr( $color ) . '">' . esc_html( $r['mapping_status'] ) . '</strong>',
			__( 'Param sent', 'pretty-links-dv' )     => $r['param_sent'] ? '<code>' . esc_html( $r['param_sent'] ) . '</code>' : '—',
			__( 'Value sent (reconciles to this click)', 'pretty-links-dv' ) => ! empty( $r['sent_value'] ) ? '<code style="word-break:break-all;">' . esc_html( (string) $r['sent_value'] ) . '</code>' : '—',
			__( 'Click ID', 'pretty-links-dv' )       => '<code>' . esc_html( $r['click_id'] ) . '</code>',
			__( 'Token (to network)', 'pretty-links-dv' ) => '<code style="word-break:break-all;">' . esc_html( $r['token'] ) . '</code>',
			__( 'Encrypted', 'pretty-links-dv' )      => $r['encrypted'] ? esc_html__( 'yes', 'pretty-links-dv' ) : esc_html__( 'no', 'pretty-links-dv' ),
			__( 'Token length', 'pretty-links-dv' )   => esc_html( (string) $r['token_length'] ) . ( $r['token_length'] > 50 ? ' <span style="color:#dba617;">(' . esc_html__( 'some networks truncate long subids', 'pretty-links-dv' ) . ')</span>' : '' ),
			__( 'Decrypts back to', 'pretty-links-dv' ) => '<code>' . esc_html( (string) $r['decrypted'] ) . '</code>' . ( $r['decrypted'] === $r['click_id'] ? ' <span style="color:green;">✓ round-trip OK</span>' : ' <span style="color:#d63638;">✗</span>' ),
			__( 'Final outbound URL', 'pretty-links-dv' ) => '<code style="word-break:break-all;">' . esc_html( $r['final_url'] ) . '</code>',
		];
		foreach ( $rows as $k => $v ) {
			echo '<tr><th style="width:200px;">' . esc_html( $k ) . '</th><td>' . $v . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values escaped above.
		}
		echo '</tbody></table>';
	}

	/* ------------------------------------------------------------------ */
	/* Reports                                                             */
	/* ------------------------------------------------------------------ */

	private function render_reports(): void {
		if ( ! $this->reports->table_ready() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The clicks table is not present yet. Deactivate and reactivate the plugin to create it.', 'pretty-links-dv' ) . '</p></div>';
			return;
		}

		$filters = $this->read_report_filters();

		// Filter bar.
		echo '<form method="get" style="margin-bottom:16px;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="reports">';
		printf( '<input type="date" name="date_from" value="%s"> ', esc_attr( $filters['date_from'] ) );
		printf( '<input type="date" name="date_to" value="%s"> ', esc_attr( $filters['date_to'] ) );
		$this->filter_select( 'software', $filters['software'] );
		$this->filter_select( 'mapping_status', $filters['mapping_status'] );
		$this->filter_select( 'operator', $filters['operator'] );
		submit_button( __( 'Filter', 'pretty-links-dv' ), 'secondary', '', false );
		echo ' <a class="button" href="' . esc_url( $this->csv_url( $filters ) ) . '">' . esc_html__( 'Export CSV', 'pretty-links-dv' ) . '</a>';
		echo '</form>';

		$total = $this->reports->total( $filters );
		echo '<h2>' . esc_html( sprintf( /* translators: %s: count */ __( '%s clicks', 'pretty-links-dv' ), number_format_i18n( $total ) ) ) . '</h2>';

		// Coverage + dimensional tables side by side.
		echo '<div style="display:flex;gap:24px;flex-wrap:wrap;">';
		$this->dimension_table( __( 'DV coverage', 'pretty-links-dv' ), 'mapping_status', $filters );
		$this->dimension_table( __( 'By link', 'pretty-links-dv' ), 'link_slug', $filters );
		$this->dimension_table( __( 'By page', 'pretty-links-dv' ), 'page', $filters );
		$this->dimension_table( __( 'By list position', 'pretty-links-dv' ), 'clicked_position', $filters );
		$this->dimension_table( __( 'By operator', 'pretty-links-dv' ), 'operator', $filters );
		echo '</div>';

		// Recent rows.
		echo '<h2>' . esc_html__( 'Recent clicks', 'pretty-links-dv' ) . '</h2>';
		$rows = $this->reports->recent( $filters, 25, 1 );
		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		foreach ( [ 'Time', 'Link', 'Software', 'Status', 'Param', 'Page', 'Pos', 'Operator' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row->created_at ) . '</td>';
			echo '<td><code>' . esc_html( (string) $row->link_slug ) . '</code></td>';
			echo '<td>' . esc_html( (string) $row->software ) . '</td>';
			echo '<td>' . esc_html( (string) $row->mapping_status ) . '</td>';
			echo '<td>' . esc_html( (string) $row->param_sent ) . '</td>';
			echo '<td>' . esc_html( (string) $row->page ) . '</td>';
			echo '<td>' . esc_html( (string) $row->clicked_position ) . '</td>';
			echo '<td>' . esc_html( (string) $row->operator ) . '</td>';
			echo '</tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No clicks recorded yet.', 'pretty-links-dv' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function read_report_filters(): array {
		return [
			'date_from'      => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'        => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
			'software'       => isset( $_GET['software'] ) ? sanitize_text_field( wp_unslash( $_GET['software'] ) ) : '',
			'mapping_status' => isset( $_GET['mapping_status'] ) ? sanitize_text_field( wp_unslash( $_GET['mapping_status'] ) ) : '',
			'operator'       => isset( $_GET['operator'] ) ? sanitize_text_field( wp_unslash( $_GET['operator'] ) ) : '',
		];
	}

	private function dimension_table( string $title, string $dimension, array $filters ): void {
		$data = $this->reports->by_dimension( $dimension, $filters, 15 );
		echo '<div style="min-width:260px;flex:1;"><h3>' . esc_html( $title ) . '</h3>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( $data as $d ) {
			$label = ( null === $d->label || '' === $d->label ) ? '—' : $d->label;
			echo '<tr><td>' . esc_html( $label ) . '</td><td style="text-align:right;">' . esc_html( number_format_i18n( $d->clicks ) ) . '</td></tr>';
		}
		if ( ! $data ) {
			echo '<tr><td>' . esc_html__( 'No data', 'pretty-links-dv' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function filter_select( string $dimension, string $current ): void {
		$values = $this->reports->distinct( $dimension );
		echo '<select name="' . esc_attr( $dimension ) . '"><option value="">' . esc_html( sprintf( /* translators: %s: dimension */ __( 'All %s', 'pretty-links-dv' ), str_replace( '_', ' ', $dimension ) ) ) . '</option>';
		foreach ( $values as $v ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $v, $current, false ), esc_html( $v ) );
		}
		echo '</select> ';
	}

	private function csv_url( array $filters ): string {
		return wp_nonce_url(
			add_query_arg(
				array_merge( [ 'action' => 'pldv_export_csv' ], array_filter( $filters ) ),
				admin_url( 'admin-post.php' )
			),
			'pldv_export_csv'
		);
	}

	public function export_csv(): void {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'pldv_export_csv' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pretty-links-dv' ) );
		}
		$filters = $this->read_report_filters();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=pldv-clicks-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out     = fopen( 'php://output', 'w' );
		$columns = [ 'id', 'created_at', 'link_slug', 'software', 'mapping_status', 'param_sent', 'sent_value', 'page', 'clicked_position', 'original_order', 'operator', 'placement', 'context', 'click_id', 'token', 'target_url' ];
		fputcsv( $out, $columns );

		// Stream in pages so a large export never silently truncates (the old code
		// asked for 5000 but recent() clamps to 200) and never loads the whole
		// table into memory. Hard ceiling guards against a runaway export.
		$per_page  = 1000;
		$page      = 1;
		$max_pages = 1000; // up to 1,000,000 rows.
		do {
			$rows = $this->reports->recent( $filters, $per_page, $page );
			foreach ( $rows as $row ) {
				$line = [];
				foreach ( $columns as $c ) {
					$line[] = $row->$c ?? '';
				}
				fputcsv( $out, $line );
			}
			$page++;
		} while ( count( $rows ) === $per_page && $page <= $max_pages );

		fclose( $out );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Small render helpers                                                */
	/* ------------------------------------------------------------------ */

	private function pretty_links( int $limit ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'prli_links';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, slug, name, url FROM {$table} WHERE link_status = 'enabled' ORDER BY id DESC LIMIT %d",
			$limit
		) ) ?: [];
	}

	private function text_row( string $label, string $name, string $value, string $placeholder ): void {
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="%s" value="%s" placeholder="%s" class="regular-text"></td></tr>',
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
	}

	private function test_input( string $name, string $label, string $value, string $id, string $placeholder ): void {
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="%s" id="%s" value="%s" placeholder="%s" class="regular-text" style="max-width:520px;"></td></tr>',
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $id ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
	}

	private function checkbox_row( string $label, string $name, bool $checked, string $desc ): void {
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s" value="1" %s> %s</label></td></tr>',
			esc_html( $label ),
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $desc )
		);
	}

	private function select_row( string $label, string $name, array $options, string $current ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $val => $text ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $val, $current, false ), esc_html( $text ) );
		}
		echo '</select></td></tr>';
	}
}
