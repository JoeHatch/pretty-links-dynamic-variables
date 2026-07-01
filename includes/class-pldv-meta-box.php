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

		// Parameter picker + reference info are (re)built client-side so changing
		// the software updates the sub-parameters immediately, without a save.
		echo '<div id="pldv-param-wrap"></div>';
		echo '<div id="pldv-param-info"></div>';

		$chosen = get_post_meta( $post->ID, self::PARAM_META, true );
		$this->print_param_script( (string) $chosen );
	}

	/**
	 * Emit the per-software data map + the JS that rebuilds the parameter picker
	 * and reference lines whenever the software select changes (and on load).
	 */
	private function print_param_script( string $chosen ): void {
		$map = [];
		foreach ( $this->mappings->all() as $slug => $platform ) {
			$params = $this->mappings->params_for( $slug );
			$map[ $slug ] = [
				'default'    => (string) ( $platform['token_param'] ?? '' ),
				'params'     => $params,
				'multi'      => $this->mappings->multi_param_enabled( $slug ),
				'constraint' => ! empty( $platform['value_constraint']['type'] ) ? str_replace( '_', ' ', $platform['value_constraint']['type'] ) : '',
				'maxLength'  => ! empty( $platform['max_length'] ) ? (int) $platform['max_length'] : 0,
				'notes'      => (string) ( $platform['notes'] ?? '' ),
			];
		}

		$i18n = [
			'pickLabel'  => __( 'Parameter for this link', 'pretty-links-dv' ),
			'default'    => __( 'Default (%s)', 'pretty-links-dv' ),
			'tokenParam' => __( 'Token parameter:', 'pretty-links-dv' ),
			'maxChars'   => __( 'max %d chars', 'pretty-links-dv' ),
		];
		?>
		<script>
		( function () {
			var MAP    = <?php echo wp_json_encode( $map ); ?>;
			var I18N   = <?php echo wp_json_encode( $i18n ); ?>;
			var SAVED  = <?php echo wp_json_encode( $chosen ); ?>;
			var select = document.getElementById( 'pldv-software-select' );
			var wrap   = document.getElementById( 'pldv-param-wrap' );
			var info   = document.getElementById( 'pldv-param-info' );
			if ( ! select || ! wrap || ! info ) { return; }

			function descP() {
				var p = document.createElement( 'p' );
				p.className = 'description';
				p.style.cssText = 'font-size:11px;color:#666;';
				return p;
			}

			function render( keepSaved ) {
				wrap.textContent = '';
				info.textContent = '';
				var d = MAP[ select.value ];
				if ( ! d ) { return; }

				if ( d.multi && d.params.length > 1 ) {
					var p = document.createElement( 'p' );
					p.style.marginTop = '8px';
					var label = document.createElement( 'label' );
					label.setAttribute( 'for', 'pldv-param-select' );
					label.style.cssText = 'font-size:11px;color:#666;';
					label.textContent = I18N.pickLabel;
					p.appendChild( label );

					var sel = document.createElement( 'select' );
					sel.name = 'pldv_param';
					sel.id = 'pldv-param-select';
					sel.style.width = '100%';
					var def = document.createElement( 'option' );
					def.value = '';
					def.textContent = I18N.default.replace( '%s', d.default );
					sel.appendChild( def );
					d.params.forEach( function ( opt ) {
						var o = document.createElement( 'option' );
						o.value = opt;
						o.textContent = opt;
						if ( keepSaved && opt === SAVED ) { o.selected = true; }
						sel.appendChild( o );
					} );
					p.appendChild( sel );
					wrap.appendChild( p );
				}

				if ( d.default ) {
					var t = descP();
					t.appendChild( document.createTextNode( I18N.tokenParam + ' ' ) );
					var code = document.createElement( 'code' );
					code.textContent = d.default;
					t.appendChild( code );
					if ( d.constraint ) { t.appendChild( document.createTextNode( ' — ' + d.constraint ) ); }
					if ( d.maxLength ) { t.appendChild( document.createTextNode( ' — ' + I18N.maxChars.replace( '%d', d.maxLength ) ) ); }
					info.appendChild( t );
				}
				if ( d.notes ) {
					var n = descP();
					n.textContent = d.notes;
					info.appendChild( n );
				}
			}

			// Initial paint keeps the saved param; later changes reset to default.
			render( true );
			select.addEventListener( 'change', function () { render( false ); } );
		} )();
		</script>
		<?php
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

		// Per-link parameter override: only touched for software with "Multiple
		// parameters" enabled, so turning it off hides the control without wiping
		// saved overrides. Keep the value only if it is one of the allowed params.
		if ( '' !== $software && $this->mappings->multi_param_enabled( $software ) ) {
			$param = isset( $_POST['pldv_param'] ) ? sanitize_text_field( wp_unslash( $_POST['pldv_param'] ) ) : '';
			if ( '' !== $param && in_array( $param, $this->mappings->params_for( $software ), true ) ) {
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
