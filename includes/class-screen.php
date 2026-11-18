<?php
/**
 * The screen: Media > Media Cleaner, with four tabs that are real links.
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

class OLIMC_Screen {

	const PAGE = 'oli-media-cleaner';

	/** @var string Hook suffix of the page. */
	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_filter( 'set_screen_option_olimc_per_page', [ __CLASS__, 'save_per_page' ], 10, 3 );
		add_filter( 'plugin_action_links_' . OLIMC_BASENAME, [ __CLASS__, 'action_links' ] );
		add_action( 'admin_post_olimc_export', [ __CLASS__, 'export_csv' ] );
		add_action( 'admin_page_access_denied', [ __CLASS__, 'access_denied' ] );
		OLIMC_Settings::init();
	}

	/**
	 * Core refuses the page itself when the user cannot even open the Media menu
	 * (no upload_files); show the plugin message instead of the generic one.
	 */
	public static function access_denied() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compares the requested page slug; nothing is read or changed.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE === $page ) {
			wp_die( esc_html__( 'You do not have permission to clean the media library.', 'oli-media-cleaner' ), '', [ 'response' => 403 ] );
		}
	}

	/**
	 * Settings link only. The donation link lives in the readme and the plugin meta line.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( self::tab_url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'oli-media-cleaner' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Menu entry. The page is registered with the lowest capability so a user
	 * without the real one gets the 403 message of the plugin; the menu item
	 * itself is only shown to users who have the capability.
	 */
	public static function add_menu() {
		self::$hook = (string) add_submenu_page(
			'upload.php',
			__( 'Media Cleaner', 'oli-media-cleaner' ),
			__( 'Media Cleaner', 'oli-media-cleaner' ),
			'read',
			self::PAGE,
			[ __CLASS__, 'render' ]
		);
		if ( ! current_user_can( OLIMC_Admin::capability() ) ) {
			remove_submenu_page( 'upload.php', self::PAGE );
		}
		add_action( 'load-' . self::$hook, [ __CLASS__, 'on_load' ] );
	}

	public static function on_load() {
		if ( ! current_user_can( OLIMC_Admin::capability() ) ) {
			return;
		}
		if ( in_array( self::current_tab(), [ 'review', 'kept', 'trash' ], true ) ) {
			add_screen_option(
				'per_page',
				[
					'label'   => __( 'Items per page', 'oli-media-cleaner' ),
					'default' => 20,
					'option'  => 'olimc_per_page',
				]
			);
		}
	}

	/**
	 * @param mixed  $status Current value.
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return int
	 */
	public static function save_per_page( $status, $option, $value ) {
		unset( $status, $option );
		return max( 1, min( 200, (int) $value ) );
	}

	/**
	 * @param string $tab review|kept|trash|settings.
	 * @return string
	 */
	public static function tab_url( $tab ) {
		return add_query_arg(
			[
				'page' => self::PAGE,
				'tab'  => $tab,
			],
			admin_url( 'upload.php' )
		);
	}

	/**
	 * @return string review|kept|trash|settings.
	 */
	public static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'review'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab choice is a read-only GET parameter.
		return in_array( $tab, [ 'review', 'kept', 'trash', 'settings' ], true ) ? $tab : 'review';
	}

	/**
	 * Filters from the URL.
	 *
	 * @return array{search: string, type: string, month: string, size: string}
	 */
	public static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filters, shareable by URL.
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$size = isset( $_GET['size'] ) ? sanitize_key( wp_unslash( $_GET['size'] ) ) : '';
		$mon  = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';
		$s    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		return [
			'search' => $s,
			'type'   => in_array( $type, [ 'images', 'documents', 'audio', 'video', 'other' ], true ) ? $type : '',
			'month'  => preg_match( '/^\d{4}-\d{2}$/', $mon ) ? $mon : '',
			'size'   => in_array( $size, [ '1', '5', '20' ], true ) ? $size : '',
		];
	}

	/**
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue( $hook ) {
		if ( '' === self::$hook || $hook !== self::$hook || ! current_user_can( OLIMC_Admin::capability() ) ) {
			return;
		}
		wp_enqueue_style( 'olimc-admin', OLIMC_PLUGIN_URL . 'assets/css/admin.css', [], OLIMC_VERSION );
		wp_enqueue_script( 'olimc-admin', OLIMC_PLUGIN_URL . 'assets/js/admin.js', [ 'wp-i18n', 'wp-a11y' ], OLIMC_VERSION, true );
		wp_set_script_translations( 'olimc-admin', 'oli-media-cleaner', OLIMC_PLUGIN_DIR . 'languages' );
		$batch = OLIMC_Settings::batch();
		$data  = [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'olimc_nonce' ),
			'tab'        => self::current_tab(),
			'batch'      => $batch['batch'],
			'pause'      => $batch['pause'],
			'maxExec'    => (int) ini_get( 'max_execution_time' ),
			'bigCount'   => 100,
			'bigPercent' => 30,
			'locale'     => str_replace( '_', '-', determine_locale() ),
			'totalMedia' => ( new OLIMC_Scanner() )->get_total_attachment_count(),
			'stale'      => OLIMC_Scan::is_stale(),
			'filters'    => self::filters(),
			'hasDialog'  => true,
		];
		wp_add_inline_script( 'olimc-admin', 'window.olimcData = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Native notice. $html must already be escaped.
	 *
	 * @param string $type  success|warning|error|info.
	 * @param string $html  Escaped HTML.
	 * @param bool   $dismiss Add a dismiss button.
	 * @param string $extra Extra classes.
	 */
	private static function notice( $type, $html, $dismiss = false, $extra = '' ) {
		printf(
			'<div class="notice notice-%1$s inline%2$s%3$s"><p>%4$s</p></div>',
			esc_attr( $type ),
			$dismiss ? ' is-dismissible' : '',
			$extra ? ' ' . esc_attr( $extra ) : '',
			$html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
		);
	}

	public static function render() {
		if ( ! current_user_can( OLIMC_Admin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to clean the media library.', 'oli-media-cleaner' ), '', [ 'response' => 403 ] );
		}
		$tab = self::current_tab();
		echo '<div class="wrap olimc-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Media Cleaner', 'oli-media-cleaner' ) . '</h1><hr class="wp-header-end">';
		echo '<div id="olimc-notices">';
		self::render_standing_notices( $tab );
		echo '</div>';

		if ( 'settings' !== $tab ) {
			if ( 'review' === $tab ) {
				self::render_scanbar();
			}
			self::render_stats( $tab );
		}
		self::render_tabs( $tab );
		echo '<div id="olimc-main" class="olimc-main">';
		if ( 'settings' === $tab ) {
			self::render_settings();
		} else {
			self::render_list_tab( $tab );
		}
		echo '</div>';
		self::render_dialog();
		echo '</div>';
	}

	/**
	 * Backup notice (per account), migration notice, settings result.
	 *
	 * @param string $tab Current tab.
	 */
	private static function render_standing_notices( $tab ) {
		if ( 'settings' === $tab ) {
			self::render_settings_errors();
		}
		if ( ! get_user_meta( get_current_user_id(), 'olimc_backup_dismissed', true ) ) {
			echo '<div class="notice notice-warning inline is-dismissible olimc-dismissible" data-olimc-dismiss="backup"><p>' . esc_html__( 'Back up your site before cleaning. The trash keeps your files, but it is not a backup.', 'oli-media-cleaner' ) . '</p></div>';
		}
		if ( '1' === get_option( 'olimc_migr_notice' ) ) {
			echo '<div class="notice notice-info inline is-dismissible olimc-dismissible" data-olimc-dismiss="migr"><p>' . esc_html__( 'New in 2.0: the trash now sets files aside. Run a new scan.', 'oli-media-cleaner' ) . '</p></div>';
		}
	}

	/**
	 * Settings API messages: "saved" only when nothing failed.
	 */
	private static function render_settings_errors() {
		$errors = get_settings_errors( 'olimc_settings' );
		if ( ! $errors && isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$errors = [];
		}
		$has_error = false;
		foreach ( $errors as $e ) {
			if ( 'error' === $e['type'] ) {
				$has_error = true;
			}
		}
		foreach ( $errors as $e ) {
			if ( $has_error && 'olimc_saved' === $e['code'] ) {
				continue;
			}
			self::notice( 'error' === $e['type'] ? 'error' : 'success', $e['message'], true );
		}
	}

	private static function render_scanbar() {
		$state = OLIMC_Scan::state( 'ajax' );
		$info  = get_option( 'olimc_scan_info' );
		if ( null !== $state ) {
			return;
		}
		echo '<p class="olimc-scanbar"><button type="button" class="button button-primary button-large" id="olimc-scan-start">' . esc_html__( 'Start scan', 'oli-media-cleaner' ) . '</button> ';
		if ( is_array( $info ) && ! empty( $info['date'] ) ) {
			/* translators: %s: date and time of the last scan */
			echo '<span class="description olimc-last">' . esc_html( sprintf( __( 'Last scan: %s', 'oli-media-cleaner' ), mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $info['date'] ) ) ) . '</span>';
		} else {
			echo '<span class="description olimc-last">' . esc_html__( 'No scan yet', 'oli-media-cleaner' ) . '</span>';
		}
		echo '</p>';
		$cov = OLIMC_Scan::coverage();
		echo '<p class="olimc-sources description">';
		$names = array_map(
			static function ( $n, $i ) {
				return $i < 3 && in_array( $n, [ 'ACF', 'WooCommerce', 'Elementor' ], true ) ? '<strong>' . esc_html( $n ) . '</strong>' : esc_html( $n );
			},
			$cov['read'],
			array_keys( $cov['read'] )
		);
		/* translators: %s: list of the sources read by the scan */
		echo wp_kses( sprintf( esc_html__( 'Sources read: %s.', 'oli-media-cleaner' ), implode( ', ', $names ) ), [ 'strong' => [] ] );
		echo ' <button type="button" class="button-link olimc-sources-link" aria-expanded="false" aria-controls="olimc-sources-detail">' . esc_html__( 'See details', 'oli-media-cleaner' ) . '</button></p>';
		echo '<details id="olimc-sources-detail" class="olimc-sources-detail"><summary class="screen-reader-text" tabindex="-1" aria-hidden="true">' . esc_html__( 'See details', 'oli-media-cleaner' ) . '</summary><ul class="ul-disc">';
		foreach ( self::source_details() as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul></details>';
		if ( $cov['gaps'] ) {
			/* translators: %s: list of plugins whose media the scan cannot read */
			self::notice( 'warning', esc_html( sprintf( __( 'Detected but not read: %s. Media used only by this plugin may be reported as unused by mistake.', 'oli-media-cleaner' ), implode( ', ', $cov['gaps'] ) ) ) );
		}
	}

	/**
	 * What the scan reads, one line each.
	 *
	 * @return string[]
	 */
	private static function source_details() {
		return [
			__( 'Post content and excerpts', 'oli-media-cleaner' ),
			__( 'Featured images, site logo and site icon', 'oli-media-cleaner' ),
			__( 'Custom fields (ACF fields, galleries, repeaters, flexible content, clones, options pages) and other metadata', 'oli-media-cleaner' ),
			__( 'WooCommerce galleries and variations, Elementor data', 'oli-media-cleaner' ),
			__( 'Widgets, term thumbnails and term descriptions', 'oli-media-cleaner' ),
			__( 'Theme files and CSS', 'oli-media-cleaner' ),
		];
	}

	/**
	 * @param string $tab Current tab.
	 */
	private static function render_stats( $tab ) {
		$info   = get_option( 'olimc_scan_info' );
		$state  = OLIMC_Scan::state( 'ajax' );
		$total  = ( new OLIMC_Scanner() )->get_total_attachment_count();
		$trash  = OLIMC_Trash::count();
		$tbytes = '';
		if ( $trash > 0 && $trash <= 300 ) {
			$tbytes = ' (' . size_format( OLIMC_Library::bytes( OLIMC_Trash::all_ids() ), 1 ) . ')';
		}
		$has = is_array( $info ) && ! empty( $info['id'] ) && null === $state;
		echo '<ul class="olimc-stats">';
		echo '<li><b>' . esc_html( number_format_i18n( $total ) ) . '</b>' . esc_html__( 'Media', 'oli-media-cleaner' ) . '</li>';
		echo '<li><b>' . ( $has ? esc_html( number_format_i18n( (int) $info['count'] ) ) : '—' ) . '</b>' . esc_html__( 'Unused', 'oli-media-cleaner' ) . '</li>';
		echo '<li><b>' . ( $has ? esc_html( size_format( (int) $info['bytes'], 1 ) ) : '—' ) . '</b>' . esc_html__( 'Space to free', 'oli-media-cleaner' ) . '</li>';
		echo '<li><b>' . esc_html( number_format_i18n( $trash ) . $tbytes ) . '</b>' . esc_html__( 'In the trash', 'oli-media-cleaner' ) . '</li>';
		echo '</ul>';
		unset( $tab );
	}

	/**
	 * @param string $current Current tab.
	 */
	private static function render_tabs( $current ) {
		$info  = get_option( 'olimc_scan_info' );
		$state = OLIMC_Scan::state( 'ajax' );
		$counts = [
			'review'   => ( is_array( $info ) && ! empty( $info['count'] ) && null === $state ) ? (int) $info['count'] : 0,
			'kept'     => count( OLIMC_Library::kept_map() ),
			'trash'    => OLIMC_Trash::count(),
			'settings' => null,
		];
		$labels = [
			'review'   => __( 'To review', 'oli-media-cleaner' ),
			'kept'     => __( 'Kept', 'oli-media-cleaner' ),
			'trash'    => __( 'Trash', 'oli-media-cleaner' ),
			'settings' => __( 'Settings', 'oli-media-cleaner' ),
		];
		echo '<nav class="nav-tab-wrapper olimc-tabs" aria-label="' . esc_attr__( 'Media Cleaner sections', 'oli-media-cleaner' ) . '">';
		foreach ( $labels as $key => $label ) {
			$active = ( $key === $current );
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s%5$s</a>',
				esc_url( self::tab_url( $key ) ),
				$active ? ' nav-tab-active' : '',
				$active ? ' aria-current="page"' : '',
				esc_html( $label ),
				null === $counts[ $key ] ? '' : ' <span class="count">(' . esc_html( number_format_i18n( $counts[ $key ] ) ) . ')</span>'
			);
		}
		echo '</nav>';
	}

	/**
	 * Review, Kept and Trash tabs.
	 *
	 * @param string $tab review|kept|trash.
	 */
	private static function render_list_tab( $tab ) {
		$filters = self::filters();
		$state   = OLIMC_Scan::state( 'ajax' );

		if ( 'review' === $tab ) {
			if ( null !== $state ) {
				self::render_panel( $state );
				return;
			}
			$info = get_option( 'olimc_scan_info' );
			if ( ! is_array( $info ) || empty( $info['id'] ) ) {
				echo '<div class="olimc-empty"><h2 class="olimc-list-title" tabindex="-1">' . esc_html__( 'No scan yet.', 'oli-media-cleaner' ) . '</h2><p>' . esc_html__( 'Run a scan to find the media your site no longer uses. Nothing is erased during a scan.', 'oli-media-cleaner' ) . '</p></div>';
				return;
			}
			if ( 0 === (int) $info['count'] ) {
				echo '<div class="olimc-empty"><h2 class="olimc-list-title" tabindex="-1">' . esc_html__( 'No unused media.', 'oli-media-cleaner' ) . '</h2><p>' . esc_html__( 'Your media library is clean. Run a new scan after your next big change.', 'oli-media-cleaner' ) . '</p></div>';
				return;
			}
			if ( OLIMC_Scan::is_stale() ) {
				echo '<div id="olimc-stale" class="notice notice-warning inline"><p>' . esc_html__( 'This list predates your latest changes (plugin, theme or a scan older than 14 days). Run a new scan before moving files to the trash.', 'oli-media-cleaner' ) . '</p></div>';
			}
			$total = ( new OLIMC_Scanner() )->get_total_attachment_count();
			if ( $total > 0 && (int) $info['count'] / $total >= 0.3 ) {
				$pct = (int) round( (int) $info['count'] / $total * 100 );
				/* translators: %s: percentage of the media library reported as unused */
				self::notice( 'warning', esc_html( sprintf( __( '%s of your media are reported. This often means a source cannot be read. Check a few files before acting.', 'oli-media-cleaner' ), self::pct_text( $pct ) ) ) );
			}
		}

		if ( 'trash' === $tab ) {
			self::notice( 'info', esc_html__( 'These files are no longer served by your site and are not erased. A CDN or cache may keep serving a copy until it is purged. If your site still needs one, a file will be missing: restore it here at any time. Emptying the trash deletes them for good, so check your site first.', 'oli-media-cleaner' ), false, 'olimc-trash-note' );
		}

		require_once OLIMC_PLUGIN_DIR . 'includes/class-list-table.php';
		$table = new OLIMC_List_Table( $tab, $filters );
		$table->prepare_items();

		echo '<h2 class="screen-reader-text olimc-list-title" tabindex="-1">' . esc_html( self::tab_title( $tab ) ) . '</h2>';
		echo '<form id="olimc-filters" method="get" action="' . esc_url( admin_url( 'upload.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		echo '<p class="search-box"><label class="screen-reader-text" for="olimc-search">' . esc_html__( 'Search media', 'oli-media-cleaner' ) . '</label>';
		echo '<input type="search" id="olimc-search" name="s" value="' . esc_attr( $filters['search'] ) . '"> <input type="submit" class="button" value="' . esc_attr__( 'Search', 'oli-media-cleaner' ) . '"></p>';
		echo '</form>';

		echo '<div class="olimc-toolbar">';
		if ( 'trash' === $tab && $table->get_pagination_arg( 'total_items' ) > 0 ) {
			echo '<button type="button" class="button button-large olimc-btn" data-act="restore_all">' . esc_html__( 'Restore all', 'oli-media-cleaner' ) . '</button> ';
			echo '<button type="button" class="button button-large olimc-btn olimc-danger" data-act="empty">' . esc_html__( 'Empty the trash', 'oli-media-cleaner' ) . '</button>';
		}
		if ( $table->get_pagination_arg( 'total_items' ) > 0 ) {
			$csv = wp_nonce_url(
				add_query_arg(
					array_merge(
						[
							'action'  => 'olimc_export',
							'tab'     => $tab,
							'orderby' => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
							'order'   => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						],
						array_filter( array_diff_key( $filters, [ 'search' => 1 ] ), 'strlen' ),
						isset( $filters['search'] ) && '' !== $filters['search'] ? [ 's' => $filters['search'] ] : []
					),
					admin_url( 'admin-post.php' )
				),
				'olimc_export'
			);
			echo ' <a class="button olimc-btn olimc-export" href="' . esc_url( $csv ) . '">' . esc_html__( 'Export as CSV', 'oli-media-cleaner' ) . '</a>';
		}
		echo '</div>';

		echo '<div id="olimc-selbar" class="olimc-bulkbar" role="status" hidden></div>';
		echo '<form id="olimc-bulk" method="post" action="" data-tab="' . esc_attr( $tab ) . '" data-total="' . esc_attr( (string) (int) $table->get_pagination_arg( 'total_items' ) ) . '" data-stale="' . ( 'review' === $tab && OLIMC_Scan::is_stale() ? '1' : '0' ) . '">';
		$table->display();
		echo '</form>';
	}

	/**
	 * @param string $tab Tab.
	 * @return string
	 */
	private static function tab_title( $tab ) {
		if ( 'kept' === $tab ) {
			return __( 'Kept', 'oli-media-cleaner' );
		}
		if ( 'trash' === $tab ) {
			return __( 'Trash', 'oli-media-cleaner' );
		}
		return __( 'To review', 'oli-media-cleaner' );
	}

	/**
	 * A percentage in the language of the site (the French form puts a narrow no-break space before the sign).
	 *
	 * @param int $pct Whole percentage.
	 * @return string
	 */
	private static function pct_text( $pct ) {
		/* translators: %s: a number; the percent sign is part of the string, e.g. 42% */
		return sprintf( __( '%s%%', 'oli-media-cleaner' ), number_format_i18n( $pct ) );
	}

	/**
	 * Progress panel for a scan that exists on the server (paused or failed).
	 * A scan started in this page is driven by the script, which reuses this markup.
	 *
	 * @param array $state Scan state.
	 */
	private static function render_panel( array $state ) {
		$view   = OLIMC_Scan::view( $state );
		$mode   = $view['failed'] ? 'failed' : ( ! empty( $_GET['olimc_run'] ) ? 'running' : 'paused' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$pct    = (int) $view['percent'];
		$pct_t  = self::pct_text( $pct );
		$label  = OLIMC_Scan::step_label( $view['step'] );
		echo '<section class="olimc-panel" id="olimc-panel" data-mode="' . esc_attr( $mode ) . '" data-percent="' . esc_attr( (string) $pct ) . '" data-step="' . esc_attr( (string) $view['step'] ) . '" data-total="' . esc_attr( (string) $view['total'] ) . '" aria-labelledby="olimc-panel-title">';

		if ( 'failed' === $mode ) {
			/* translators: 1: percentage, 2: step number, 3: number of steps */
			$msg = sprintf( __( 'The scan stopped at %1$s (step %2$d of %3$d). No file was marked as unused.', 'oli-media-cleaner' ), $pct_t, $view['step'], OLIMC_Scan::STEPS );
			echo '<h2 id="olimc-panel-title" tabindex="-1" class="screen-reader-text">' . esc_html__( 'Scan stopped', 'oli-media-cleaner' ) . '</h2>';
			echo '<div class="notice notice-error inline"><p>' . esc_html( $msg ) . '</p></div>';
			echo '<p><button type="button" class="button button-primary button-large" id="olimc-scan-resume">' . esc_html__( 'Resume scan', 'oli-media-cleaner' ) . '</button> <button type="button" class="button button-large" id="olimc-scan-restart">' . esc_html__( 'Start over', 'oli-media-cleaner' ) . '</button></p>';
			echo '<details><summary>' . esc_html__( 'Technical details', 'oli-media-cleaner' ) . '</summary><pre class="olimc-mono">' . esc_html( $view['phase'] . ( '' !== $view['detail'] ? ' — ' . $view['detail'] : '' ) ) . '</pre></details>';
			echo '</section>';
			return;
		}

		echo '<h2 id="olimc-panel-title" tabindex="-1">' . ( 'paused' === $mode ? esc_html__( 'Scan paused', 'oli-media-cleaner' ) : esc_html__( 'Scan in progress', 'oli-media-cleaner' ) ) . '</h2>';
		if ( 'paused' === $mode ) {
			/* translators: %s: percentage reached when the scan paused */
			echo '<p id="olimc-paused">' . esc_html( sprintf( __( 'The scan is paused at %s. Resume it or start over.', 'oli-media-cleaner' ), $pct_t ) ) . '</p>';
			echo '<p><button type="button" class="button button-primary button-large" id="olimc-scan-resume">' . esc_html__( 'Resume scan', 'oli-media-cleaner' ) . '</button> <button type="button" class="button button-large" id="olimc-scan-restart">' . esc_html__( 'Start over', 'oli-media-cleaner' ) . '</button></p>';
			echo '</section>';
			return;
		}
		/* translators: 1: step number, 2: number of steps, 3: step name */
		echo '<p id="olimc-step">' . esc_html( sprintf( __( 'Step %1$d of %2$d: %3$s', 'oli-media-cleaner' ), $view['step'], OLIMC_Scan::STEPS, $label ) ) . '</p>';
		/* translators: 1: percentage, 2: step number, 3: number of steps */
		$text = sprintf( __( '%1$s, step %2$d of %3$d', 'oli-media-cleaner' ), $pct_t, $view['step'], OLIMC_Scan::STEPS );
		echo '<div class="olimc-bar" role="progressbar" id="olimc-bar" aria-label="' . esc_attr__( 'Scan progress', 'oli-media-cleaner' ) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $pct ) . '" aria-valuetext="' . esc_attr( $text ) . '"><span data-w="' . esc_attr( (string) $pct ) . '"></span></div>';
		echo '<p class="olimc-counter"><span id="olimc-count"></span> <span id="olimc-elapsed" class="olimc-elapsed"></span></p>';
		echo '<div id="olimc-live" class="screen-reader-text" role="status" aria-live="polite"></div>';
		echo '<ul class="olimc-steps" aria-hidden="true">';
		for ( $i = 1; $i <= OLIMC_Scan::STEPS; $i++ ) {
			$mark = $i < $view['step'] ? '✓' : ( $i === $view['step'] ? '●' : '○' );
			printf( '<li%s>%s %s</li>', $i === $view['step'] ? ' class="is-now"' : '', esc_html( $mark ), esc_html( OLIMC_Scan::step_label( $i ) ) );
		}
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'Nothing is marked as unused until the scan is finished.', 'oli-media-cleaner' ) . '<br>' . esc_html__( 'If you leave this page, the scan pauses. You can resume it later.', 'oli-media-cleaner' ) . '</p>';
		echo '<p><button type="button" class="button button-large" id="olimc-scan-cancel">' . esc_html__( 'Cancel scan', 'oli-media-cleaner' ) . '</button></p>';
		echo '</section>';
	}

	/**
	 * Empty modal dialog, filled and opened by the script.
	 */
	private static function render_dialog() {
		echo '<dialog class="olimc-dialog" id="olimc-dialog" aria-labelledby="olimc-dlg-title" aria-describedby="olimc-dlg-body">';
		echo '<h2 id="olimc-dlg-title"></h2><p id="olimc-dlg-body"></p><p id="olimc-dlg-size" hidden></p><ul id="olimc-dlg-list"></ul><p id="olimc-dlg-more" class="description"></p>';
		echo '<p id="olimc-dlg-ok" class="olimc-ok" hidden><label><input type="checkbox" id="olimc-dlg-check"> <span id="olimc-dlg-check-label"></span></label></p>';
		echo '<div class="olimc-dlg-actions"><button type="button" class="button button-large" id="olimc-dlg-cancel">' . esc_html__( 'Cancel', 'oli-media-cleaner' ) . '</button> <button type="button" class="button button-primary button-large" id="olimc-dlg-confirm"></button></div>';
		echo '</dialog>';
	}

	// ─── Settings tab ────────────────────────────────────────────────

	private static function render_settings() {
		$v = static function ( $k ) {
			return OLIMC_Settings::get( $k );
		};
		echo '<form method="post" action="options.php" id="olimc-settings-form">';
		settings_fields( OLIMC_Settings::GROUP );

		echo '<h2 class="title">' . esc_html__( 'Scan', 'oli-media-cleaner' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Attached media', 'oli-media-cleaner' ) . '</th><td><label><input type="checkbox" name="olimc_count_attached" value="1"' . checked( '1', $v( 'olimc_count_attached' ), false ) . '> ' . esc_html__( 'Count a file attached to a content item (“Uploaded to”) as used', 'oli-media-cleaner' ) . '</label><p class="description">' . esc_html__( 'Safer: some attached files are not displayed, but they will not be reported.', 'oli-media-cleaner' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Media types', 'oli-media-cleaner' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Media types', 'oli-media-cleaner' ) . '</legend>';
		echo '<label><input type="radio" name="olimc_types" value="all"' . checked( 'all', $v( 'olimc_types' ), false ) . '> ' . esc_html__( 'All types', 'oli-media-cleaner' ) . '</label><br>';
		echo '<label><input type="radio" name="olimc_types" value="images"' . checked( 'images', $v( 'olimc_types' ), false ) . '> ' . esc_html__( 'Images only', 'oli-media-cleaner' ) . '</label>';
		echo '</fieldset></td></tr></tbody></table>';

		echo '<h2 class="title">' . esc_html__( 'Keep list', 'oli-media-cleaner' ) . '</h2><table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="olimc-patterns">' . esc_html__( 'Protected paths', 'oli-media-cleaner' ) . '</label></th><td>';
		echo '<textarea name="olimc_protected_paths" id="olimc-patterns" rows="5" cols="50" class="large-text code" aria-describedby="olimc-patterns-help olimc-pattern-status" placeholder="2025/*/logo-*">' . esc_textarea( $v( 'olimc_protected_paths' ) ) . '</textarea>';
		echo '<p><button type="button" class="button button-large" id="olimc-patterns-test">' . esc_html__( 'Test patterns', 'oli-media-cleaner' ) . '</button> <span id="olimc-pattern-status" role="status"></span></p><ul id="olimc-pattern-lines" class="olimc-pattern-lines"></ul>';
		echo '<p class="description" id="olimc-patterns-help">' . esc_html__( 'One path, URL or pattern per line. * matches within one folder, ** across folders. Paths are relative to the uploads folder. Thumbnails and WebP/AVIF copies are protected with the original.', 'oli-media-cleaner' ) . '</p>';
		echo '</td></tr></tbody></table>';

		echo '<h2 class="title">' . esc_html__( 'Trash', 'oli-media-cleaner' ) . '</h2><table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Empty the trash automatically', 'oli-media-cleaner' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Empty the trash automatically', 'oli-media-cleaner' ) . '</legend>';
		$opts = [
			'0'  => __( 'Never, I will empty it myself', 'oli-media-cleaner' ),
			'30' => __( 'After 30 days', 'oli-media-cleaner' ),
			'90' => __( 'After 90 days', 'oli-media-cleaner' ),
		];
		foreach ( $opts as $val => $label ) {
			echo '<label><input type="radio" name="olimc_auto_empty" value="' . esc_attr( (string) $val ) . '"' . checked( (string) $val, $v( 'olimc_auto_empty' ), false ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		echo '</fieldset><p class="description">' . esc_html__( 'Automatic emptying permanently deletes files without asking again.', 'oli-media-cleaner' ) . '</p></td></tr></tbody></table>';

		echo '<h2 class="title">' . esc_html__( 'Scheduled scan', 'oli-media-cleaner' ) . '</h2>';
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			self::notice( 'warning', esc_html__( 'WP-Cron is disabled on this site. The scheduled scan depends on a server task.', 'oli-media-cleaner' ) );
		}
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Scan automatically on a schedule', 'oli-media-cleaner' ) . '</th><td><label><input type="checkbox" name="olimc_schedule_enabled" value="1"' . checked( '1', $v( 'olimc_schedule_enabled' ), false ) . '> ' . esc_html__( 'Scan automatically on a schedule', 'oli-media-cleaner' ) . '</label><p class="description">' . esc_html__( 'The scheduled scan only refreshes the list. Nothing is moved to the trash automatically.', 'oli-media-cleaner' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="olimc-freq">' . esc_html__( 'Frequency', 'oli-media-cleaner' ) . '</label></th><td><select name="olimc_schedule_frequency" id="olimc-freq">';
		$freqs = [
			'daily'      => __( 'Daily', 'oli-media-cleaner' ),
			'twicedaily' => __( 'Twice daily', 'oli-media-cleaner' ),
			'weekly'     => __( 'Weekly', 'oli-media-cleaner' ),
		];
		foreach ( $freqs as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $val, $v( 'olimc_schedule_frequency' ), false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		$next = wp_next_scheduled( OLIMC_Scan::SCAN_CRON );
		echo '<tr><th scope="row">' . esc_html__( 'Next run', 'oli-media-cleaner' ) . '</th><td>' . ( $next ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) ) : esc_html__( 'Not scheduled', 'oli-media-cleaner' ) ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<details class="olimc-advanced"><summary>' . esc_html__( 'Advanced', 'oli-media-cleaner' ) . '</summary><table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Advanced', 'oli-media-cleaner' ) . '</th><td><label><input type="checkbox" name="olimc_careful" value="1"' . checked( '1', $v( 'olimc_careful' ), false ) . '> ' . esc_html__( 'Careful mode (slow hosting): smaller batches and a short pause between requests', 'oli-media-cleaner' ) . '</label></td></tr></tbody></table></details>';

		echo '<h2 class="title">' . esc_html__( 'Data', 'oli-media-cleaner' ) . '</h2><table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Uninstall', 'oli-media-cleaner' ) . '</th><td><label><input type="checkbox" name="olimc_delete_data" value="1"' . checked( '1', $v( 'olimc_delete_data' ), false ) . '> ' . esc_html__( 'Delete the plugin data when it is uninstalled', 'oli-media-cleaner' ) . '</label><p class="description">' . esc_html__( 'Files in the trash are always restored to the media library first. They are never deleted. A file that cannot be restored stays in the uploads/olimc-trash folder.', 'oli-media-cleaner' ) . '</p></td></tr></tbody></table>';

		echo '<h2 class="title">' . esc_html__( 'Diagnostics', 'oli-media-cleaner' ) . '</h2>';
		echo '<p><label class="screen-reader-text" for="olimc-diag">' . esc_html__( 'Diagnostics', 'oli-media-cleaner' ) . '</label><textarea id="olimc-diag" readonly rows="8" cols="60" class="large-text code">' . esc_textarea( self::diagnostic_report() ) . '</textarea></p>';
		echo '<p><button type="button" class="button button-large" id="olimc-diag-copy">' . esc_html__( 'Copy report', 'oli-media-cleaner' ) . '</button> <span class="description">' . esc_html__( 'The report stays on your site until you copy it yourself.', 'oli-media-cleaner' ) . '</span></p>';
		echo '<p class="olimc-privacy description">' . esc_html__( 'This plugin sends no data outside your site.', 'oli-media-cleaner' ) . '</p>';
		echo '<p class="submit"><button type="submit" class="button button-primary button-large">' . esc_html__( 'Save settings', 'oli-media-cleaner' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Plain-text report for the administrator to copy. Never sent anywhere.
	 *
	 * @return string
	 */
	public static function diagnostic_report() {
		global $wp_version;
		$info  = get_option( 'olimc_scan_info' );
		$lines = [
			'Oli Media Cleaner ' . OLIMC_VERSION,
			'WordPress ' . $wp_version . ( is_multisite() ? ' (multisite)' : '' ),
			'PHP ' . PHP_VERSION . ', memory_limit ' . ini_get( 'memory_limit' ) . ', max_execution_time ' . ini_get( 'max_execution_time' ),
			'Theme: ' . get_stylesheet(),
			'Active plugins: ' . count( (array) get_option( 'active_plugins', [] ) ),
			'EMPTY_TRASH_DAYS: ' . ( defined( 'EMPTY_TRASH_DAYS' ) ? EMPTY_TRASH_DAYS : 'n/a' ),
			'DISABLE_WP_CRON: ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'yes' : 'no' ),
			'Uploads writable: ' . ( wp_is_writable( wp_upload_dir( null, false )['basedir'] ) ? 'yes' : 'no' ),
			'Trash folder writable: ' . ( OLIMC_Quarantine::is_writable() ? 'yes' : 'no' ),
			'Results table: ' . ( OLIMC_Results::exists() ? 'yes' : 'no' ),
			'Last scan: ' . ( is_array( $info ) && ! empty( $info['date'] ) ? $info['date'] . ', ' . (int) $info['count'] . ' unused' : 'none' ),
			'Media: ' . ( new OLIMC_Scanner() )->get_total_attachment_count() . ', trash: ' . OLIMC_Trash::count(),
		];
		return implode( "\n", $lines );
	}

	// ─── CSV export ──────────────────────────────────────────────────

	public static function export_csv() {
		if ( ! current_user_can( OLIMC_Admin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to clean the media library.', 'oli-media-cleaner' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'olimc_export' );
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'review';
		if ( ! in_array( $tab, [ 'review', 'kept', 'trash' ], true ) ) {
			$tab = 'review';
		}
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		if ( ! in_array( $orderby, [ 'name', 'size', 'type', 'date', 'trashed' ], true ) ) {
			$orderby = ( 'trash' === $tab ) ? 'trashed' : 'date';
		}
		$filters = self::filters();
		$status  = [
			'review' => __( 'To review', 'oli-media-cleaner' ),
			'kept'   => __( 'Kept', 'oli-media-cleaner' ),
			'trash'  => __( 'Trash', 'oli-media-cleaner' ),
		][ $tab ];

		$rows = [];
		$page = 1;
		do {
			$paging = [
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => 200,
				'page'     => $page,
			];
			if ( 'review' === $tab ) {
				$res = OLIMC_Results::published_id() ? OLIMC_Results::query( OLIMC_Results::published_id(), $filters, $paging ) : [ 'rows' => [] ];
			} else {
				$res = OLIMC_Library::query( $tab, $filters, $paging );
			}
			foreach ( $res['rows'] as $r ) {
				$rows[] = $r;
			}
			++$page;
		} while ( count( $res['rows'] ) === 200 && $page < 1000 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="oli-media-cleaner-' . wp_date( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		self::csv_row( $out, [ 'ID', __( 'Title', 'oli-media-cleaner' ), __( 'File name', 'oli-media-cleaner' ), 'URL', __( 'MIME type', 'oli-media-cleaner' ), __( 'Size (bytes)', 'oli-media-cleaner' ), __( 'Readable size', 'oli-media-cleaner' ), __( 'Upload date', 'oli-media-cleaner' ), __( 'Status', 'oli-media-cleaner' ) ] );
		foreach ( $rows as $r ) {
			$id = (int) $r->id;
			self::csv_row(
				$out,
				[
					$id,
					(string) $r->title,
					wp_basename( (string) $r->file ),
					(string) wp_get_attachment_url( $id ),
					(string) $r->mime,
					(int) $r->size,
					size_format( (int) $r->size, 1 ),
					(string) $r->uploaded,
					$status,
				]
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Cell safe from spreadsheet formulas: a leading = + - @ tab or CR gets an apostrophe.
	 *
	 * @param mixed $value Cell value.
	 * @return mixed
	 */
	public static function csv_cell( $value ) {
		if ( is_string( $value ) && '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * @param resource $out Output stream.
	 * @param array    $row Cells.
	 */
	private static function csv_row( $out, array $row ) {
		fputcsv( $out, array_map( [ __CLASS__, 'csv_cell' ], $row ), ',', '"', '\\' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
