<?php
/**
 * Trash of the plugin.
 *
 * Every state change goes through a backend (see OLIMC_Trash_Backend) so the
 * way a media is set aside can change without touching the screens, the
 * routes or the checks in this class. This class owns the rules: capability
 * per media, live usage re-check before trashing, kept media are never
 * trashed, and permanent deletion only from the trash.
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

/**
 * How a media is set aside, restored and erased.
 *
 * Implementations never decide whether an action is allowed: OLIMC_Trash does.
 */
interface OLIMC_Trash_Backend {

	/**
	 * Set a media aside.
	 *
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	public function trash( $id );

	/**
	 * Put a media back.
	 *
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	public function restore( $id );

	/**
	 * Whether the media was set aside by this backend.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public function is_trashed( $id );

	/**
	 * Erase a trashed media for good.
	 *
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	public function purge( $id );
}

/**
 * Backend used until the quarantine of files is decided: the media is flagged
 * with the `_olimc_trashed` meta (timestamp) and nothing else changes. No file
 * is moved, WordPress never purges it and the post status stays as it was.
 */
class OLIMC_Trash_Flag_Backend implements OLIMC_Trash_Backend {

	/**
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	public function trash( $id ) {
		update_post_meta( (int) $id, '_olimc_trashed', time() );
		return true;
	}

	/**
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	public function restore( $id ) {
		delete_post_meta( (int) $id, '_olimc_trashed' );
		return true;
	}

	/**
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public function is_trashed( $id ) {
		return (bool) get_post_meta( (int) $id, '_olimc_trashed', true );
	}

	/**
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	public function purge( $id ) {
		$deleted = wp_delete_attachment( (int) $id, true );
		if ( ! $deleted ) {
			return new WP_Error( 'olimc_refused', 'refused' );
		}
		return true;
	}
}

class OLIMC_Trash {

	/**
	 * @var OLIMC_Trash_Backend|null
	 */
	private static $backend = null;

	/**
	 * Active backend. The `olimc_trash_backend` filter may return another
	 * OLIMC_Trash_Backend.
	 *
	 * @return OLIMC_Trash_Backend
	 */
	public static function backend() {
		if ( null === self::$backend ) {
			$backend = apply_filters( 'olimc_trash_backend', new OLIMC_Trash_Flag_Backend() );
			self::$backend = ( $backend instanceof OLIMC_Trash_Backend ) ? $backend : new OLIMC_Trash_Flag_Backend();
		}
		return self::$backend;
	}

	/**
	 * Tests only.
	 *
	 * @param OLIMC_Trash_Backend|null $backend Backend, or null to reset.
	 */
	public static function set_backend( $backend ) {
		self::$backend = $backend;
	}

	/**
	 * @param int $id Attachment ID.
	 * @return bool True for a media in the trash of this plugin or in the former WordPress trash.
	 */
	public static function is_in_trash( $id ) {
		$id = (int) $id;
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			return false;
		}
		return self::backend()->is_trashed( $id ) || 'trash' === get_post_status( $id );
	}

	/**
	 * @param int $id Attachment ID.
	 * @return bool Media in the former WordPress trash (status trash), not flagged by this plugin.
	 */
	public static function is_legacy( $id ) {
		return 'trash' === get_post_status( (int) $id ) && ! self::backend()->is_trashed( (int) $id );
	}

