<?php
/**
 * Settings (Settings API), defaults and the scheduled events they control.
 *
 * Defaults are cautious: nothing automatic, nothing destructive.
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

class OLIMC_Settings {

	const GROUP = 'olimc_settings';

	/**
	 * Defaults by option name.
	 *
	 * @return array<string,string>
	 */
	public static function defaults() {
		return [
			'olimc_count_attached'     => '0',
			'olimc_types'              => 'all',
			'olimc_protected_paths'    => '',
			'olimc_auto_empty'         => '0',
			'olimc_schedule_enabled'   => '0',
			'olimc_schedule_frequency' => 'daily',
			'olimc_careful'            => '0',
			'olimc_delete_data'        => '1',
		];
	}

	/**
	 * @param string $name Option name.
	 * @return string
	 */
	public static function get( $name ) {
		$defaults = self::defaults();
		$default  = isset( $defaults[ $name ] ) ? $defaults[ $name ] : '';
		$value    = get_option( $name, $default );
		return is_scalar( $value ) ? (string) $value : $default;
	}

	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'register' ] );
		foreach ( [ 'olimc_schedule_enabled', 'olimc_schedule_frequency', 'olimc_auto_empty' ] as $opt ) {
			add_action( 'update_option_' . $opt, [ __CLASS__, 'sync_schedule' ] );
			add_action( 'add_option_' . $opt, [ __CLASS__, 'sync_schedule' ] );
		}
	}

	public static function register() {
		$args = static function ( $cb, $default ) {
			return [
				'type'              => 'string',
				'sanitize_callback' => $cb,
				'default'           => $default,
				'show_in_rest'      => false,
			];
		};
		$d = self::defaults();
		register_setting( self::GROUP, 'olimc_count_attached', $args( [ __CLASS__, 'sanitize_bool' ], $d['olimc_count_attached'] ) );
		register_setting( self::GROUP, 'olimc_types', $args( [ __CLASS__, 'sanitize_types' ], $d['olimc_types'] ) );
		register_setting( self::GROUP, 'olimc_protected_paths', $args( [ __CLASS__, 'sanitize_paths' ], $d['olimc_protected_paths'] ) );
		register_setting( self::GROUP, 'olimc_auto_empty', $args( [ __CLASS__, 'sanitize_auto_empty' ], $d['olimc_auto_empty'] ) );
		register_setting( self::GROUP, 'olimc_schedule_enabled', $args( [ __CLASS__, 'sanitize_bool' ], $d['olimc_schedule_enabled'] ) );
		register_setting( self::GROUP, 'olimc_schedule_frequency', $args( [ __CLASS__, 'sanitize_frequency' ], $d['olimc_schedule_frequency'] ) );
		register_setting( self::GROUP, 'olimc_careful', $args( [ __CLASS__, 'sanitize_bool' ], $d['olimc_careful'] ) );
		register_setting( self::GROUP, 'olimc_delete_data', $args( [ __CLASS__, 'sanitize_bool' ], $d['olimc_delete_data'] ) );
	}

	/**
	 * Add the "saved" notice once per request.
	 */
	private static function saved_notice() {
		static $done = false;
		if ( ! $done ) {
			$done = true;
			add_settings_error( 'olimc_settings', 'olimc_saved', __( 'Settings saved.', 'oli-media-cleaner' ), 'success' );
		}
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string 1 or 0.
	 */
	public static function sanitize_bool( $value ) {
		self::saved_notice();
		return empty( $value ) ? '0' : '1';
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_types( $value ) {
		self::saved_notice();
		$value = is_string( $value ) ? sanitize_key( $value ) : '';
		if ( in_array( $value, [ 'all', 'images' ], true ) ) {
			return $value;
		}
		self::invalid_notice();
		return self::get( 'olimc_types' );
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string 0, 30 or 90.
	 */
	public static function sanitize_auto_empty( $value ) {
		self::saved_notice();
		$value = is_scalar( $value ) ? (string) absint( $value ) : '';
		if ( in_array( $value, [ '0', '30', '90' ], true ) ) {
			return $value;
		}
		self::invalid_notice();
		return self::get( 'olimc_auto_empty' );
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_frequency( $value ) {
		self::saved_notice();
		$value = is_string( $value ) ? sanitize_key( $value ) : '';
		if ( in_array( $value, [ 'daily', 'twicedaily', 'weekly' ], true ) ) {
			return $value;
		}
		self::invalid_notice();
		return self::get( 'olimc_schedule_frequency' );
	}

	/**
	 * One path, URL or pattern per line. A URL from another site is refused.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_paths( $value ) {
		self::saved_notice();
		if ( ! is_string( $value ) ) {
			self::invalid_notice();
			return self::get( 'olimc_protected_paths' );
		}
		$value = wp_unslash( $value );
		$keep  = [];
		foreach ( OLIMC_Admin::parse_protected_path_option( $value ) as $line ) {
			$line = sanitize_text_field( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '#^https?://#i', $line ) && ! self::is_own_url( $line ) ) {
				add_settings_error(
					'olimc_settings',
					'olimc_foreign_url',
					sprintf(
						/* translators: %s: the URL that was refused. */
						__( 'The address %s is not on this site and was not saved.', 'oli-media-cleaner' ),
						esc_html( $line )
					),
					'error'
				);
				continue;
			}
			$keep[] = $line;
		}
		return implode( "\n", array_values( array_unique( $keep ) ) );
	}

	/**
	 * @param string $url URL from a pattern line.
	 * @return bool Whether the host is the host of this site.
	 */
	public static function is_own_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$own  = wp_parse_url( home_url(), PHP_URL_HOST );
		$up   = wp_parse_url( (string) ( wp_upload_dir( null, false )['baseurl'] ?? '' ), PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return false;
		}
		return 0 === strcasecmp( $host, (string) $own ) || ( is_string( $up ) && 0 === strcasecmp( $host, $up ) );
	}

	private static function invalid_notice() {
		static $done = false;
		if ( ! $done ) {
			$done = true;
			add_settings_error( 'olimc_settings', 'olimc_invalid', __( 'One value was not valid. The previous value was kept.', 'oli-media-cleaner' ), 'error' );
		}
	}

	/**
	 * Create or remove the recurring events from the current settings.
	 */
	public static function sync_schedule() {
		wp_clear_scheduled_hook( OLIMC_Scan::SCAN_CRON );
		if ( '1' === self::get( 'olimc_schedule_enabled' ) ) {
			$freq = self::get( 'olimc_schedule_frequency' );
			if ( ! in_array( $freq, [ 'daily', 'twicedaily', 'weekly' ], true ) ) {
				$freq = 'daily';
			}
			wp_schedule_event( time() + HOUR_IN_SECONDS, $freq, OLIMC_Scan::SCAN_CRON );
		}
		wp_clear_scheduled_hook( 'olimc_auto_empty_trash' );
		if ( in_array( self::get( 'olimc_auto_empty' ), [ '30', '90' ], true ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'olimc_auto_empty_trash' );
		}
	}

	/**
	 * Batch size and pause for bulk actions, from the careful mode.
	 *
	 * @return array{batch: int, pause: int}
	 */
	public static function batch() {
		return ( '1' === self::get( 'olimc_careful' ) )
			? [
				'batch' => 10,
				'pause' => 500,
			]
			: [
				'batch' => 50,
				'pause' => 0,
			];
	}
}
