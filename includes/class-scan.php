<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin tables and core tables are read directly on purpose: scans and results are one-off, large and must not fill the object cache.
/**
 * Scan controller: start, step, resume, cancel and publish, shared by the
 * screen (AJAX) and by the scheduled event. The engine lives in
 * OLIMC_Acf_Meta_Scanner; this class keeps the state, the lock and the
 * results table in step with it.
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

class OLIMC_Scan {

	const STEPS         = 5;
	const STALE_DAYS    = 14;
	const SCAN_CRON     = 'olimc_scheduled_cleanup';

	/**
	 * @param string $owner ajax|cron.
	 * @return string
	 */
	private static function owner( $owner ) {
		return ( 'cron' === $owner ) ? 'cron' : 'ajax';
	}

	/**
	 * Step label by number (1 to 5).
	 *
	 * @param int $n Step number.
	 * @return string
	 */
	public static function step_label( $n ) {
		switch ( (int) $n ) {
			case 1:
				return __( 'Preparing', 'oli-media-cleaner' );
			case 2:
				return __( 'Custom fields and ACF', 'oli-media-cleaner' );
			case 3:
				return __( 'Other metadata', 'oli-media-cleaner' );
			case 4:
				return __( 'Content, theme and widgets', 'oli-media-cleaner' );
		}
		return __( 'Sorting results', 'oli-media-cleaner' );
	}

	/**
	 * Stored state for an owner, or null.
	 *
	 * @param string $owner ajax|cron.
	 * @return array|null
	 */
	public static function state( $owner ) {
		$state = get_option( OLIMC_Acf_Meta_Scanner::state_option_key( self::owner( $owner ) ), [] );
		return ( is_array( $state ) && ! empty( $state['phase'] ) ) ? $state : null;
	}

	/**
	 * Public view of a state for the interface.
	 *
	 * @param array $state State.
	 * @return array
	 */
	public static function view( array $state ) {
		$engine  = new OLIMC_Acf_Meta_Scanner( new OLIMC_Scanner() );
		$done    = OLIMC_Acf_Meta_Scanner::may_mark_unused( $state );
		$failed  = ( isset( $state['phase'] ) && 'failed' === $state['phase'] ) || ! empty( $state['error'] );
		$percent = $done ? 100 : $engine->percent( $state );
		if ( $failed && isset( $state['failed_percent'] ) ) {
			$percent = (int) $state['failed_percent'];
		}
		$step  = $failed && isset( $state['failed_step'] ) ? (int) $state['failed_step'] : $engine->step_number( $state );
		$total = isset( $state['total'] ) ? (int) $state['total'] : 0;
		return [
			'done'    => $done,
			'failed'  => $failed,
			'percent' => $percent,
			'step'    => $step,
			'steps'   => self::STEPS,
			'checked' => (int) floor( $percent / 100 * $total ),
			'total'   => $total,
			'elapsed' => isset( $state['started'] ) ? max( 0, time() - (int) $state['started'] ) : 0,
			'detail'  => $failed && isset( $state['error'] ) ? (string) $state['error'] : '',
			'phase'   => isset( $state['failed_phase'] ) ? (string) $state['failed_phase'] : ( isset( $state['phase'] ) ? (string) $state['phase'] : '' ),
		];
	}

	/**
	 * Start a new scan (or start over).
	 *
	 * @param string $owner ajax|cron.
	 * @return array|WP_Error View of the first state, or an error with code lock|fail.
	 */
	public static function start( $owner ) {
		$owner = self::owner( $owner );
		if ( ! OLIMC_Acf_Meta_Scanner::acquire_lock( $owner ) ) {
			return new WP_Error( 'olimc_lock', 'locked' );
		}
		self::clear_owner( $owner );

		$engine = new OLIMC_Acf_Meta_Scanner( new OLIMC_Scanner() );
		$state  = $engine->initial_state();
		$state['scan_id'] = (int) ( time() . str_pad( (string) wp_rand( 0, 999 ), 3, '0', STR_PAD_LEFT ) );
		$state['started'] = time();
		OLIMC_Results::install();

		if ( 'failed' === $state['phase'] ) {
			self::abort( $owner, $state );
			return new WP_Error( 'olimc_fail', 'could not start' );
		}
		self::persist( $owner, $state );
		return self::view( $state );
	}

	/**
	 * Run one step for a time budget. Resumes a paused or failed scan.
	 *
	 * @param string $owner     ajax|cron.
	 * @param int    $budget_ms Milliseconds.
	 * @return array|WP_Error View, or an error with code lock|lost.
	 */
	public static function step( $owner, $budget_ms = 1500 ) {
		$owner = self::owner( $owner );
		if ( ! OLIMC_Acf_Meta_Scanner::acquire_lock( $owner ) ) {
			return new WP_Error( 'olimc_lock', 'locked' );
		}
		$state = self::state( $owner );
		if ( null === $state ) {
			OLIMC_Acf_Meta_Scanner::release_lock( $owner );
			return new WP_Error( 'olimc_lost', 'state lost' );
		}
		// Resume from the cursor of a scan that stopped on an error.
		if ( 'failed' === $state['phase'] && ! empty( $state['failed_phase'] ) ) {
			$state['phase'] = $state['failed_phase'];
			$state['error'] = '';
			unset( $state['failed_phase'], $state['failed_percent'], $state['failed_step'] );
		}

		$engine = new OLIMC_Acf_Meta_Scanner( new OLIMC_Scanner() );
		$before = $engine->percent( $state );
		$prev   = $state;
		$state  = $engine->step( $state, $budget_ms );

		if ( 'failed' === $state['phase'] || ! empty( $state['error'] ) ) {
			// Keep the cursor so the scan can resume; nothing is published.
			$state['failed_phase']   = isset( $state['failed_phase'] ) ? $state['failed_phase'] : ( isset( $prev['phase'] ) ? $prev['phase'] : '' );
			$state['failed_percent'] = $before;
			$state['failed_step']    = $engine->step_number( $prev );
			self::persist( $owner, $state );
			OLIMC_Acf_Meta_Scanner::release_lock( $owner );
			return self::view( $state );
		}

		// Monotone: never report less than before.
		self::persist( $owner, $state );
		if ( OLIMC_Acf_Meta_Scanner::may_mark_unused( $state ) ) {
			self::finish( $owner, $state );
			$view = self::view( $state );
			$view['done']    = true;
			$view['percent'] = 100;
			$view['count']   = (int) ( get_option( 'olimc_scan_info', [] )['count'] ?? 0 );
			$view['bytes']   = (int) ( get_option( 'olimc_scan_info', [] )['bytes'] ?? 0 );
			return $view;
		}
		$view = self::view( $state );
		if ( $view['percent'] < $before ) {
			$view['percent'] = $before;
		}
		return $view;
	}

	/**
	 * Cancel: free the lock, drop the partial state, keep the published list.
	 *
	 * @param string $owner ajax|cron.
	 */
	public static function cancel( $owner ) {
		$owner = self::owner( $owner );
		self::clear_owner( $owner );
		OLIMC_Acf_Meta_Scanner::release_lock( $owner );
	}

	/**
	 * Publish a finished scan.
	 *
	 * @param string $owner ajax|cron.
	 * @param array  $state Finished state.
	 */
	private static function finish( $owner, array $state ) {
		OLIMC_Results::publish( (int) $state['scan_id'], [ 'signature' => self::signature() ] );
		delete_option( OLIMC_Acf_Meta_Scanner::state_option_key( $owner ) );
		OLIMC_Acf_Meta_Scanner::release_lock( $owner );
	}

	/**
	 * Save progress and add the new unused rows to the results table (not shown yet).
	 *
	 * @param string $owner ajax|cron.
	 * @param array  $state State, passed by reference. The row delta is removed before saving.
	 */
	private static function persist( $owner, array &$state ) {
		$delta = isset( $state['pending_delta'] ) && is_array( $state['pending_delta'] ) ? $state['pending_delta'] : [];
		unset( $state['pending_delta'], $state['pending_unused'] );
		if ( [] !== $delta && ! empty( $state['scan_id'] ) ) {
			OLIMC_Results::add_rows( (int) $state['scan_id'], $delta );
		}
		update_option( OLIMC_Acf_Meta_Scanner::state_option_key( $owner ), $state, false );
		OLIMC_Acf_Meta_Scanner::touch_lock( $owner );
	}

	/**
	 * Drop the state of an owner and the rows of its unfinished scan.
	 *
	 * @param string $owner ajax|cron.
	 */
	private static function clear_owner( $owner ) {
		$state = self::state( $owner );
		if ( $state && ! empty( $state['scan_id'] ) && (int) $state['scan_id'] !== OLIMC_Results::published_id() ) {
			OLIMC_Results::discard( (int) $state['scan_id'] );
		}
		delete_option( OLIMC_Acf_Meta_Scanner::state_option_key( $owner ) );
		delete_option( OLIMC_Acf_Meta_Scanner::pending_option_key( $owner ) );
		delete_option( 'olimc_scan_state' );
	}

	/**
	 * Failed start: nothing is published.
	 *
	 * @param string $owner ajax|cron.
	 * @param array  $state State.
	 */
	private static function abort( $owner, array $state ) {
		if ( ! empty( $state['scan_id'] ) ) {
			OLIMC_Results::discard( (int) $state['scan_id'] );
		}
		self::clear_owner( $owner );
		OLIMC_Acf_Meta_Scanner::release_lock( $owner );
	}

	/**
	 * Remove every runtime option (deactivation, uninstall).
	 */
	public static function clear_runtime() {
		foreach ( [ 'ajax', 'cron' ] as $owner ) {
			delete_option( OLIMC_Acf_Meta_Scanner::state_option_key( $owner ) );
			delete_option( OLIMC_Acf_Meta_Scanner::pending_option_key( $owner ) );
		}
		delete_option( 'olimc_scan_state' );
		delete_option( OLIMC_Acf_Meta_Scanner::LOCK_OPTION );
	}

	/**
	 * Scheduled event: only refreshes the list. Nothing is ever moved to the trash here.
	 */
	public static function run_scheduled() {
		$state = self::state( 'cron' );
		if ( null === $state || 'done' === $state['phase'] ) {
			$started = self::start( 'cron' );
			if ( is_wp_error( $started ) ) {
				self::reschedule_soon();
				return;
			}
		}
		$deadline = microtime( true ) + 20;
		do {
			$view = self::step( 'cron', 2000 );
			if ( is_wp_error( $view ) ) {
				self::reschedule_soon();
				return;
			}
			if ( $view['failed'] ) {
				self::cancel( 'cron' );
				return;
			}
			if ( $view['done'] ) {
				return;
			}
		} while ( microtime( true ) < $deadline );
		self::reschedule_soon();
	}

	/**
	 * Ask cron to continue soon, unless a run is already due shortly.
	 */
	private static function reschedule_soon() {
		$next = wp_next_scheduled( self::SCAN_CRON );
		if ( ! $next || $next > time() + 120 ) {
			wp_schedule_single_event( time() + 30, self::SCAN_CRON );
		}
	}

	/**
	 * Hash of what changes the answer of a scan: active plugins and their
	 * versions, the active theme, the number of media and the latest change.
	 *
	 * @return string
	 */
	public static function signature() {
		global $wpdb;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', [] );
		sort( $active );
		$parts = [];
		foreach ( $active as $file ) {
			$parts[] = $file . '@' . ( isset( $all[ $file ]['Version'] ) ? $all[ $file ]['Version'] : '' );
		}
		$theme = wp_get_theme();
		$row   = $wpdb->get_row( "SELECT COUNT(*) AS n, MAX(post_modified_gmt) AS m FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status != 'trash'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$parts[] = 'theme:' . get_stylesheet() . '@' . $theme->get( 'Version' );
		$parts[] = 'media:' . ( $row ? (int) $row->n : 0 ) . '@' . ( $row ? (string) $row->m : '' );
		return md5( implode( '|', $parts ) );
	}

	/**
	 * Accept the current state as the reference of the published list. Called
	 * after this plugin itself changed the media count (permanent deletion),
	 * only when the list was fresh before.
	 */
	public static function rebase_signature() {
		$info = get_option( 'olimc_scan_info' );
		if ( is_array( $info ) && ! empty( $info['id'] ) ) {
			$info['signature'] = self::signature();
			update_option( 'olimc_scan_info', $info, false );
		}
	}

	/**
	 * Whether the published list is stale: signature changed or older than 14 days.
	 *
	 * @return bool
	 */
	public static function is_stale() {
		$info = get_option( 'olimc_scan_info' );
		if ( ! is_array( $info ) || empty( $info['id'] ) ) {
			return false;
		}
		$age = time() - (int) ( isset( $info['time'] ) ? $info['time'] : 0 );
		if ( $age > self::STALE_DAYS * DAY_IN_SECONDS ) {
			return true;
		}
		return isset( $info['signature'] ) && self::signature() !== $info['signature'];
	}

	/**
	 * Sources read by the scan and sources detected but not read.
	 *
	 * @return array{read: string[], gaps: string[]}
	 */
	public static function coverage() {
		$read = [];
		if ( function_exists( 'acf_get_field_groups' ) || class_exists( 'ACF' ) ) {
			$read[] = 'ACF';
		}
		if ( class_exists( 'WooCommerce' ) ) {
			$read[] = 'WooCommerce';
		}
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$read[] = 'Elementor';
		}
		$read[] = __( 'theme files', 'oli-media-cleaner' );
		$read[] = __( 'widgets', 'oli-media-cleaner' );

		$known = apply_filters(
			'olimc_known_gaps',
			[
				'revslider'           => 'Slider Revolution',
				'LayerSlider'         => 'LayerSlider',
				'smart-slider-3'      => 'Smart Slider 3',
				'nextgen-gallery'     => 'NextGEN Gallery',
				'envira-gallery-lite' => 'Envira Gallery',
			]
		);
		$gaps = [];
		if ( is_array( $known ) ) {
			foreach ( (array) get_option( 'active_plugins', [] ) as $file ) {
				$slug = dirname( (string) $file );
				if ( isset( $known[ $slug ] ) && is_string( $known[ $slug ] ) ) {
					$gaps[] = $known[ $slug ];
				}
			}
		}
		return [
			'read' => $read,
			'gaps' => array_values( array_unique( $gaps ) ),
		];
	}
}
