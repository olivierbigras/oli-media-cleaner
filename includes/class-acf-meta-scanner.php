<?php
/**
 * Resumable ACF / meta attachment scan.
 *
 * Primary path (ACF active and field groups resolved): field names and keys come
 * from acf_get_field_groups() / acf_get_fields(), including repeater, flexible
 * content, group and clone. Values are loaded with indexed meta_key equality
 * queries in chunks — not a LIKE per row.
 *
 * Nested keys (content_blocks_4_image_carousel_images) are found from the ACF
 * reference row (_key => field_xxx) in primary-key ranges, then loaded with the
 * same indexed lookups.
 *
 * Fallback: if ACF is inactive or groups cannot be resolved, walk meta in
 * primary-key chunks and match attachment IDs in PHP. When ACF is active the
 * same chunked walk still runs (generic_* phases) so page builders, SEO plugins
 * and any other meta that stores an ID or upload URL count as used.
 *
 * Meta keys are bound with $wpdb->prepare(). Any key WordPress can store is
 * accepted, including hyphens (highlight-image). Only SQL identifiers such as
 * column names stay restricted to [A-Za-z0-9_].
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Step engine. State is a plain array so AJAX, cron and tests can resume it.
 */
class OLIMC_Acf_Meta_Scanner {

	const RANGE_SPAN = 5000;
	const KEY_CHUNK  = 40;
	const LOCK_TTL   = 180;
	const LOCK_OPTION = 'olimc_scan_lock';

	/**
	 * @var OLIMC_Scanner
	 */
	private $scanner;

	/**
	 * @param OLIMC_Scanner $scanner Scanner (attachment index + ACF field walk).
	 */
	public function __construct( OLIMC_Scanner $scanner ) {
		$this->scanner = $scanner;
	}

	/**
	 * Nothing may be flagged unused until every phase finished without error.
	 *
	 * @param array $state Scan state.
	 * @return bool
	 */
	public static function may_mark_unused( array $state ) {
		return isset( $state['phase'] ) && $state['phase'] === 'done' && empty( $state['error'] );
	}

	/**
	 * Option that stores resumable progress for one owner. AJAX and cron never share it.
	 *
	 * @param string $owner ajax|cron.
	 * @return string
	 */
	public static function state_option_key( $owner ) {
		return $owner === 'cron' ? 'olimc_scan_state_cron' : 'olimc_scan_state_ajax';
	}

	/**
	 * Option that accumulates unused-attachment rows for one owner.
	 *
	 * Kept out of the progress option so cron does not rewrite the full list every batch.
	 *
	 * @param string $owner ajax|cron.
	 * @return string
	 */
	public static function pending_option_key( $owner ) {
		return $owner === 'cron' ? 'olimc_scan_pending_cron' : 'olimc_scan_pending_ajax';
	}

	/**
	 * Take the scan lock, or refresh it when this owner already holds it.
	 *
	 * A lock older than LOCK_TTL can be taken over so a closed browser cannot block cron.
	 *
	 * @param string $owner ajax|cron.
	 * @return bool
	 */
	public static function acquire_lock( $owner ) {
		$owner = ( $owner === 'cron' ) ? 'cron' : 'ajax';
		$now   = time();
		$lock  = get_option( self::LOCK_OPTION, false );
		if ( self::lock_blocks( $lock, $owner, $now ) ) {
			return false;
		}
		$payload = [
			'owner' => $owner,
			'time'  => $now,
		];
		if ( false === $lock ) {
			if ( add_option( self::LOCK_OPTION, $payload, '', false ) ) {
				return true;
			}
			$lock = get_option( self::LOCK_OPTION, false );
			if ( self::lock_blocks( $lock, $owner, $now ) ) {
				return false;
			}
		}
		update_option( self::LOCK_OPTION, $payload, false );
		return true;
	}

	/**
	 * @param string $owner ajax|cron.
	 */
	public static function touch_lock( $owner ) {
		$owner = ( $owner === 'cron' ) ? 'cron' : 'ajax';
		$lock  = get_option( self::LOCK_OPTION, false );
		if ( ! is_array( $lock ) || ! isset( $lock['owner'] ) || $lock['owner'] !== $owner ) {
			return;
		}
		$lock['time'] = time();
		update_option( self::LOCK_OPTION, $lock, false );
	}

