<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin tables and core tables are read directly on purpose: scans and results are one-off, large and must not fill the object cache.
/**
 * Scan results: one row per unused media, kept in a dedicated table.
 *
 * A table (instead of one huge option) keeps a page of 20 rows fast on a
 * site with tens of thousands of results. The table is removed on uninstall.
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

class OLIMC_Results {

	const DB_VERSION = '1';

	/**
	 * @return string Table name.
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'olimc_results';
	}

	/**
	 * Create or update the table.
	 */
	public static function install() {
		global $wpdb;
		if ( get_option( 'olimc_db_version' ) === self::DB_VERSION && self::exists() ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			scan_id bigint(20) unsigned NOT NULL,
			attachment_id bigint(20) unsigned NOT NULL,
			title varchar(255) NOT NULL DEFAULT '',
			file varchar(255) NOT NULL DEFAULT '',
			mime varchar(100) NOT NULL DEFAULT '',
			size bigint(20) unsigned NOT NULL DEFAULT 0,
			uploaded datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (scan_id,attachment_id),
			KEY scan_size (scan_id,size),
			KEY scan_uploaded (scan_id,uploaded)
			) {$charset};"
		);
		update_option( 'olimc_db_version', self::DB_VERSION, false );
	}

	/**
	 * @return bool
	 */
	public static function exists() {
		global $wpdb;
		$table = self::table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Drop the table (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table name is built from $wpdb->prefix.
	}

	/**
	 * Add rows of a scan that is still running (not shown until published).
	 *
	 * @param int     $scan_id Scan ID.
	 * @param array[] $rows    Rows from OLIMC_Scanner::get_result_row().
	 */
	public static function add_rows( $scan_id, array $rows ) {
		global $wpdb;
		$scan_id = (int) $scan_id;
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$values = [];
			foreach ( $chunk as $r ) {
				$title    = function_exists( 'mb_substr' ) ? mb_substr( (string) $r['title'], 0, 250 ) : substr( (string) $r['title'], 0, 250 );
				$values[] = $wpdb->prepare(
					'(%d,%d,%s,%s,%s,%d,%s)',
					$scan_id,
					(int) $r['id'],
					$title,
					substr( (string) $r['file'], 0, 250 ),
					substr( (string) $r['mime'], 0, 100 ),
					(int) $r['size'],
					$r['uploaded'] ? $r['uploaded'] : '0000-00-00 00:00:00'
				);
			}
			if ( $values ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- values are prepared above.
				$wpdb->query( 'REPLACE INTO ' . self::table() . ' (scan_id,attachment_id,title,file,mime,size,uploaded) VALUES ' . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
	}

	/**
	 * Show a finished scan and drop every other one.
	 *
	 * @param int $scan_id Scan ID.
	 * @param array $extra Extra info stored with the scan (signature).
	 */
	public static function publish( $scan_id, array $extra = [] ) {
		global $wpdb;
		$scan_id = (int) $scan_id;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE scan_id <> %d', $scan_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
		$info = array_merge(
			[
				'id'    => $scan_id,
				'date'  => current_time( 'mysql' ),
				'time'  => time(),
				'count' => self::count( $scan_id ),
				'bytes' => self::total_size( $scan_id ),
			],
			$extra
		);
		update_option( 'olimc_scan_info', $info, false );
		update_option( 'olimc_scan_date', $info['date'], false );
	}

	/**
	 * Remove rows of a scan that did not finish.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public static function discard( $scan_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE scan_id = %d', (int) $scan_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	}

	/**
	 * Forget the published list (a new scan is required).
	 */
	public static function invalidate() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
		delete_option( 'olimc_scan_info' );
		delete_option( 'olimc_scan_date' );
	}

	/**
	 * Remove a media from every list (trashed, kept or deleted).
	 *
	 * @param int $id Attachment ID.
	 */
	public static function remove( $id ) {
		global $wpdb;
		if ( ! self::exists() ) {
			return;
		}
		$wpdb->delete( self::table(), [ 'attachment_id' => (int) $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$info = get_option( 'olimc_scan_info' );
		if ( is_array( $info ) && isset( $info['id'] ) ) {
			$info['count'] = self::count( (int) $info['id'] );
			$info['bytes'] = self::total_size( (int) $info['id'] );
			update_option( 'olimc_scan_info', $info, false );
		}
	}

	/**
	 * @return int Published scan ID, 0 when there is none.
	 */
	public static function published_id() {
		$info = get_option( 'olimc_scan_info' );
		return ( is_array( $info ) && ! empty( $info['id'] ) ) ? (int) $info['id'] : 0;
	}

	/**
	 * @param int $scan_id Scan ID.
	 * @return int
	 */
	public static function count( $scan_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE scan_id = %d', (int) $scan_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	}

	/**
	 * @param int $scan_id Scan ID.
	 * @return int Bytes.
	 */
	public static function total_size( $scan_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(size),0) FROM ' . self::table() . ' WHERE scan_id = %d', (int) $scan_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	}

	/**
	 * SQL for a type filter on a mime column. Static fragments, no user input.
	 *
	 * @param string $type all|images|documents|audio|video|other.
	 * @param string $col  Column expression.
	 * @return string Condition starting with AND, or an empty string.
	 */
	public static function type_sql( $type, $col = 'mime' ) {
		$docs = "{$col} LIKE 'application/%' OR {$col} LIKE 'text/%'";
		switch ( $type ) {
			case 'images':
				return " AND {$col} LIKE 'image/%'";
			case 'documents':
				return " AND ({$docs})";
			case 'audio':
				return " AND {$col} LIKE 'audio/%'";
			case 'video':
				return " AND {$col} LIKE 'video/%'";
			case 'other':
				return " AND {$col} NOT LIKE 'image/%' AND {$col} NOT LIKE 'audio/%' AND {$col} NOT LIKE 'video/%' AND NOT ({$docs})";
		}
		return '';
	}

	/**
	 * Minimum size in bytes for a size filter key.
	 *
	 * @param string $key 1|5|20 (MB) or empty.
	 * @return int
	 */
	public static function min_size( $key ) {
		$map = [
			'1'  => MB_IN_BYTES,
			'5'  => 5 * MB_IN_BYTES,
			'20' => 20 * MB_IN_BYTES,
		];
		return isset( $map[ $key ] ) ? $map[ $key ] : 0;
	}

	/**
	 * WHERE clause (without WHERE) for filters, prepared.
	 *
	 * @param int   $scan_id Scan ID.
	 * @param array $f       Filters: search, type, month (YYYY-MM), size.
	 * @return string
	 */
	private static function where( $scan_id, array $f ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'scan_id = %d', (int) $scan_id );
		if ( ! empty( $f['type'] ) ) {
			$sql .= self::type_sql( $f['type'] );
		}
		if ( ! empty( $f['month'] ) && preg_match( '/^(\d{4})-(\d{2})$/', $f['month'], $m ) ) {
			$sql .= $wpdb->prepare( ' AND uploaded >= %s AND uploaded < %s', $m[1] . '-' . $m[2] . '-01 00:00:00', gmdate( 'Y-m-d H:i:s', strtotime( $m[1] . '-' . $m[2] . '-01 +1 month' ) ) );
		}
		$min = self::min_size( isset( $f['size'] ) ? $f['size'] : '' );
		if ( $min > 0 ) {
			$sql .= $wpdb->prepare( ' AND size >= %d', $min );
		}
		if ( isset( $f['search'] ) && '' !== $f['search'] ) {
			$like = '%' . $wpdb->esc_like( $f['search'] ) . '%';
			$sql .= $wpdb->prepare( ' AND (title LIKE %s OR file LIKE %s)', $like, $like );
		}
		return $sql;
	}

	/**
	 * ORDER BY clause from a whitelist.
	 *
	 * @param string $orderby name|size|type|date.
	 * @param string $order   asc|desc.
	 * @return string
	 */
	private static function order_sql( $orderby, $order ) {
		$cols = [
			'name' => 'title',
			'size' => 'size',
			'type' => 'mime',
			'date' => 'uploaded',
		];
		$col = isset( $cols[ $orderby ] ) ? $cols[ $orderby ] : 'uploaded';
		$dir = ( 'asc' === strtolower( (string) $order ) ) ? 'ASC' : 'DESC';
		return " ORDER BY {$col} {$dir}, attachment_id {$dir}";
	}

	/**
	 * One page of results.
	 *
	 * @param int   $scan_id  Scan ID.
	 * @param array $filters  Filters (see where()).
	 * @param array $paging   orderby, order, per_page, page.
	 * @return array{rows: object[], total: int}
	 */
	public static function query( $scan_id, array $filters, array $paging ) {
		global $wpdb;
		$per_page = max( 1, min( 200, isset( $paging['per_page'] ) ? (int) $paging['per_page'] : 20 ) );
		$page     = max( 1, isset( $paging['page'] ) ? (int) $paging['page'] : 1 );
		$where    = self::where( $scan_id, $filters );
		$table    = self::table();
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where is built from prepared fragments.
		$order    = self::order_sql( isset( $paging['orderby'] ) ? $paging['orderby'] : 'date', isset( $paging['order'] ) ? $paging['order'] : 'desc' );
		$rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT attachment_id AS id, title, file, mime, size, uploaded FROM {$table} WHERE {$where} {$order} LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where and $order are built from prepared fragments and a column whitelist.
		);
		return [
			'rows'  => is_array( $rows ) ? $rows : [],
			'total' => $total,
		];
	}

	/**
	 * Every attachment ID that matches the filters (for "select all results").
	 *
	 * @param int   $scan_id Scan ID.
	 * @param array $filters Filters.
	 * @param array $paging  orderby and order.
	 * @return int[]
	 */
	public static function ids( $scan_id, array $filters, array $paging = [] ) {
		global $wpdb;
		$where = self::where( $scan_id, $filters );
		$order = self::order_sql( isset( $paging['orderby'] ) ? $paging['orderby'] : 'date', isset( $paging['order'] ) ? $paging['order'] : 'desc' );
		$table = self::table();
		$ids   = $wpdb->get_col( "SELECT attachment_id FROM {$table} WHERE {$where} {$order}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- built from prepared fragments.
		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	/**
	 * Distinct upload months present in the results, newest first.
	 *
	 * @param int $scan_id Scan ID.
	 * @return string[] YYYY-MM values.
	 */
	public static function months( $scan_id ) {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT DATE_FORMAT(uploaded,'%%Y-%%m') AS ym FROM " . self::table() . ' WHERE scan_id = %d ORDER BY ym DESC', (int) $scan_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
		return is_array( $rows ) ? $rows : [];
	}
}
