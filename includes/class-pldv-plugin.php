<?php
/**
 * Plugin orchestrator: wires up the components and registers runtime hooks.
 *
 * @package PrettyLinksDV
 */

namespace PrettyLinksDV;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	/** Cron hook that prunes click rows past the retention window. */
	const PRUNE_HOOK = 'pldv_prune_event';

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Settings */
	public $settings;

	/** @var Mappings */
	public $mappings;

	/** @var DB */
	public $db;

	/** @var Recorder */
	public $recorder;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		$this->settings = new Settings();
		$this->mappings = new Mappings();
		$this->db       = new DB();
		$this->recorder = new Recorder( $this->settings, $this->mappings, $this->db );

		// Run a deferred DB upgrade if the stored version is behind.
		$this->maybe_upgrade();

		// Core redirect interception: record every click, inject when mapped.
		$this->recorder->register();

		// Frontend: click-time capture of page / position / operator.
		( new Capture( $this->settings ) )->register();

		// Retention pruning (self-healing daily schedule + handler).
		$this->register_prune();

		// Admin: software meta box + the managed admin application.
		if ( is_admin() ) {
			( new Meta_Box( $this->mappings ) )->register();
			( new Admin( $this->settings, $this->mappings, $this->recorder ) )->register();
			add_action( 'admin_notices', [ $this, 'dependency_notice' ] );
		}
	}

	private function maybe_upgrade(): void {
		if ( get_option( 'pldv_db_version' ) !== PLDV_DB_VERSION ) {
			DB::install();
			update_option( 'pldv_db_version', PLDV_DB_VERSION );
		}
	}

	/**
	 * Ensure the daily prune event is scheduled and bind its handler. The schedule
	 * is self-healing: if it was lost, it is re-created on the next page load.
	 */
	private function register_prune(): void {
		add_action( self::PRUNE_HOOK, [ $this, 'prune' ] );
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Delete click rows older than the configured retention window. A value of 0
	 * means "keep forever" and prunes nothing.
	 */
	public function prune(): void {
		$days = (int) $this->settings->get( 'retention_days' );
		if ( $days <= 0 ) {
			return;
		}

		global $wpdb;
		$table  = DB::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Warn in the admin if Pretty Links is not active — without it the redirect
	 * filter never fires and no clicks are recorded.
	 */
	public function dependency_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( defined( 'PRLI_VERSION' ) || class_exists( 'PrliLink' ) || class_exists( '\PrliLink' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>Pretty Links Dynamic Variables:</strong> Pretty Links does not appear to be active. This add-on records and tags clicks via Pretty Links\' redirect — install and activate Pretty Links for it to do anything.</p></div>';
	}
}
