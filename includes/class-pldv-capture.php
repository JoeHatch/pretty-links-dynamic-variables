<?php
/**
 * Frontend capture layer.
 *
 * Enqueues the click-time capture script and passes it the configured param
 * prefix and Pretty Link path prefix. JS-primary (see assets/js/pldv-capture.js
 * for why): the listing is geo-reordered client-side, so the position a visitor
 * clicks from only exists in the live DOM.
 *
 * @package PrettyLinksDV
 */

namespace PrettyLinksDV;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capture {

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		if ( is_admin() || is_feed() ) {
			return;
		}

		$handle = 'pldv-capture';
		$src    = PLDV_URL . 'assets/js/pldv-capture.js';
		$ver    = defined( 'PLDV_VERSION' ) ? PLDV_VERSION : false;

		wp_register_script( $handle, $src, [], $ver, true );

		wp_localize_script(
			$handle,
			'PLDV_CAPTURE',
			[
				'prefix' => $this->settings->param_prefix(),
				'match'  => $this->settings->link_prefix(),
			]
		);

		wp_enqueue_script( $handle );
	}
}
