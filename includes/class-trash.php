<?php
/**
 * Trash of the plugin.
 *
 * Every state change goes through a backend (see OLIMC_Trash_Backend; the
 * default one moves the files into a quarantine, see OLIMC_Quarantine) so the
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

class OLIMC_Trash {

	/** Failed automatic emptying attempts of a media (it is skipped after AUTOFAIL_MAX). */
	const META_AUTOFAIL = '_olimc_autofail';
	const AUTOFAIL_MAX  = 3;
	/** Bounded journal of who trashed, restored or deleted what, and when. */
	const OPT_AUDIT     = 'olimc_audit_log';
	const AUDIT_MAX     = 200;
	const AUDIT_IDS     = 25;

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
			$backend = apply_filters( 'olimc_trash_backend', new OLIMC_Trash_Quarantine_Backend() );
			self::$backend = ( $backend instanceof OLIMC_Trash_Backend ) ? $backend : new OLIMC_Trash_Quarantine_Backend();
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
			// A file was created at the old path in the meantime: nothing is overwritten.
			$code = 'olimc_exists' === $done->get_error_code() ? 'exists' : 'fail';
			return self::result( false, $code, $id, $done->get_error_message() );
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
			// Another request is working on this media: not a "now in use" case.
			return self::result( false, 'olimc_busy' === $done->get_error_code() ? 'fail' : 'used', $id );
		}
		OLIMC_Results::remove( $id );
		return self::result( true, 'ok', $id );
	}

	/**
	 * Add one line to the journal: user, action, media IDs, time. Kept to the last AUDIT_MAX lines,
	 * no personal data besides the user ID, removed at uninstall with the plugin data.
	 *
	 * @param string $action Action: trash, restore, delete, empty, auto_empty, rotate_token.
	 * @param int[]  $ids    Media IDs concerned.
	 */
	public static function log( $action, array $ids ) {
		if ( [] === $ids && 'rotate_token' !== $action ) {
			return;
		}
		$log   = get_option( self::OPT_AUDIT, [] );
		$log   = is_array( $log ) ? $log : [];
		$log[] = [
			'u' => (int) get_current_user_id(),
			'a' => (string) $action,
			'n' => count( $ids ),
			'i' => array_map( 'intval', array_slice( array_values( $ids ), 0, self::AUDIT_IDS ) ),
			't' => time(),
		];
		update_option( self::OPT_AUDIT, array_slice( $log, -self::AUDIT_MAX ), false );
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
				 LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = %s
				 WHERE p.post_type = 'attachment' AND CAST(t.meta_value AS UNSIGNED) < %d
				 AND ( f.meta_id IS NULL OR CAST(f.meta_value AS UNSIGNED) < %d ) ORDER BY p.ID ASC LIMIT 200",
				self::META_AUTOFAIL,
				$limit,
				self::AUTOFAIL_MAX
			)
		);
		$done = [];
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( OLIMC_Admin::is_attachment_protected( $id ) ) {
				$r = self::restore( $id, false );
			} else {
				$r = self::purge( $id, false );
			}
			if ( $r['ok'] ) {
				$done[] = $id;
			} elseif ( ! in_array( $r['code'], [ 'not_trashed', 'cap' ], true ) ) {
				// A media that keeps failing (used again, folder not writable) must not block the newer ones
				// at every run: after AUTOFAIL_MAX attempts it is skipped.
				update_post_meta( $id, self::META_AUTOFAIL, (int) get_post_meta( $id, self::META_AUTOFAIL, true ) + 1 );
			}
		}
		self::log( 'auto_empty', $done );
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
