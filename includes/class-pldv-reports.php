<?php
/**
 * Reporting queries over the clicks table.
 *
 * Read-only, paginated, indexed. Excludes test rows by default. All inputs are
 * sanitized/whitelisted before reaching SQL; values go through $wpdb->prepare.
 *
 * @package PrettyLinksDV
 */

namespace PrettyLinksDV;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reports {

	/** Columns safe to GROUP BY / filter on (whitelist). */
	const DIMENSIONS = [
		'link_slug', 'software', 'mapping_status', 'page',
		'clicked_position', 'operator', 'placement', 'context',
	];

	/**
	 * Build the shared WHERE clause + prepared args from a filter set.
	 *
	 * @return array{0:string,1:array} [where_sql, args]
	 */
	private function where( array $filters ): array {
		$where = [ 'is_test = 0' ];
		$args  = [];

		if ( ! empty( $filters['date_from'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $filters['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $filters['date_to'] . ' 23:59:59';
		}
		foreach ( [ 'software', 'mapping_status', 'page', 'operator', 'link_slug' ] as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== $filters[ $key ] ) {
				$where[] = "{$key} = %s";
				$args[]  = $filters[ $key ];
			}
		}

		return [ implode( ' AND ', $where ), $args ];
	}

	/** Total matching clicks. */
	public function total( array $filters = [] ): int {
		global $wpdb;
		$table          = DB::table_name();
		list( $w, $a )  = $this->where( $filters );
		$sql            = "SELECT COUNT(*) FROM {$table} WHERE {$w}";
		$sql            = $a ? $wpdb->prepare( $sql, $a ) : $sql;
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Aggregate click counts grouped by a whitelisted dimension.
	 *
	 * @return array<int,object{label:string,clicks:int}>
	 */
	public function by_dimension( string $dimension, array $filters = [], int $limit = 50 ): array {
		if ( ! in_array( $dimension, self::DIMENSIONS, true ) ) {
			return [];
		}
		global $wpdb;
		$table         = DB::table_name();
		list( $w, $a ) = $this->where( $filters );
		$limit         = max( 1, min( 500, $limit ) );

		$sql  = "SELECT {$dimension} AS label, COUNT(*) AS clicks
			FROM {$table} WHERE {$w}
			GROUP BY {$dimension} ORDER BY clicks DESC LIMIT %d";
		$args = array_merge( $a, [ $limit ] );

		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) ?: [];
	}

	/** Coverage breakdown by mapping_status. */
	public function coverage( array $filters = [] ): array {
		return $this->by_dimension( 'mapping_status', $filters );
	}

	/**
	 * Recent click rows for the drill-down table.
	 */
	public function recent( array $filters = [], int $per_page = 25, int $page = 1 ): array {
		global $wpdb;
		$table         = DB::table_name();
		list( $w, $a ) = $this->where( $filters );
		// Ceiling of 1000 keeps a single query bounded while letting the CSV export
		// stream in 1000-row pages (the admin drill-down asks for far fewer).
		$per_page      = max( 1, min( 1000, $per_page ) );
		$offset        = max( 0, ( $page - 1 ) * $per_page );

		$sql  = "SELECT id, created_at, link_slug, software, mapping_status, param_sent, sent_value,
				page, clicked_position, original_order, operator, placement, context,
				click_id, token, target_url
			FROM {$table} WHERE {$w}
			ORDER BY id DESC LIMIT %d OFFSET %d";
		$args = array_merge( $a, [ $per_page, $offset ] );

		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) ?: [];
	}

	/** Distinct values for a dimension, for filter dropdowns. */
	public function distinct( string $dimension, int $limit = 200 ): array {
		if ( ! in_array( $dimension, self::DIMENSIONS, true ) ) {
			return [];
		}
		global $wpdb;
		$table = DB::table_name();
		$limit = max( 1, min( 500, $limit ) );
		$sql   = "SELECT DISTINCT {$dimension} AS v FROM {$table}
			WHERE is_test = 0 AND {$dimension} IS NOT NULL AND {$dimension} <> ''
			ORDER BY v ASC LIMIT %d";
		return $wpdb->get_col( $wpdb->prepare( $sql, $limit ) ) ?: [];
	}

	/** Whether the clicks table exists yet. */
	public function table_ready(): bool {
		global $wpdb;
		$table = DB::table_name();
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