	/**
	 * @param string $owner ajax|cron.
	 */
	public static function release_lock( $owner ) {
		$owner = ( $owner === 'cron' ) ? 'cron' : 'ajax';
		$lock  = get_option( self::LOCK_OPTION, false );
		if ( is_array( $lock ) && isset( $lock['owner'] ) && $lock['owner'] === $owner ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * @param mixed  $lock  Stored lock.
	 * @param string $owner ajax|cron.
	 * @param int    $now   Unix time.
	 * @return bool
	 */
	private static function lock_blocks( $lock, $owner, $now ) {
		if ( ! is_array( $lock ) || empty( $lock['owner'] ) || $lock['owner'] === $owner ) {
			return false;
		}
		$age = $now - (int) ( isset( $lock['time'] ) ? $lock['time'] : 0 );
		return $age < self::LOCK_TTL;
	}

	/**
	 * @return array
	 */
	public function initial_state() {
		global $wpdb;

		$this->scanner->ensure_attachment_lookup();

		// Mark protected attachments as used immediately
		$protected = $this->scanner->get_protected_attachment_ids();
		$initial_used = [];
		foreach ($protected as $id) {
			$initial_used[(int) $id] = 1;
		}

		$targets = $this->scanner->get_acf_media_field_targets();
		$mode    = is_array( $targets ) ? 'acf' : 'fallback';

		$max_post   = $this->max_id( $wpdb->postmeta, 'meta_id' );
		$max_term   = isset( $wpdb->termmeta ) ? $this->max_id( $wpdb->termmeta, 'meta_id' ) : 0;
		$max_user   = $this->max_id( $wpdb->usermeta, 'umeta_id' );
		$max_option = $this->max_id( $wpdb->options, 'option_id' );

		$state = [
			'phase'             => ( $mode === 'acf' ) ? 'acf_in_post' : 'fallback_post',
			'mode'              => $mode,
			'cursor'            => 0,
			'names'             => is_array( $targets ) ? $targets['names'] : [],
			'keys'              => is_array( $targets ) ? $targets['keys'] : [],
			'discovered_post'   => [],
			'discovered_term'   => [],
			'discovered_user'   => [],
			'discovered_option' => [],
			'used'              => $initial_used,
			'pending_unused'    => [],
			'pending_delta'     => [],
			'max_post'          => $max_post,
			'max_term'          => $max_term,
			'max_user'          => $max_user,
			'max_option'        => $max_option,
			'error'             => '',
			'skip_auxiliary'    => false,
			'skip_classify'     => false,
		];

		if ( $this->db_failed() ) {
			$state['phase'] = 'failed';
			$state['error'] = 'Could not read meta table bounds.';
		}

		return $state;
	}

	/**
	 * Run as much work as fits in the time budget. Safe to call again with the returned state.
	 *
	 * @param array $state     Scan state.
	 * @param int   $budget_ms Milliseconds.
	 * @return array
	 */
	public function step( array $state, $budget_ms = 1500 ) {
		if ( ! isset( $state['phase'] ) || $state['phase'] === 'done' || $state['phase'] === 'failed' ) {
			return $state;
		}

		$deadline = microtime( true ) + ( max( 1, (int) $budget_ms ) / 1000 );
		$guard    = 0;
		do {
			$marker = $state['phase'] . ':' . (int) $state['cursor'];
			$this->step_once( $state );
			$guard++;
			if ( $state['phase'] === 'done' || $state['phase'] === 'failed' ) {
				break;
			}
			if ( $marker === $state['phase'] . ':' . (int) $state['cursor'] ) {
				$state['phase'] = 'failed';
				$state['error'] = 'Scan did not advance.';
				break;
			}
		} while ( microtime( true ) < $deadline && $guard < 100000 );

		return $state;
	}

	/**
	 * @param array $state Scan state.
	 * @return int 0-100
	 */
	public function percent( array $state ) {
		if ( ! empty( $state['error'] ) || ( isset( $state['phase'] ) && $state['phase'] === 'failed' ) ) {
			return 0;
		}
		if ( isset( $state['phase'] ) && $state['phase'] === 'done' ) {
			return 100;
		}
		$order = $this->phase_order( isset( $state['mode'] ) ? $state['mode'] : 'fallback' );
		$idx   = array_search( $state['phase'], $order, true );
		if ( $idx === false ) {
			return 0;
		}
		$frac = 0;
		$phase = (string) $state['phase'];
		if ( strpos( $phase, 'ref_' ) !== false || strpos( $phase, 'fallback_' ) !== false || strpos( $phase, 'generic_' ) !== false ) {
			$max  = max( 1, (int) $this->range_max_for_phase( $state ) );
			$frac = min( 1, (int) $state['cursor'] / $max );
		}
		return (int) min( 99, floor( ( ( $idx + $frac ) / count( $order ) ) * 100 ) );
	}

	/**
	 * @param array $state State, passed by reference.
	 */
	private function step_once( array &$state ) {
		switch ( $state['phase'] ) {
			case 'acf_in_post':
				$this->phase_indexed_values( $state, 'post', 'acf_in_term' );
				break;
			case 'acf_in_term':
				$this->phase_indexed_values( $state, 'term', 'acf_in_user' );
				break;
			case 'acf_in_user':
				$this->phase_indexed_values( $state, 'user', 'acf_in_option' );
				break;
			case 'acf_in_option':
				$this->phase_indexed_values( $state, 'option', 'acf_ref_post' );
				break;
			case 'acf_ref_post':
				$this->phase_reference_range( $state, 'post', 'acf_ref_term' );
				break;
			case 'acf_ref_term':
				$this->phase_reference_range( $state, 'term', 'acf_ref_user' );
				break;
			case 'acf_ref_user':
				$this->phase_reference_range( $state, 'user', 'acf_ref_option' );
				break;
			case 'acf_ref_option':
				$this->phase_reference_range( $state, 'option', 'acf_val_post' );
				break;
			case 'acf_val_post':
				$this->phase_indexed_values( $state, 'post', 'acf_val_term', true );
				break;
			case 'acf_val_term':
				$this->phase_indexed_values( $state, 'term', 'acf_val_user', true );
				break;
			case 'acf_val_user':
				$this->phase_indexed_values( $state, 'user', 'acf_val_option', true );
				break;
			case 'acf_val_option':
				$this->phase_indexed_values( $state, 'option', 'generic_post', true );
				break;
			case 'generic_post':
				$this->phase_fallback_range( $state, 'post', 'generic_term' );
				break;
			case 'generic_term':
				$this->phase_fallback_range( $state, 'term', 'generic_user' );
				break;
			case 'generic_user':
				$this->phase_fallback_range( $state, 'user', 'generic_option' );
				break;
			case 'generic_option':
				$this->phase_fallback_range( $state, 'option', 'auxiliary' );
				break;
			case 'fallback_post':
				$this->phase_fallback_range( $state, 'post', 'fallback_term' );
				break;
			case 'fallback_term':
				$this->phase_fallback_range( $state, 'term', 'fallback_user' );
				break;
			case 'fallback_user':
				$this->phase_fallback_range( $state, 'user', 'fallback_option' );
				break;
			case 'fallback_option':
				$this->phase_fallback_range( $state, 'option', 'auxiliary' );
				break;
			case 'auxiliary':
				$this->phase_auxiliary( $state );
				break;
			case 'classify':
				$this->phase_classify( $state );
				break;
			default:
				$state['phase'] = 'failed';
				$state['error'] = 'Unknown scan phase.';
		}
	}

	/**
	 * @param string $mode acf|fallback.
	 * @return string[]
	 */
	private function phase_order( $mode ) {
		if ( $mode === 'acf' ) {
			return [
				'acf_in_post',
				'acf_in_term',
				'acf_in_user',
				'acf_in_option',
				'acf_ref_post',
				'acf_ref_term',
				'acf_ref_user',
				'acf_ref_option',
				'acf_val_post',
				'acf_val_term',
				'acf_val_user',
				'acf_val_option',
				'generic_post',
				'generic_term',
				'generic_user',
				'generic_option',
				'auxiliary',
				'classify',
			];
		}
		return [
			'fallback_post',
			'fallback_term',
			'fallback_user',
			'fallback_option',
			'auxiliary',
			'classify',
		];
	}

	/**
	 * Indexed lookup of known meta keys (field names, or discovered nested keys).
	 *
	 * @param array  $state    State.
	 * @param string $which    post|term|user|option.
	 * @param string $next     Next phase.
	 * @param bool   $discovered Use discovered nested keys instead of field names.
	 */
	private function phase_indexed_values( array &$state, $which, $next, $discovered = false ) {
		$spec = $this->table_spec( $which );
		if ( $spec === null ) {
			$this->advance( $state, $next );
			return;
		}

		if ( $discovered ) {
			$bucket = 'discovered_' . $which;
			$keys   = array_keys( isset( $state[ $bucket ] ) && is_array( $state[ $bucket ] ) ? $state[ $bucket ] : [] );
		} else {
			$keys = $this->value_keys_for_table( $state, $which );
		}

		$keys = array_values( array_filter( $keys, [ $this, 'is_bindable_meta_key' ] ) );
		$total = count( $keys );
		$cursor = (int) $state['cursor'];
		if ( $cursor >= $total ) {
			$this->advance( $state, $next );
			return;
		}

		$chunk = array_slice( $keys, $cursor, self::KEY_CHUNK );
		$rows  = $this->fetch_values_for_keys( $spec['table'], $spec['key'], $spec['value'], $chunk );
		if ( $rows === null ) {
			$this->fail( $state, 'Meta key lookup failed.' );
			return;
		}
		$this->absorb_values( $state, $rows );
		$state['cursor'] = $cursor + count( $chunk );
		if ( $state['cursor'] >= $total ) {
			$this->advance( $state, $next );
		}
	}

	/**
	 * Primary-key ranges. Keep rows whose value is a known ACF field key (the _field reference).
	 *
	 * @param array  $state State.
	 * @param string $which post|term|user|option.
	 * @param string $next  Next phase.
	 */
	private function phase_reference_range( array &$state, $which, $next ) {
		$keyset = [];
		foreach ( $state['keys'] as $field_key ) {
			if ( is_string( $field_key ) && $field_key !== '' ) {
				$keyset[ $field_key ] = true;
			}
		}
		if ( $keyset === [] ) {
			$this->advance( $state, $next );
			return;
		}

		$spec = $this->table_spec( $which );
		$max  = (int) $this->range_max_for_phase( $state );
		if ( $spec === null || $max <= 0 ) {
			$this->advance( $state, $next );
			return;
		}

		$cursor = (int) $state['cursor'];
		if ( $cursor >= $max ) {
			$this->advance( $state, $next );
			return;
		}
		$hi   = min( $max, $cursor + self::RANGE_SPAN );
		$rows = $this->fetch_reference_rows( $spec, $cursor, $hi, array_keys( $keyset ) );
		if ( $rows === null ) {
			$this->fail( $state, 'ACF reference scan failed.' );
			return;
		}
		$bucket = 'discovered_' . $which;
		if ( ! isset( $state[ $bucket ] ) || ! is_array( $state[ $bucket ] ) ) {
			$state[ $bucket ] = [];
		}
		foreach ( $rows as $row ) {
			$meta_key = isset( $row->meta_key ) ? (string) $row->meta_key : '';
			if ( $meta_key === '' || $meta_key[0] !== '_' ) {
				continue;
			}
			$value_key = substr( $meta_key, 1 );
			if ( $this->is_bindable_meta_key( $value_key ) ) {
				$state[ $bucket ][ $value_key ] = 1;
			}
		}
		$state['cursor'] = $hi;
		if ( $state['cursor'] >= $max ) {
			$this->advance( $state, $next );
		}
	}

	/**
	 * Raw meta chunks. Any attachment ID in a numeric or serialized value counts as used.
	 *
	 * @param array  $state State.
	 * @param string $which post|term|user|option.
	 * @param string $next  Next phase.
	 */
	private function phase_fallback_range( array &$state, $which, $next ) {
		$spec = $this->table_spec( $which );
		$max  = (int) $this->range_max_for_phase( $state );
		if ( $spec === null || $max <= 0 ) {
			$this->advance( $state, $next );
			return;
		}
		$cursor = (int) $state['cursor'];
		if ( $cursor >= $max ) {
			$this->advance( $state, $next );
			return;
		}
		$hi   = min( $max, $cursor + self::RANGE_SPAN );
		$rows = $this->fetch_range( $spec, $cursor, $hi );
		if ( $rows === null ) {
			$this->fail( $state, 'Fallback meta scan failed.' );
			return;
		}
		foreach ( $rows as $row ) {
			if ( ! isset( $row->meta_value ) ) {
				continue;
			}
			if ( $this->is_plugin_record( isset( $row->meta_key ) ? $row->meta_key : '' ) ) {
				continue;
			}
			$this->absorb_values( $state, [ $row->meta_value ] );
		}
		$state['cursor'] = $hi;
		if ( $state['cursor'] >= $max ) {
			$this->advance( $state, $next );
		}
	}

	/**
	 * @param array $state State.
	 */
	private function phase_auxiliary( array &$state ) {
		if ( empty( $state['skip_auxiliary'] ) ) {
			$this->scanner->collect_auxiliary_sources();
			if ( $this->db_failed() ) {
				$this->fail( $state, 'Auxiliary scan failed.' );
				return;
			}
			foreach ( $this->scanner->drain_used_ids() as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$state['used'][ $id ] = 1;
				}
			}
		}
		if ( ! empty( $state['skip_classify'] ) ) {
			$state['phase']  = 'done';
			$state['cursor'] = 0;
			return;
		}
		$this->advance( $state, 'classify' );
	}

	/**
	 * @param array $state State.
	 */
	private function phase_classify( array &$state ) {
		global $wpdb;

		if ( empty( $state['shared_files_applied'] ) ) {
			$used   = isset( $state['used'] ) && is_array( $state['used'] ) ? $state['used'] : [];
			$shared = $this->scanner->ids_sharing_files_with_used( $used );
			if ( $shared === null ) {
				$this->fail( $state, 'Shared file scan failed.' );
				return;
			}
			foreach ( $shared as $id => $_unused ) {
				$state['used'][ (int) $id ] = 1;
			}
			$state['shared_files_applied'] = 1;
		}

		$batch  = 40;
		$offset = (int) $state['cursor'];
		$wpdb->last_error = '';
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status != 'trash' ORDER BY ID ASC LIMIT %d OFFSET %d",
				$batch,
				$offset
			)
		);
		if ( $this->db_failed() || ! is_array( $ids ) ) {
			$this->fail( $state, 'Could not list attachments.' );
			return;
		}

