<?php
/**
 * Lists of the Kept and Trash tabs, read from the media library itself.
 *
 * "Kept" = media flagged by the user (`_olimc_protected`) or matching a
 * protected path pattern. "Trash" = media flagged `_olimc_trashed` plus media
 * left in the former WordPress trash (status trash).
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

class OLIMC_Library {

	/**
	 * Kept media: ID => reason ('' for kept by the user, else the pattern that matched).
	 *
	 * @return array<int,string>
	 */
	public static function kept_map() {
		global $wpdb;
		$map = [];
		$ids = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_olimc_protected' AND meta_value = '1'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $ids as $id ) {
			$map[ (int) $id ] = '';
		}
		$patterns = OLIMC_Admin::get_protected_path_patterns();
		if ( [] !== $patterns ) {
			$upload   = wp_upload_dir( null, false );
			$base_url = isset( $upload['baseurl'] ) ? $upload['baseurl'] : '';
			$prepared = [];
			foreach ( $patterns as $raw ) {
				$norm = self::normalize( $raw, $base_url );
				if ( '' !== $norm ) {
					$prepared[ $raw ] = $norm;
				}
			}
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_wp_attached_file' AND p.post_type = 'attachment' AND p.post_status <> 'trash'"
			);
			foreach ( (array) $rows as $row ) {
				if ( isset( $map[ (int) $row->post_id ] ) ) {
					continue;
				}
				foreach ( $prepared as $raw => $norm ) {
					if ( OLIMC_Admin::path_matches_pattern( $row->meta_value, $norm ) ) {
						$map[ (int) $row->post_id ] = (string) $raw;
						break;
					}
				}
			}
		}
		return $map;
	}

	/**
	 * Same normalization as the scanner: URL to uploads-relative path.
	 *
	 * @param string $pattern  Pattern.
	 * @param string $base_url Uploads base URL.
	 * @return string
	 */
	public static function normalize( $pattern, $base_url ) {
		$pattern = trim( (string) $pattern );
		if ( '' === $pattern ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $pattern ) ) {
			if ( '' !== $base_url && 0 === strpos( $pattern, $base_url ) ) {
				$pattern = substr( $pattern, strlen( $base_url ) );
			} elseif ( preg_match( '#/wp-content/uploads/(.+)$#', $pattern, $m ) ) {
				$pattern = $m[1];
			}
		}
		return ltrim( $pattern, '/' );
	}

	/**
	 * How many media each pattern line matches (for "Test patterns").
	 *
	 * @param string[] $lines Pattern lines.
	 * @return array{total: int, lines: array<int,int>} Matches per line index, and distinct media.
	 */
	public static function test_patterns( array $lines ) {
		global $wpdb;
		$upload   = wp_upload_dir( null, false );
		$base_url = isset( $upload['baseurl'] ) ? $upload['baseurl'] : '';
		$rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_wp_attached_file' AND p.post_type = 'attachment' AND p.post_status <> 'trash'"
		);
		$per   = [];
		$ids   = [];
		foreach ( $lines as $i => $line ) {
			$per[ $i ] = 0;
			$norm      = self::normalize( $line, $base_url );
			if ( '' === $norm ) {
				continue;
			}
			foreach ( (array) $rows as $row ) {
				if ( OLIMC_Admin::path_matches_pattern( $row->meta_value, $norm ) ) {
					++$per[ $i ];
					$ids[ (int) $row->post_id ] = true;
				}
			}
		}
		return [
			'total' => count( $ids ),
			'lines' => $per,
		];
	}

	/**
	 * Rows of the Kept or Trash tab.
	 *
	 * @param string $tab     kept|trash.
	 * @param array  $filters search, type, month, size.
	 * @param array  $paging  orderby, order, per_page, page.
	 * @return array{rows: object[], total: int}
	 */
	public static function query( $tab, array $filters, array $paging ) {
		global $wpdb;
		$per_page = max( 1, min( 200, isset( $paging['per_page'] ) ? (int) $paging['per_page'] : 20 ) );
		$page     = max( 1, isset( $paging['page'] ) ? (int) $paging['page'] : 1 );
		$orderby  = isset( $paging['orderby'] ) ? $paging['orderby'] : 'date';
		$dir      = ( isset( $paging['order'] ) && 'asc' === strtolower( $paging['order'] ) ) ? 'ASC' : 'DESC';

		$kept = [];
		$join = " LEFT JOIN {$wpdb->postmeta} pf ON pf.post_id = p.ID AND pf.meta_key = '_wp_attached_file'";
		if ( 'kept' === $tab ) {
			$kept = self::kept_map();
			if ( [] === $kept ) {
				return [
					'rows'  => [],
					'total' => 0,
				];
			}
			$where = ' p.post_type = \'attachment\' AND p.post_status <> \'trash\' AND p.ID IN (' . implode( ',', array_map( 'intval', array_keys( $kept ) ) ) . ')'
				. " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} tt WHERE tt.post_id = p.ID AND tt.meta_key = '_olimc_trashed')";
			$extra = ', 0 AS trashed_at';
		} else {
			$join .= " LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_olimc_trashed'";
			$where = " p.post_type = 'attachment' AND (p.post_status = 'trash' OR t.meta_id IS NOT NULL)";
			$extra = ', t.meta_value AS trashed_at, p.post_status AS status';
		}
		$where .= OLIMC_Results::type_sql( isset( $filters['type'] ) ? $filters['type'] : '', 'p.post_mime_type' );
		if ( ! empty( $filters['month'] ) && preg_match( '/^(\d{4})-(\d{2})$/', $filters['month'], $m ) ) {
			$where .= $wpdb->prepare( ' AND p.post_date >= %s AND p.post_date < %s', $m[1] . '-' . $m[2] . '-01 00:00:00', gmdate( 'Y-m-d H:i:s', strtotime( $m[1] . '-' . $m[2] . '-01 +1 month' ) ) );
		}
		if ( isset( $filters['search'] ) && '' !== $filters['search'] ) {
			$like   = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where .= $wpdb->prepare( ' AND (p.post_title LIKE %s OR pf.meta_value LIKE %s)', $like, $like );
		}
		$cols = [
			'name' => 'p.post_title',
			'type' => 'p.post_mime_type',
			'date' => 'p.post_date',
		];
		if ( 'trash' === $tab && 'trashed' === $orderby ) {
			$order_sql = " ORDER BY CAST(t.meta_value AS UNSIGNED) {$dir}, p.ID {$dir}";
		} else {
			$col       = isset( $cols[ $orderby ] ) ? $cols[ $orderby ] : 'p.post_date';
			$order_sql = " ORDER BY {$col} {$dir}, p.ID {$dir}";
		}
		$select = "SELECT p.ID AS id, p.post_title AS title, pf.meta_value AS file, p.post_mime_type AS mime, p.post_date AS uploaded{$extra}";
		$from   = " FROM {$wpdb->posts} p{$join} WHERE {$where}";

		$min_size = OLIMC_Results::min_size( isset( $filters['size'] ) ? $filters['size'] : '' );
		$by_size  = ( 'size' === $orderby ) || $min_size > 0;
		$scanner  = new OLIMC_Scanner();

		if ( $by_size ) {
			$all = $wpdb->get_results( $select . $from . ' GROUP BY p.ID' . $order_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- fragments are built from prepared values and a column whitelist.
			$out = [];
			foreach ( (array) $all as $row ) {
				$row->size = (int) $scanner->calculate_total_size( (int) $row->id, (string) $row->mime );
				if ( $row->size >= $min_size ) {
					$out[] = $row;
				}
			}
			if ( 'size' === $orderby ) {
				usort(
					$out,
					static function ( $a, $b ) use ( $dir ) {
						return 'ASC' === $dir ? $a->size <=> $b->size : $b->size <=> $a->size;
					}
				);
			}
			$total = count( $out );
			$rows  = array_slice( $out, ( $page - 1 ) * $per_page, $per_page );
		} else {
			$total = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT p.ID)' . $from ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- fragments are built from prepared values.
			$rows  = $wpdb->get_results( $select . $from . ' GROUP BY p.ID' . $order_sql . $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, ( $page - 1 ) * $per_page ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- fragments are built from prepared values.
			foreach ( (array) $rows as $row ) {
				$row->size = (int) $scanner->calculate_total_size( (int) $row->id, (string) $row->mime );
			}
		}
		foreach ( (array) $rows as $row ) {
			$row->reason = isset( $kept[ (int) $row->id ] ) ? $kept[ (int) $row->id ] : '';
		}
		return [
			'rows'  => is_array( $rows ) ? $rows : [],
			'total' => $total,
		];
	}

	/**
	 * IDs of every row of a tab for a set of filters (select all results).
	 *
	 * @param string $tab     kept|trash.
	 * @param array  $filters Filters.
	 * @return int[]
	 */
	public static function ids( $tab, array $filters ) {
		$ids  = [];
		$page = 1;
		do {
			$res = self::query( $tab, $filters, [
				'per_page' => 200,
				'page' => $page,
			] );
			foreach ( $res['rows'] as $row ) {
				$ids[] = (int) $row->id;
			}
			++$page;
		} while ( count( $res['rows'] ) === 200 && $page < 500 );
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Size in bytes of a set of media.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return int
	 */
	public static function bytes( array $ids ) {
		$scanner = new OLIMC_Scanner();
		$sum     = 0;
		foreach ( $ids as $id ) {
			$sum += (int) $scanner->calculate_total_size( (int) $id );
		}
		return $sum;
	}
}
