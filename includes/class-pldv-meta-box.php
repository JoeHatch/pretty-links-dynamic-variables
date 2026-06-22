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

	const META_KEY = '_pldv_software';
	const NONCE    = 'pldv_save_meta';

	/** @var Mappings */
	private $mappings;

	public function __construct( Mappings $mappings ) {
		$this->mappings = $mappings;
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
				echo '</p>';
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