		$whitelist = function_exists( 'get_option' ) ? get_option( 'olimc_whitelist', [] ) : [];
		if ( ! is_array( $whitelist ) ) {
			$whitelist = [];
		}
		$wl = array_fill_keys( array_map( 'intval', $whitelist ), true );

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 || isset( $state['used'][ $id ] ) || isset( $wl[ $id ] ) ) {
				continue;
			}
			$info = $this->scanner->get_attachment_info( $id );
			if ( $info ) {
				if ( ! isset( $state['pending_delta'] ) || ! is_array( $state['pending_delta'] ) ) {
					$state['pending_delta'] = [];
				}
				$state['pending_delta'][] = $info;
			}
		}

		$state['cursor'] = $offset + count( $ids );
		if ( count( $ids ) < $batch ) {
			$state['phase'] = 'done';
		}
	}

	/**
	 * Field-name keys for the indexed pass. Options are stored as options_{name}.
	 *
	 * @param array  $state State.
	 * @param string $which Table.
	 * @return string[]
	 */
	private function value_keys_for_table( array $state, $which ) {
		$names = isset( $state['names'] ) && is_array( $state['names'] ) ? $state['names'] : [];
		if ( $which !== 'option' ) {
			return $names;
		}
		$out = [];
		foreach ( $names as $name ) {
			if ( is_string( $name ) && $name !== '' ) {
				$out[] = 'options_' . $name;
			}
		}
		return $out;
	}

	/**
	 * @param array $state State.
	 * @param array $values Meta values.
	 */
	private function absorb_values( array &$state, array $values ) {
		foreach ( $values as $value ) {
			$ids = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value(
				$value,
				[ $this->scanner, 'resolve_upload_path_to_id' ],
				[ $this->scanner, 'is_known_attachment_id' ]
			);
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$state['used'][ $id ] = 1;
				}
			}
		}
	}

	/**
	 * @param array  $state State.
	 * @param string $next  Next phase name.
	 */
	private function advance( array &$state, $next ) {
		$state['phase']  = $next;
		$state['cursor'] = 0;
	}

	/**
	 * @param array  $state   State.
	 * @param string $message Error message.
	 */
	private function fail( array &$state, $message ) {
		$state['phase']          = 'failed';
		$state['error']          = $message;
		$state['pending_unused'] = [];
		$state['pending_delta']  = [];
		$state['used']           = [];
	}

	/**
	 * @param string $which post|term|user|option.
	 * @return array{table: string, id: string, key: string, value: string, max: string}|null
	 */
	private function table_spec( $which ) {
		global $wpdb;
		if ( $which === 'post' ) {
			return [
				'table' => $wpdb->postmeta,
				'id'    => 'meta_id',
				'key'   => 'meta_key',
				'value' => 'meta_value',
				'max'   => 'max_post',
			];
		}
		if ( $which === 'term' ) {
			if ( ! isset( $wpdb->termmeta ) ) {
				return null;
			}
			return [
				'table' => $wpdb->termmeta,
				'id'    => 'meta_id',
				'key'   => 'meta_key',
				'value' => 'meta_value',
				'max'   => 'max_term',
			];
		}
		if ( $which === 'user' ) {
			return [
				'table' => $wpdb->usermeta,
				'id'    => 'umeta_id',
				'key'   => 'meta_key',
				'value' => 'meta_value',
				'max'   => 'max_user',
			];
		}
		if ( $which === 'option' ) {
			return [
				'table' => $wpdb->options,
				'id'    => 'option_id',
				'key'   => 'option_name',
				'value' => 'option_value',
				'max'   => 'max_option',
			];
		}
		return null;
	}

	/**
	 * @param array $state State.
	 * @return int
	 */
	private function range_max_for_phase( array $state ) {
		$phase = isset( $state['phase'] ) ? $state['phase'] : '';
		if ( substr( $phase, -5 ) === '_post' || substr( $phase, -4 ) === 'post' ) {
			return (int) $state['max_post'];
		}
		if ( substr( $phase, -5 ) === '_term' ) {
			return (int) $state['max_term'];
		}
		if ( substr( $phase, -5 ) === '_user' ) {
			return (int) $state['max_user'];
		}
		if ( substr( $phase, -7 ) === '_option' ) {
			return (int) $state['max_option'];
		}
		return 0;
	}

	/**
	 * @param string $table Table name.
	 * @param string $id_col ID column.
	 * @return int
	 */
	private function max_id( $table, $id_col ) {
		global $wpdb;
		if ( ! $this->is_safe_identifier( $table ) || ! $this->is_safe_identifier( $id_col ) ) {
			return 0;
		}
		$wpdb->last_error = '';
		$value            = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(%i) FROM %i',
				$id_col,
				$table
			)
		);
		if ( $this->db_failed() ) {
			return 0;
		}
		return (int) $value;
	}

	/**
	 * @param string   $table Table.
	 * @param string   $key_col Key column.
	 * @param string   $value_col Value column.
	 * @param string[] $keys Keys.
	 * @return array|null Null on database error.
	 */
	private function fetch_values_for_keys( $table, $key_col, $value_col, array $keys ) {
		global $wpdb;
		if ( $keys === [] ) {
			return [];
		}
		if ( ! $this->is_safe_identifier( $table ) || ! $this->is_safe_identifier( $key_col ) || ! $this->is_safe_identifier( $value_col ) ) {
			return null;
		}
		$parts = [];
		foreach ( $keys as $key ) {
			$parts[] = $wpdb->prepare(
				'SELECT %i AS meta_value FROM %i WHERE %i = %s AND %i != %s',
				$value_col,
				$table,
				$key_col,
				$key,
				$value_col,
				''
			);
		}
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- UNION of $wpdb->prepare() fragments with %i identifiers.
		$col              = $wpdb->get_col( implode( ' UNION ALL ', $parts ) );
		if ( $this->db_failed() || ! is_array( $col ) ) {
			return null;
		}
		return $col;
	}

	/**
	 * @param array    $spec  Table spec.
	 * @param int      $lo    Exclusive lower id.
	 * @param int      $hi    Inclusive upper id.
	 * @param string[] $field_keys ACF field keys.
	 * @return array|null
	 */
	private function fetch_reference_rows( array $spec, $lo, $hi, array $field_keys ) {
		global $wpdb;
		$field_keys = array_values( array_filter( $field_keys, [ $this, 'is_bindable_meta_key' ] ) );
		if ( $field_keys === [] ) {
			return [];
		}
		if ( ! $this->is_safe_identifier( $spec['table'] ) || ! $this->is_safe_identifier( $spec['id'] ) || ! $this->is_safe_identifier( $spec['key'] ) || ! $this->is_safe_identifier( $spec['value'] ) ) {
			return null;
		}
		$clauses = [];
		$args    = [
			$spec['key'],
			$spec['value'],
			$spec['table'],
			$spec['id'],
			(int) $lo,
			$spec['id'],
			(int) $hi,
		];
		foreach ( $field_keys as $field_key ) {
			$clauses[] = '%i = %s';
			$args[]    = $spec['value'];
			$args[]    = $field_key;
		}
		$wpdb->last_error = '';
		$rows             = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT %i AS meta_key, %i AS meta_value FROM %i WHERE %i > %d AND %i <= %d AND (' . esc_sql( implode( ' OR ', $clauses ) ) . ')',
				...$args
			)
		);
		if ( $this->db_failed() || ! is_array( $rows ) ) {
			return null;
		}
		return $rows;
	}

	/**
	 * @param array $spec Table spec.
	 * @param int   $lo   Exclusive lower id.
	 * @param int   $hi   Inclusive upper id.
	 * @return array|null
	 */
	private function fetch_range( array $spec, $lo, $hi ) {
		global $wpdb;
		if ( ! $this->is_safe_identifier( $spec['table'] ) || ! $this->is_safe_identifier( $spec['id'] ) || ! $this->is_safe_identifier( $spec['key'] ) || ! $this->is_safe_identifier( $spec['value'] ) ) {
			return null;
		}
		$wpdb->last_error = '';
		$rows             = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT %i AS meta_key, %i AS meta_value FROM %i WHERE %i > %d AND %i <= %d',
				$spec['key'],
				$spec['value'],
				$spec['table'],
				$spec['id'],
				(int) $lo,
				$spec['id'],
				(int) $hi
			)
		);
		if ( $this->db_failed() || ! is_array( $rows ) ) {
			return null;
		}
		return $rows;
	}

	/**
	 * Plugin options, transients, and meta are not evidence that a file is used.
	 *
	 * @param string $key Meta key or option name.
	 * @return bool
	 */
	private function is_plugin_record( $key ) {
		if ( ! is_string( $key ) || $key === '' ) {
			return false;
		}
		$prefixes = [
			'olimc_',
			'_olimc_',
			'_transient_olimc_',
			'_transient_timeout_olimc_',
			'_site_transient_olimc_',
			'_site_transient_timeout_olimc_',
		];
		foreach ( $prefixes as $prefix ) {
			if ( strpos( $key, $prefix ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Meta keys and ACF field keys are query values, not SQL identifiers.
	 * WordPress allows hyphens and other characters; prepare() binds them.
	 *
	 * @param mixed $key Meta key or field key.
	 * @return bool
	 */
	private function is_bindable_meta_key( $key ) {
		if ( ! is_string( $key ) || $key === '' || strlen( $key ) > 255 ) {
			return false;
		}
		return strpos( $key, "\0" ) === false;
	}

	/**
	 * Column names interpolated into SQL. These are chosen by this class, never by meta.
	 *
	 * @param string $key Column name.
	 * @return bool
	 */
	private function is_safe_identifier( $key ) {
		return is_string( $key ) && $key !== '' && (bool) preg_match( '/^[A-Za-z0-9_]+$/', $key );
	}

	/**
	 * @return bool
	 */
	private function db_failed() {
		global $wpdb;
		return isset( $wpdb->last_error ) && $wpdb->last_error !== '';
	}
}
