<?php
/**
 * Clicks data store: table creation (dbDelta) and inserts.
 *
 * One row per click. Recording never blocks a redirect — a DB failure degrades
 * to "redirect works, click unlogged".
 *
 * @package PrettyLinksDV
 */

namespace PrettyLinksDV;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB {

	const TABLE = 'pldv_clicks';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create or upgrade the clicks table via dbDelta.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace- and format-sensitive; keep two spaces after PRIMARY KEY.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			click_id CHAR(32) NOT NULL,
			token VARCHAR(255) NULL,
			link_id BIGINT UNSIGNED NULL,
			link_slug VARCHAR(190) NULL,
			software VARCHAR(64) NULL,
			mapping_status VARCHAR(20) NOT NULL DEFAULT 'no_software',
			param_sent VARCHAR(64) NULL,
			sent_value VARCHAR(255) NULL,
			page VARCHAR(190) NULL,
			clicked_position SMALLINT UNSIGNED NULL,
			original_order SMALLINT UNSIGNED NULL,
			placement VARCHAR(32) NULL,
			context VARCHAR(64) NULL,
			operator VARCHAR(64) NULL,
			target_url TEXT NULL,
			ip_hash CHAR(64) NULL,
			user_agent VARCHAR(255) NULL,
			is_test TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY click_id (click_id),
			KEY link_id (link_id),
			KEY sent_value (sent_value),
			KEY mapping_status (mapping_status),
			KEY page_position (page, clicked_position),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Insert a click row. Returns the insert id, or 0 on failure.
	 *
	 * @param array $data Column => value pairs (only known columns are used).
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$defaults = [
			'click_id'         => '',
			'token'            => null,
			'link_id'          => null,
			'link_slug'        => null,
			'software'         => null,
			'mapping_status'   => 'no_software',
			'param_sent'       => null,
			'sent_value'       => null,
			'page'             => null,
			'clicked_position' => null,
			'original_order'   => null,
			'placement'        => null,
			'context'          => null,
			'operator'         => null,
			'target_url'       => null,
			'ip_hash'          => null,
			'user_agent'       => null,
			'is_test'          => 0,
			'created_at'       => current_time( 'mysql' ),
		];

		$row = array_intersect_key( array_merge( $defaults, $data ), $defaults );

		// Never let a logging failure surface as a broken redirect.
		$ok = $wpdb->insert( self::table_name(), $row );

		return $ok ? (int) $wpdb->insert_id : 0;
	}
}
