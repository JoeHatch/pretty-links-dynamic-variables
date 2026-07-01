<?php
/**
 * Per-link software selection meta box on the pretty-link post type.
 *
 * Focused on picking the software (so links can be configured and tested);
 * the managed admin app (Reports / Settings / Mappings / Test) handles the
 * richer controls and the live URL preview.
 *
 * @package PrettyLinksDV
 */

namespace PrettyLinksDV;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meta_Box {

	const META_KEY   = '_pldv_software';
	const PARAM_META = '_pldv_param';
	const NONCE      = 'pldv_save_meta';

	/** @var Mappings */
	private $mappings;
	/** @var Settings */
	private $settings;

	public function __construct( Mappings $mappings, Settings $settings ) {
		$this->mappings = $mappings;
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add' ] );
		add_action( 'save_post', [ $this, 'save' ] );
	}

	public function add(): void {
		$screen = get_current_screen();
		if ( $screen && 'pretty-link' === $screen->post_type ) {
			add_meta_box(
				'pldv_software_config',
				__( 'Affiliate Program Software', 'pretty-links-dv' ),
				[ $this, 'render' ],
				'pretty-link',
				'side',
				'high'
			);
		}
	}

	public function render( $post ): void {
		wp_nonce_field( self::NONCE, 'pldv_nonce' );

		$selected = get_post_meta( $post->ID, self::META_KEY, true );

		echo '<select name="pldv_software" id="pldv-software-select" style="width:100%;">';
		echo '<option value="">' . esc_html__( '-- Select Software --', 'pretty-links-dv' ) . '</option>';

		foreach ( $this->mappings->all() as $slug => $platform ) {
			$label = isset( $platform['label'] ) && '' !== $platform['label']
				? $platform['label']
				: ucwords( str_replace( [ '_', '-' ], ' ', $slug ) );

			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $slug ),
				selected( $slug, $selected, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Select affiliate software for click ID tracking.', 'pretty-links-dv' ) . '</p>';

		// Show the token param / any constraint for the chosen platform.
		$platform = $selected ? $this->mappings->get( $selected ) : null;
		if ( $platform ) {
			$param = $platform['token_param'] ?? '';

			// Per-link parameter override for multi-param platforms (e.g. NetRefer
			// var1/var2/subid). Gated behind a setting (off by default) so editors
			// can't accidentally pick a wrong param. Options reflect the saved
			// software; changing the software and saving refreshes the list.
			$params = $selected ? $this->mappings->params_for( $selected ) : [];
			if ( $this->settings->param_override_enabled() && count( $params ) > 1 ) {
				$chosen = get_post_meta( $post->ID, self::PARAM_META, true );
				echo '<p style="margin-top:8px;"><label for="pldv-param-select" style="font-size:11px;color:#666;">'
					. esc_html__( 'Parameter for this link', 'pretty-links-dv' ) . '</label>';
				echo '<select name="pldv_param" id="pldv-param-select" style="width:100%;">';
				printf(
					'<option value="">%s</option>',
					/* translators: %s: default URL parameter name */
					esc_html( sprintf( __( 'Default (%s)', 'pretty-links-dv' ), $param ) )
				);
				foreach ( $params as $opt ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $opt ),
						selected( $opt, $chosen, false ),
						esc_html( $opt )
					);
				}
				echo '</select></p>';
			}

			if ( $param ) {
				echo '<p class="description" style="font-size:11px;color:#666;">';
				printf(
					/* translators: %s: URL parameter name */
					esc_html__( 'Token parameter: %s', 'pretty-links-dv' ),
					'<code>' . esc_html( $param ) . '</code>'
				);
				if ( ! empty( $platform['value_constraint']['type'] ) ) {
					echo ' — ' . esc_html( str_replace( '_', ' ', $platform['value_constraint']['type'] ) );
				}
				if ( ! empty( $platform['max_length'] ) ) {
					/* translators: %d: maximum character length */
					echo ' — ' . esc_html( sprintf( __( 'max %d chars', 'pretty-links-dv' ), (int) $platform['max_length'] ) );
				}
				echo '</p>';
			}

			if ( ! empty( $platform['notes'] ) ) {
				echo '<p class="description" style="font-size:11px;color:#666;">' . esc_html( $platform['notes'] ) . '</p>';
			}
		}
	}

	public function save( $post_id ): void {
		if ( ! $this->verify( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['pldv_software'] ) ) {
			return;
		}

		$software = sanitize_text_field( wp_unslash( $_POST['pldv_software'] ) );

		if ( '' !== $software && ! $this->mappings->get( $software ) ) {
			// Unknown slug submitted; ignore rather than persist garbage.
			return;
		}

		update_post_meta( $post_id, self::META_KEY, $software );

		// Per-link parameter override: only touched when the feature is enabled, so
		// disabling the setting hides the control without wiping saved overrides.
		// Keep the value only if it is one of the platform's allowed params.
		if ( $this->settings->param_override_enabled() ) {
			$param = isset( $_POST['pldv_param'] ) ? sanitize_text_field( wp_unslash( $_POST['pldv_param'] ) ) : '';
			if ( '' !== $param && '' !== $software && in_array( $param, $this->mappings->params_for( $software ), true ) ) {
				update_post_meta( $post_id, self::PARAM_META, $param );
			} else {
				delete_post_meta( $post_id, self::PARAM_META );
			}
		}
	}

	private function verify( $post_id ): bool {
		if ( ! isset( $_POST['pldv_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pldv_nonce'] ) ), self::NONCE ) ) {
			return false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		if ( 'pretty-link' !== get_post_type( $post_id ) ) {
			return false;
		}
		return true;
	}
}