	/**
	 * Move one media to the trash.
	 *
	 * @param int  $id          Attachment ID.
	 * @param bool $check_caps  Check the capability of the current user.
	 * @return array{ok: bool, code: string, id: int}
	 */
	public static function trash( $id, $check_caps = true ) {
		$id = (int) $id;
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			return self::result( false, 'invalid', $id );
		}
		if ( $check_caps && ! current_user_can( 'delete_post', $id ) ) {
			return self::result( false, 'cap', $id );
		}
		if ( self::is_in_trash( $id ) ) {
			return self::result( false, 'already', $id );
		}
		if ( OLIMC_Admin::is_attachment_protected( $id ) ) {
			return self::result( false, 'kept', $id );
		}
		// Live re-check: the list may be old. Unknown (null) counts as used.
		$in_use = ( new OLIMC_Scanner() )->is_attachment_in_use_now( $id );
		if ( true === $in_use || null === $in_use ) {
			return self::result( false, 'used', $id );
		}
		$done = self::backend()->trash( $id );
		if ( is_wp_error( $done ) ) {
			return self::result( false, 'fail', $id, $done->get_error_message() );
		}
		OLIMC_Results::remove( $id );
		return self::result( true, 'ok', $id );
	}

	/**
	 * Put one media back.
	 *
	 * @param int  $id         Attachment ID.
	 * @param bool $check_caps Check the capability of the current user.
	 * @return array{ok: bool, code: string, id: int}
	 */
	public static function restore( $id, $check_caps = true ) {
		$id = (int) $id;
		if ( ! self::is_in_trash( $id ) ) {
			return self::result( false, 'not_trashed', $id );
		}
		if ( $check_caps && ! current_user_can( 'delete_post', $id ) ) {
			return self::result( false, 'cap', $id );
		}
		if ( self::is_legacy( $id ) ) {
			$done = wp_untrash_post( $id ) ? true : new WP_Error( 'olimc_untrash', 'untrash' );
		} else {
			$done = self::backend()->restore( $id );
		}
		if ( is_wp_error( $done ) ) {
			return self::result( false, 'fail', $id, $done->get_error_message() );
		}
		return self::result( true, 'ok', $id );
	}

	/**
	 * Delete one media for good. Only a media in the trash can be deleted.
	 *
	 * @param int  $id         Attachment ID.
	 * @param bool $check_caps Check the capability of the current user.
	 * @return array{ok: bool, code: string, id: int}
	 */
	public static function purge( $id, $check_caps = true ) {
		$id = (int) $id;
		if ( ! self::is_in_trash( $id ) ) {
			return self::result( false, 'not_trashed', $id );
		}
		if ( $check_caps && ! current_user_can( 'delete_post', $id ) ) {
			return self::result( false, 'cap', $id );
		}
		if ( OLIMC_Admin::is_attachment_protected( $id ) ) {
			return self::result( false, 'kept', $id );
		}
		if ( self::is_legacy( $id ) ) {
			$deleted = wp_delete_attachment( $id, true );
			$done    = $deleted ? true : new WP_Error( 'olimc_refused', 'refused' );
		} else {
			$done = self::backend()->purge( $id );
		}
		if ( is_wp_error( $done ) ) {
			return self::result( false, 'used', $id );
		}
		OLIMC_Results::remove( $id );
		return self::result( true, 'ok', $id );
	}

	/**
	 * IDs of every media in the trash (this plugin and the former WordPress trash), oldest first.
	 *
	 * @return int[]
	 */
	public static function all_ids() {
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_olimc_trashed'
			 WHERE p.post_type = 'attachment' AND (p.post_status = 'trash' OR t.meta_id IS NOT NULL)
			 ORDER BY p.ID ASC"
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	/**
	 * @return int Number of media in the trash.
	 */
	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_olimc_trashed'
			 WHERE p.post_type = 'attachment' AND (p.post_status = 'trash' OR t.meta_id IS NOT NULL)"
		);
	}

	/**
	 * Restore every media in the trash (uninstall).
	 *
	 * @return int Number restored.
	 */
	public static function restore_all() {
		$n = 0;
		foreach ( self::all_ids() as $id ) {
			$r = self::restore( $id, false );
			if ( $r['ok'] ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Scheduled emptying (opt-in): erase media that have been in the trash
	 * longer than the delay chosen in the settings. Kept media are restored.
	 */
	public static function run_auto_empty() {
		$days = (int) get_option( 'olimc_auto_empty', 0 );
		if ( ! in_array( $days, [ 30, 90 ], true ) ) {
			return;
		}
		global $wpdb;
		$limit = time() - $days * DAY_IN_SECONDS;
		$ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_olimc_trashed'
				 WHERE p.post_type = 'attachment' AND CAST(t.meta_value AS UNSIGNED) < %d ORDER BY p.ID ASC LIMIT 200",
				$limit
			)
		);
		foreach ( (array) $ids as $id ) {
			if ( OLIMC_Admin::is_attachment_protected( (int) $id ) ) {
				self::restore( (int) $id, false );
			} else {
				self::purge( (int) $id, false );
			}
		}
	}

	/**
	 * @param bool   $ok     Success.
	 * @param string $code   Result code.
	 * @param int    $id     Attachment ID.
	 * @param string $detail Optional detail.
	 * @return array{ok: bool, code: string, id: int, detail: string}
	 */
	private static function result( $ok, $code, $id, $detail = '' ) {
		return [
			'ok'     => (bool) $ok,
			'code'   => $code,
			'id'     => (int) $id,
			'detail' => $detail,
		];
	}
}
