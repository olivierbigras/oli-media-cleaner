<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin tables and core tables are read directly on purpose: scans and results are one-off, large and must not fill the object cache.
/**
 * AJAX routes. Every route checks the capability and the nonce `olimc_nonce`;
 * routes that act on media also check the capability on each media.
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

class OLIMC_Ajax {

	const MAX_IDS = 20000;

	public static function init() {
		$routes = [
			'scan_start',
			'scan_step',
			'scan_cancel',
			'bulk_prepare',
			'trash',
			'restore',
			'delete',
			'keep',
			'unkeep',
			'empty',
			'test_patterns',
			'dismiss',
		];
		foreach ( $routes as $route ) {
			add_action( 'wp_ajax_olimc_' . $route, [ __CLASS__, 'route_' . $route ] );
		}
	}

	/**
	 * Capability then nonce. Both failures answer 403.
	 */
	private static function guard() {
		if ( ! current_user_can( OLIMC_Admin::capability() ) ) {
			wp_send_json_error(
				[
					'code'    => 'cap',
					'message' => __( 'You do not have permission to clean the media library.', 'oli-media-cleaner' ),
				],
				403
			);
		}
		if ( ! check_ajax_referer( 'olimc_nonce', 'nonce', false ) ) {
			wp_send_json_error(
				[
					'code'    => 'nonce',
					'message' => __( 'Your session has expired. Reload the page and try again.', 'oli-media-cleaner' ),
				],
				403
			);
		}
	}

	/**
	 * Attachment IDs from the request.
	 *
	 * @return int[]
	 */
	private static function ids() {
		$raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(), cast to int below.
		if ( ! is_array( $raw ) ) {
			$raw = explode( ',', (string) $raw );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
		return array_slice( $ids, 0, self::MAX_IDS );
	}

	/**
	 * Refuse (400) a request that names anything but a media file, before anything is changed.
	 *
	 * @param int[] $ids IDs from the request.
	 */
	private static function require_media( array $ids ) {
		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) ) {
				wp_send_json_error(
					[
						'code'    => 'not_media',
						'message' => __( 'This is not a media file.', 'oli-media-cleaner' ),
					],
					400
				);
			}
		}
	}

	/**
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private static function name( $id ) {
		$title = get_the_title( $id );
		if ( '' === trim( (string) $title ) ) {
			$title = wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) );
		}
		return (string) $title;
	}

	public static function route_scan_start() {
		self::guard();
		$view = OLIMC_Scan::start( 'ajax' );
		if ( is_wp_error( $view ) ) {
			if ( 'olimc_lock' === $view->get_error_code() ) {
				wp_send_json_error(
					[
						'code'    => 'lock',
						'message' => __( 'Another scan is already running. Wait a few minutes, then try again.', 'oli-media-cleaner' ),
					],
					409
				);
			}
			wp_send_json_error( [ 'code' => 'fail' ], 500 );
		}
		wp_send_json_success( $view );
	}

	public static function route_scan_step() {
		self::guard();
		$budget = ( '1' === OLIMC_Settings::get( 'olimc_careful' ) ) ? 600 : 1500;
		/**
		 * Time budget of one scan step, in milliseconds.
		 *
		 * @param int $budget Milliseconds.
		 */
		$budget = max( 1, (int) apply_filters( 'olimc_scan_step_budget', $budget ) );
		$view   = OLIMC_Scan::step( 'ajax', $budget );
		if ( is_wp_error( $view ) ) {
			$code = 'olimc_lock' === $view->get_error_code() ? 'lock' : 'lost';
			wp_send_json_error(
				[
					'code'    => $code,
					'message' => 'lock' === $code
						? __( 'Another scan is already running. Wait a few minutes, then try again.', 'oli-media-cleaner' )
						: '',
				],
				'lock' === $code ? 409 : 410
			);
		}
		if ( ! empty( $view['done'] ) ) {
			$view['bytes_label'] = size_format( (int) $view['bytes'], 1 );
		}
		wp_send_json_success( $view );
	}

	public static function route_scan_cancel() {
		self::guard();
		OLIMC_Scan::cancel( 'ajax' );
		wp_send_json_success( [ 'cancelled' => true ] );
	}

	/**
	 * Summary for the confirmation dialog: count, size, five names with a preview.
	 * For "all results" it also returns the IDs of every result of the active filters.
	 */
	public static function route_bulk_prepare() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$tab   = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'review';
		$scope = isset( $_POST['scope'] ) && 'all' === sanitize_key( wp_unslash( $_POST['scope'] ) ) ? 'all' : 'ids';
		// phpcs:enable
		if ( ! in_array( $tab, [ 'review', 'kept', 'trash' ], true ) ) {
			$tab = 'review';
		}
		$ids = self::ids();
		if ( 'all' === $scope ) {
			$filters = self::posted_filters();
			if ( 'review' === $tab ) {
				$ids = OLIMC_Results::published_id() ? OLIMC_Results::ids( OLIMC_Results::published_id(), $filters ) : [];
			} else {
				$ids = OLIMC_Library::ids( $tab, $filters );
			}
		}
		$sample = [];
		foreach ( array_slice( $ids, 0, 5 ) as $id ) {
			$thumb    = wp_get_attachment_image_url( $id, [ 32, 32 ] );
			$sample[] = [
				'id'    => $id,
				'name'  => wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ),
				'thumb' => $thumb ? esc_url_raw( $thumb ) : '',
			];
		}
		$total = ( new OLIMC_Scanner() )->get_total_attachment_count();
		$data  = [
			'count'       => count( $ids ),
			'count_label' => number_format_i18n( count( $ids ) ),
			'size_label'  => size_format( OLIMC_Library::bytes( $ids ), 1 ),
			'sample'      => $sample,
			'more'        => max( 0, count( $ids ) - 5 ),
			'more_label'  => number_format_i18n( max( 0, count( $ids ) - 5 ) ),
			'big'         => count( $ids ) >= 100 || ( $total > 0 && count( $ids ) / $total >= 0.3 ) || 'all' === $scope,
		];
		if ( 'all' === $scope ) {
			$data['ids'] = $ids;
		}
		wp_send_json_success( $data );
	}

	/**
	 * Filters sent with an "all results" request (same keys as the URL).
	 *
	 * @return array
	 */
	private static function posted_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$size = isset( $_POST['size'] ) ? sanitize_key( wp_unslash( $_POST['size'] ) ) : '';
		$mon  = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
		$s    = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		// phpcs:enable
		return [
			'search' => $s,
			'type'   => in_array( $type, [ 'images', 'documents', 'audio', 'video', 'other' ], true ) ? $type : '',
			'month'  => preg_match( '/^\d{4}-\d{2}$/', $mon ) ? $mon : '',
			'size'   => in_array( $size, [ '1', '5', '20' ], true ) ? $size : '',
		];
	}

	/**
	 * Run $action on each ID and collect outcomes by code.
	 *
	 * @param int[]    $ids IDs.
	 * @param callable $actionReceives the ID, returns the result array of OLIMC_Trash.
	 * @return array{ok: int[], by: array<string,array>}
	 */
	private static function each( array $ids, $action ) {
		$out = [
			'ok' => [],
			'by' => [],
		];
		OLIMC_Scanner::begin_batch();
		foreach ( $ids as $id ) {
			$r = call_user_func( $action, $id );
			if ( ! empty( $r['ok'] ) ) {
				$out['ok'][] = $id;
			} else {
				$out['by'][ $r['code'] ][] = [
					'id'   => $id,
					'name' => self::name( $id ),
				];
			}
		}
		OLIMC_Scanner::end_batch();
		return $out;
	}

	public static function route_trash() {
		self::guard();
		$ids = self::ids();
		self::require_media( $ids );
		$res = self::each(
			$ids,
			static function ( $id ) {
				return OLIMC_Trash::trash( $id );
			}
		);
		wp_send_json_success(
			[
				'trashed' => $res['ok'],
				'used'    => isset( $res['by']['used'] ) ? $res['by']['used'] : [],
				'kept'    => isset( $res['by']['kept'] ) ? count( $res['by']['kept'] ) : 0,
				'cap'     => isset( $res['by']['cap'] ) ? count( $res['by']['cap'] ) : 0,
				'failed'  => self::failed_list( $res['by'], [ 'fail', 'invalid', 'already' ] ),
				'reason'  => self::reason(),
			]
		);
	}

	public static function route_restore() {
		self::guard();
		$ids = self::ids();
		self::require_media( $ids );
		$res = self::each(
			$ids,
			static function ( $id ) {
				return OLIMC_Trash::restore( $id );
			}
		);
		wp_send_json_success(
			[
				'restored' => $res['ok'],
				'cap'      => isset( $res['by']['cap'] ) ? count( $res['by']['cap'] ) : 0,
				'failed'   => self::failed_list( $res['by'], [ 'fail', 'not_trashed' ] ),
				'exists'   => isset( $res['by']['exists'] ) ? count( $res['by']['exists'] ) : 0,
			]
		);
	}

	/**
	 * Permanent deletion. A request that names any media outside the trash is
	 * refused (400) and deletes nothing.
	 */
	public static function route_delete() {
		self::guard();
		$ids = self::ids();
		self::require_media( $ids );
		foreach ( $ids as $id ) {
			if ( ! OLIMC_Trash::is_in_trash( $id ) ) {
				wp_send_json_error(
					[
						'code'    => 'not_trashed',
						'message' => __( 'Only files in the trash can be deleted permanently.', 'oli-media-cleaner' ),
					],
					400
				);
			}
		}
		$fresh = ! OLIMC_Scan::is_stale();
		$res   = self::each(
			$ids,
			static function ( $id ) {
				return OLIMC_Trash::purge( $id );
			}
		);
		if ( $fresh ) {
			OLIMC_Scan::rebase_signature();
		}
		wp_send_json_success(
			[
				'deleted' => $res['ok'],
				'cap'     => isset( $res['by']['cap'] ) ? count( $res['by']['cap'] ) : 0,
				'kept'    => isset( $res['by']['kept'] ) ? count( $res['by']['kept'] ) : 0,
				'failed'  => self::failed_list( $res['by'], [ 'used', 'fail' ] ),
				'reason'  => self::reason(),
			]
		);
	}

	/**
	 * Empty the trash, one batch: kept media are restored instead of deleted.
	 */
	public static function route_empty() {
		self::guard();
		$ids = self::ids();
		self::require_media( $ids );
		$fresh    = ! OLIMC_Scan::is_stale();
		$restored = 0;
		$deleted  = [];
		$failed   = [];
		$cap      = 0;
		OLIMC_Scanner::begin_batch();
		foreach ( $ids as $id ) {
			if ( ! OLIMC_Trash::is_in_trash( $id ) ) {
				continue;
			}
			if ( OLIMC_Admin::is_attachment_protected( $id ) ) {
				$r = OLIMC_Trash::restore( $id );
				if ( $r['ok'] ) {
					++$restored;
				}
				continue;
			}
			$r = OLIMC_Trash::purge( $id );
			if ( $r['ok'] ) {
				$deleted[] = $id;
			} elseif ( 'cap' === $r['code'] ) {
				++$cap;
			} else {
				$failed[] = [
					'id'   => $id,
					'name' => self::name( $id ),
				];
			}
		}
		OLIMC_Scanner::end_batch();
		if ( $fresh ) {
			OLIMC_Scan::rebase_signature();
		}
		wp_send_json_success(
			[
				'deleted'       => $deleted,
				'restored_kept' => $restored,
				'cap'           => $cap,
				'failed'        => $failed,
				'reason'        => self::reason(),
			]
		);
	}

	public static function route_keep() {
		self::guard();
		$kept = [];
		foreach ( self::ids() as $id ) {
			if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			update_post_meta( $id, '_olimc_protected', 1 );
			OLIMC_Results::remove( $id );
			$kept[] = $id;
		}
		wp_send_json_success( [ 'kept' => $kept ] );
	}

	public static function route_unkeep() {
		self::guard();
		$done = [];
		foreach ( self::ids() as $id ) {
			if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			delete_post_meta( $id, '_olimc_protected' );
			$done[] = $id;
		}
		wp_send_json_success( [ 'unkept' => $done ] );
	}

	/**
	 * "Test patterns": count per line without saving anything.
	 */
	public static function route_test_patterns() {
		self::guard();
		$raw = isset( $_POST['patterns'] ) ? sanitize_textarea_field( wp_unslash( $_POST['patterns'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$lines = OLIMC_Admin::parse_protected_path_option( $raw );
		$foreign = [];
		$valid   = [];
		foreach ( $lines as $line ) {
			if ( preg_match( '#^https?://#i', $line ) && ! OLIMC_Settings::is_own_url( $line ) ) {
				$foreign[] = $line;
				continue;
			}
			$valid[] = $line;
		}
		$res  = OLIMC_Library::test_patterns( $valid );
		$none = [];
		foreach ( $valid as $i => $line ) {
			if ( empty( $res['lines'][ $i ] ) ) {
				$none[] = $line;
			}
		}
		wp_send_json_success(
			[
				'total'       => (int) $res['total'],
				'total_label' => number_format_i18n( (int) $res['total'] ),
				'none'        => $none,
				'foreign'     => $foreign,
			]
		);
	}

	public static function route_dismiss() {
		self::guard();
		$which = isset( $_POST['which'] ) ? sanitize_key( wp_unslash( $_POST['which'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		if ( 'backup' === $which ) {
			update_user_meta( get_current_user_id(), 'olimc_backup_dismissed', 1 );
		} elseif ( 'migr' === $which ) {
			delete_option( 'olimc_migr_notice' );
		}
		wp_send_json_success( [ 'dismissed' => $which ] );
	}

	/**
	 * Failed items of the given codes.
	 *
	 * @param array    $by    Outcomes by code.
	 * @param string[] $codes Codes to include.
	 * @return array
	 */
	private static function failed_list( array $by, array $codes ) {
		$out = [];
		foreach ( $codes as $c ) {
			if ( isset( $by[ $c ] ) ) {
				$out = array_merge( $out, $by[ $c ] );
			}
		}
		return $out;
	}

	/**
	 * Reason shown after "N files could not be moved".
	 *
	 * @return string
	 */
	private static function reason() {
		$dir = wp_upload_dir( null, false );
		if ( ! empty( $dir['basedir'] ) && ! wp_is_writable( $dir['basedir'] ) ) {
			return __( 'The uploads folder is not writable.', 'oli-media-cleaner' );
		}
		return '';
	}
}
