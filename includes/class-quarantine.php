<?php
/**
 * Real quarantine of the trash.
 *
 * Putting a media in the trash moves its files (original, -scaled, original
 * image, every generated size, backup sizes and the .webp/.avif copies that
 * exist on disk) out of their public place, into
 * `uploads/olimc-trash/<random token>/<attachment ID>/files/<original path>`.
 * A manifest (original path, size, MD5 of each moved file) is kept in the
 * `_olimc_trash_map` post meta and in `manifest.json` next to the files, so a
 * restore puts back exactly the same bytes at exactly the same paths.
 *
 * While in the trash the media is hidden from the Media Library: its post
 * status is `olimc-trash` (WordPress never purges it, and no query for the
 * usual `inherit` status returns it). The former status is kept and restored.
 *
 * Only OLIMC_Trash decides whether an action is allowed. This class moves files.
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

class OLIMC_Quarantine {

	const STATUS     = 'olimc-trash';
	const META_MAP   = '_olimc_trash_map';
	const META_PREV  = '_olimc_prev_status';
	const META_FLAG  = '_olimc_trashed';
	const META_LOCK  = '_olimc_trashing';
	const OPT_TOKEN  = 'olimc_trash_token';
	const FOLDER     = 'olimc-trash';
	const LOCK_SECS  = 300;
	const THUMB_MAX  = 2097152;

	/**
	 * Original absolute paths that a deletion in progress must never unlink,
	 * per attachment ID. A file found there is not ours: it was created after the move.
	 *
	 * @var array<int, string[]>
	 */
	private static $guard = [];

	/**
	 * Quarantine folder of media being deleted, removed once the deletion is done.
	 *
	 * @var array<int, string>
	 */
	private static $pending = [];

	/**
	 * Moves in progress, so a fatal error or a time-out puts the files back.
	 *
	 * @var array<int, array{moved: array, dir: string}>
	 */
	private static $inflight = [];

	/**
	 * @var bool
	 */
	private static $shutdown_registered = false;

	/**
	 * Whether a move was refused in this request because a folder was not writable.
	 *
	 * @var bool
	 */
	private static $perm_failed = false;

	/**
	 * Shutdown: a move that did not finish (fatal error, memory or time limit) is undone,
	 * so a media is never left with only some of its files moved.
	 */
	public static function undo_unfinished() {
		foreach ( self::$inflight as $id => $job ) {
			self::rollback_in( $job['moved'] );
			if ( '' !== $job['dir'] ) {
				self::remove_tree( $job['dir'] );
			}
			delete_post_meta( $id, self::META_LOCK );
		}
		self::$inflight = [];
	}

	/**
	 * @return bool True when a move was refused in this request for lack of write access.
	 */
	public static function had_permission_error() {
		return self::$perm_failed;
	}

	/**
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function perm_error( $message ) {
		self::$perm_failed = true;
		return new WP_Error( 'olimc_perm', $message );
	}

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_status' ], 1 );
		add_action( 'admin_post_olimc_thumb', [ __CLASS__, 'serve_thumb' ] );
		add_action( 'delete_attachment', [ __CLASS__, 'before_delete' ], 0 );
		add_action( 'deleted_post', [ __CLASS__, 'after_delete' ], 10 );
		add_filter( 'wp_delete_file', [ __CLASS__, 'guard_file' ], 99 );
	}

	/**
	 * Internal status of media in the trash: not public, not listed, not queried.
	 */
	public static function register_status() {
		register_post_status(
			self::STATUS,
			[
				'label'                     => 'Oli Media Cleaner trash',
				'public'                    => false,
				'internal'                  => true,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			]
		);
	}

	// ─── Locations ────────────────────────────────────────────────────

	/**
	 * @return string Uploads base directory of the current site, no trailing slash. Empty when unknown.
	 */
	public static function basedir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		return rtrim( str_replace( '\\', '/', (string) $uploads['basedir'] ), '/' );
	}

	/**
	 * @return string Absolute path of the quarantine root (may not exist yet).
	 */
	public static function root_dir() {
		$base = self::basedir();
		return '' === $base ? '' : $base . '/' . self::FOLDER;
	}

	/**
	 * Random folder name, created once per site and kept in an option. The
	 * quarantine URL is therefore not guessable, even on a server that
	 * ignores .htaccess.
	 *
	 * @return string 32 hexadecimal characters.
	 */
	public static function token() {
		$token = (string) get_option( self::OPT_TOKEN, '' );
		if ( preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return $token;
		}
		$token = bin2hex( random_bytes( 16 ) );
		if ( ! add_option( self::OPT_TOKEN, $token, '', 'no' ) ) {
			$existing = (string) get_option( self::OPT_TOKEN, '' );
			if ( preg_match( '/^[a-f0-9]{32}$/', $existing ) ) {
				return $existing;
			}
			update_option( self::OPT_TOKEN, $token, false );
		}
		return $token;
	}

	/**
	 * @param int $id Attachment ID.
	 * @return string Folder of this media, relative to the uploads folder.
	 */
	private static function dir_rel( $id ) {
		return self::FOLDER . '/' . self::token() . '/' . (int) $id;
	}

	/**
	 * Absolute folder of a media from its map, only when it sits inside the quarantine.
	 *
	 * @param array $map Map.
	 * @return string Empty when invalid.
	 */
	private static function dir_from_map( array $map ) {
		$base = self::basedir();
		$rel  = isset( $map['dir'] ) ? (string) $map['dir'] : '';
		if ( '' === $base || ! preg_match( '#^' . self::FOLDER . '/[a-f0-9]{32}/\d+$#', $rel ) ) {
			return '';
		}
		return $base . '/' . $rel;
	}

	/**
	 * Safe relative path (no traversal, not inside the quarantine itself).
	 *
	 * @param mixed $path Path.
	 * @return string Empty when unsafe.
	 */
	private static function clean_rel( $path ) {
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) ) {
			return '';
		}
		$path = ltrim( str_replace( '\\', '/', $path ), '/' );
		if ( '' === $path || preg_match( '#(^|/)\.\.?(/|$)#', $path ) || 0 === strpos( $path, self::FOLDER . '/' ) ) {
			return '';
		}
		return $path;
	}

	// ─── State ────────────────────────────────────────────────────────

	/**
	 * @param int $id Attachment ID.
	 * @return array|null Map of the media (files moved), null when nothing was moved by this class.
	 */
	public static function map( $id ) {
		$raw = get_post_meta( (int) $id, self::META_MAP, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$map = json_decode( $raw, true );
		return ( is_array( $map ) && isset( $map['files'] ) && is_array( $map['files'] ) ) ? $map : null;
	}

	/**
	 * @param int $id Attachment ID.
	 * @return bool True when the media has a quarantine map.
	 */
	public static function is_quarantined( $id ) {
		return null !== self::map( $id );
	}

	/**
	 * Size of the files of a media in the quarantine (moved files and files left in place).
	 *
	 * @param int $id Attachment ID.
	 * @return int|null Null when the media is not quarantined.
	 */
	public static function total_bytes( $id ) {
		$map = self::map( $id );
		if ( null === $map ) {
			return null;
		}
		$sum = 0;
		foreach ( $map['files'] as $f ) {
			$sum += isset( $f['s'] ) ? (int) $f['s'] : 0;
		}
		if ( ! empty( $map['stay'] ) && is_array( $map['stay'] ) ) {
			$base = self::basedir();
			foreach ( $map['stay'] as $rel ) {
				$sum += olimc_filesize( $base . '/' . $rel );
			}
		}
		return $sum;
	}

	/**
	 * Whether the uploads folder allows the quarantine to be created and used.
	 *
	 * @return bool
	 */
	public static function is_writable() {
		$base = self::basedir();
		if ( '' === $base || ! wp_is_writable( $base ) ) {
			return false;
		}
		$root = self::root_dir();
		return ! is_dir( $root ) || wp_is_writable( $root );
	}

	// ─── Move in ──────────────────────────────────────────────────────

	/**
	 * Move every file of a media into the quarantine.
	 *
	 * Nothing is moved unless the whole set can be moved: a failure on one
	 * file puts back the files already moved for this media.
	 *
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	public static function move_in( $id ) {
		$id = (int) $id;
		if ( ! self::lock( $id ) ) {
			return new WP_Error( 'olimc_busy', 'This media is being processed.' );
		}
		try {
			return self::do_move_in( $id );
		} finally {
			delete_post_meta( $id, self::META_LOCK );
		}
	}

	/**
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	private static function lock( $id ) {
		if ( add_post_meta( $id, self::META_LOCK, time(), true ) ) {
			return true;
		}
		$since = (int) get_post_meta( $id, self::META_LOCK, true );
		if ( $since > 0 && time() - $since < self::LOCK_SECS ) {
			return false;
		}
		update_post_meta( $id, self::META_LOCK, time() );
		return true;
	}

	/**
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	private static function do_move_in( $id ) {
		$base = self::basedir();
		if ( '' === $base ) {
			return self::perm_error( 'The uploads folder is not available.' );
		}
		// A folder left by a move of this same media that was killed midway: put its files back first,
		// so the plan below sees every file at its original path.
		$left = $base . '/' . self::dir_rel( $id );
		if ( is_dir( $left ) ) {
			self::recover_interrupted( $left, $base );
		}
		$plan = self::plan( $id, $base );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$prev = (string) get_post_field( 'post_status', $id, 'raw' );
		$map  = [
			'v'           => 1,
			'id'          => $id,
			'moved_at'    => time(),
			'prev_status' => '' !== $prev ? $prev : 'inherit',
			'dir'         => '',
			'files'       => [],
			'stay'        => $plan['stay'],
		];

		if ( [] === $plan['move'] ) {
			// No file on disk to set aside (missing files, or all shared): the media is only hidden.
			self::finalize( $id, $map );
			return true;
		}

		if ( ! self::is_writable() ) {
			return self::perm_error( 'The uploads folder is not writable.' );
		}
		foreach ( $plan['move'] as $rel => $info ) {
			if ( ! wp_is_writable( dirname( $info['abs'] ) ) ) {
				return self::perm_error( 'The uploads folder is not writable.' );
			}
		}

		$map['dir'] = self::dir_rel( $id );
		$dir        = $base . '/' . $map['dir'];
		if ( is_dir( $dir ) ) {
			// Already recovered above; whatever is left is not ours to guess about.
			if ( ! self::cleanup_dir( $dir ) ) {
				return new WP_Error( 'olimc_dir', 'A previous quarantine folder for this media is not empty.' );
			}
		}
		self::ensure_guards();
		if ( ! self::mkdir_protected( $dir, $base ) ) {
			return self::perm_error( 'The uploads folder is not writable.' );
		}

		foreach ( $plan['move'] as $rel => $info ) {
			$map['files'][] = [
				'p' => $rel,
				's' => $info['size'],
				'm' => $info['md5'],
			];
		}
		// Manifest first: if the request is killed midway, the files can still be put back by hand.
		$map['state'] = 'moving';
		if ( ! self::write_manifest( $dir, $map ) ) {
			self::remove_tree( $dir );
			return self::perm_error( 'The manifest could not be written.' );
		}

		$moved = [];
		self::$inflight[ $id ] = [
			'moved' => &$moved,
			'dir'   => $dir,
		];
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( [ __CLASS__, 'undo_unfinished' ] );
		}
		foreach ( $plan['move'] as $rel => $info ) {
			$dest = $dir . '/files/' . $rel;
			if ( ! self::mkdir_protected( dirname( $dest ), $dir ) || ! self::may_move( $info['abs'], $dest ) || true !== self::move_file( $info['abs'], $dest ) ) {
				self::rollback_in( $moved );
				unset( self::$inflight[ $id ] );
				self::remove_tree( $dir );
				return new WP_Error( 'olimc_move', 'A file could not be moved.' );
			}
			$moved[ $rel ] = [ $info['abs'], $dest ];
		}

		unset( $map['state'] );
		if ( ! self::write_manifest( $dir, $map ) ) {
			self::rollback_in( $moved );
			unset( self::$inflight[ $id ] );
			self::remove_tree( $dir );
			return self::perm_error( 'The manifest could not be written.' );
		}
		self::finalize( $id, $map );
		unset( self::$inflight[ $id ] );
		return true;
	}

	/**
	 * A manifest in the state "moving" means the process died before the media was hidden:
	 * every file listed that sits in the quarantine goes back to its original path
	 * (never over an existing file).
	 *
	 * @param string $dir  Absolute media folder.
	 * @param string $base Uploads base directory.
	 */
	private static function recover_interrupted( $dir, $base ) {
		$raw = is_file( $dir . '/manifest.json' ) ? file_get_contents( $dir . '/manifest.json' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file of the quarantine.
		$map = json_decode( (string) $raw, true );
		if ( ! is_array( $map ) || empty( $map['files'] ) || ! is_array( $map['files'] ) || 'moving' !== ( isset( $map['state'] ) ? $map['state'] : '' ) ) {
			return;
		}
		foreach ( $map['files'] as $f ) {
			$rel = self::clean_rel( isset( $f['p'] ) ? $f['p'] : '' );
			if ( '' === $rel ) {
				continue;
			}
			$from = $dir . '/files/' . $rel;
			$to   = $base . '/' . $rel;
			if ( is_file( $from ) && ! file_exists( $to ) ) {
				self::move_file( $from, $to, true );
			}
		}
	}

	/**
	 * Which files to move and which stay where they are (shared with another media).
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $base Uploads base directory.
	 * @return array{move: array<string, array{abs: string, size: int, md5: string}>, stay: string[]}|WP_Error
	 */
	private static function plan( $id, $base ) {
		$scanner = new OLIMC_Scanner();
		$paths   = $scanner->relative_upload_paths( $id );
		if ( null === $paths ) {
			return new WP_Error( 'olimc_index', 'The list of files could not be read.' );
		}
		$meta = get_post_meta( $id, '_wp_attachment_metadata', true );
		$dir  = '';
		if ( is_array( $meta ) && ! empty( $meta['file'] ) && is_string( $meta['file'] ) ) {
			$dir = dirname( $meta['file'] );
		} else {
			$dir = dirname( (string) get_post_meta( $id, '_wp_attached_file', true ) );
		}
		$dir = ( '.' === $dir || '' === $dir ) ? '' : $dir . '/';
		// WordPress 6.x lists the WebP/AVIF files it generated under "sources".
		$sources = [];
		if ( is_array( $meta ) ) {
			$lists = [];
			if ( ! empty( $meta['sources'] ) && is_array( $meta['sources'] ) ) {
				$lists[] = $meta['sources'];
			}
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( is_array( $size ) && ! empty( $size['sources'] ) && is_array( $size['sources'] ) ) {
						$lists[] = $size['sources'];
					}
				}
			}
			foreach ( $lists as $list ) {
				foreach ( $list as $source ) {
					if ( is_array( $source ) && ! empty( $source['file'] ) && is_string( $source['file'] ) ) {
						$sources[] = false === strpos( $source['file'], '/' ) ? $dir . $source['file'] : $source['file'];
					}
				}
			}
		}

		$own   = [];
		$stays = [];
		foreach ( $paths as $rel ) {
			$rel = self::clean_rel( $rel );
			if ( '' === $rel ) {
				continue;
			}
			$shared = $scanner->relative_path_is_shared( $rel );
			if ( null === $shared ) {
				return new WP_Error( 'olimc_index', 'The list of files could not be read.' );
			}
			$abs = $base . '/' . $rel;
			if ( $shared ) {
				if ( is_file( $abs ) ) {
					$stays[ $rel ] = true;
				}
				continue;
			}
			$own[ $rel ] = true;
			// Copies next to the file: photo.jpg.webp / photo.webp (same for AVIF).
			foreach ( [ 'webp', 'avif' ] as $ext ) {
				$own_sib = $rel . '.' . $ext;
				if ( is_file( $base . '/' . $own_sib ) ) {
					$own[ $own_sib ] = true;
				}
				$stem_sib = preg_replace( '/\.[^.\/]+$/', '', $rel ) . '.' . $ext;
				if ( $stem_sib !== $rel && is_file( $base . '/' . $stem_sib ) && false === $scanner->stem_used_by_others( $id, $stem_sib ) ) {
					$own[ $stem_sib ] = true;
				}
			}
		}
		foreach ( $sources as $rel ) {
			$rel = self::clean_rel( $rel );
			if ( '' !== $rel && ! isset( $stays[ $rel ] ) && true !== $scanner->relative_path_is_shared( $rel ) ) {
				$own[ $rel ] = true;
			}
		}

		$move = [];
		foreach ( array_keys( $own ) as $rel ) {
			$abs = $base . '/' . $rel;
			if ( ! is_file( $abs ) ) {
				continue; // Listed in the metadata but not on disk: nothing to move.
			}
			if ( is_link( $abs ) ) {
				$stays[ $rel ] = true;
				continue;
			}
			$md5  = md5_file( $abs );
			$size = olimc_filesize( $abs );
			if ( false === $md5 ) {
				return new WP_Error( 'olimc_read', 'A file could not be read.' );
			}
			$move[ $rel ] = [
				'abs'  => $abs,
				'size' => $size,
				'md5'  => $md5,
			];
		}
		return [
			'move' => $move,
			'stay' => array_keys( $stays ),
		];
	}

	/**
	 * Hide the media and record the state. Done once every file is in the quarantine.
	 *
	 * @param int   $id  Attachment ID.
	 * @param array $map Map.
	 */
	private static function finalize( $id, array $map ) {
		global $wpdb;
		if ( ! empty( $map['files'] ) ) {
			update_post_meta( $id, self::META_MAP, wp_slash( wp_json_encode( $map ) ) );
		} else {
			delete_post_meta( $id, self::META_MAP );
		}
		update_post_meta( $id, self::META_PREV, $map['prev_status'] );
		// Direct update: no save_post / attachment_updated hook for a change nobody asked for in the editor.
		$wpdb->update( $wpdb->posts, [ 'post_status' => self::STATUS ], [ 'ID' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $id );
		update_post_meta( $id, self::META_FLAG, time() );
	}

	/**
	 * @param array<string, array{0: string, 1: string}> $moved rel => [original, quarantined].
	 */
	private static function rollback_in( array $moved ) {
		foreach ( array_reverse( $moved, true ) as $pair ) {
			self::move_file( $pair[1], $pair[0] );
		}
	}

	// ─── Move back ────────────────────────────────────────────────────

	/**
	 * Put every file back at its original path, then show the media again.
	 *
	 * Checks first (files present and identical, no file at the destination),
	 * then moves; nothing is ever overwritten.
	 *
	 * @param int $id Attachment ID.
	 * @return true|WP_Error Error codes: olimc_exists (a file is already at the destination), olimc_integrity, olimc_perm, olimc_move.
	 */
	public static function move_back( $id ) {
		$id = (int) $id;
		if ( ! self::lock( $id ) ) {
			return new WP_Error( 'olimc_busy', 'This media is being processed.' );
		}
		try {
			return self::do_move_back( $id );
		} finally {
			delete_post_meta( $id, self::META_LOCK );
		}
	}

	/**
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	private static function do_move_back( $id ) {
		$map = self::map( $id );
		if ( null === $map ) {
			// Hidden and flagged, nothing was moved (media without file on disk): show it again.
			self::unhide( $id, '' );
			return true;
		}
		$base = self::basedir();
		$dir  = self::dir_from_map( $map );
		if ( '' === $base || '' === $dir ) {
			return new WP_Error( 'olimc_integrity', 'The quarantine folder of this media is unknown.' );
		}

		$pairs = [];
		foreach ( $map['files'] as $f ) {
			$rel = self::clean_rel( isset( $f['p'] ) ? $f['p'] : '' );
			if ( '' === $rel ) {
				return new WP_Error( 'olimc_integrity', 'The manifest is not valid.' );
			}
			$from = $dir . '/files/' . $rel;
			$to   = $base . '/' . $rel;
			if ( ! file_exists( $from ) && is_file( $to ) && olimc_filesize( $to ) === (int) $f['s'] && md5_file( $to ) === (string) $f['m'] ) {
				continue; // Already back (a former restore of this media was interrupted): nothing to do for this file.
			}
			if ( ! is_file( $from ) || olimc_filesize( $from ) !== (int) $f['s'] || md5_file( $from ) !== (string) $f['m'] ) {
				return new WP_Error( 'olimc_integrity', 'A file in the quarantine is missing or was changed.' );
			}
			if ( file_exists( $to ) || is_link( $to ) ) {
				return new WP_Error( 'olimc_exists', 'A file with the same name already exists.' );
			}
			$pairs[ $rel ] = [ $from, $to ];
		}
		foreach ( $pairs as $rel => $pair ) {
			$target = dirname( $pair[1] );
			if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) {
				return self::perm_error( 'A folder could not be created.' );
			}
			if ( ! wp_is_writable( $target ) ) {
				return self::perm_error( 'The uploads folder is not writable.' );
			}
		}

		$done = [];
		foreach ( $pairs as $rel => $pair ) {
			$res = self::may_move( $pair[0], $pair[1] ) ? self::move_file( $pair[0], $pair[1], true ) : 'fail';
			if ( true !== $res ) {
				foreach ( array_reverse( $done, true ) as $back ) {
					self::move_file( $back[1], $back[0] );
				}
				return 'exists' === $res
					? new WP_Error( 'olimc_exists', 'A file with the same name already exists.' )
					: new WP_Error( 'olimc_move', 'A file could not be put back.' );
			}
			$done[ $rel ] = $pair;
		}

		self::unhide( $id, $dir );
		return true;
	}

	/**
	 * Show the media again with its former status and drop the quarantine record.
	 *
	 * @param int    $id  Attachment ID.
	 * @param string $dir Absolute quarantine folder to remove, or empty.
	 */
	private static function unhide( $id, $dir ) {
		global $wpdb;
		$prev = (string) get_post_meta( $id, self::META_PREV, true );
		if ( '' === $prev || self::STATUS === $prev || 'trash' === $prev ) {
			$prev = 'inherit';
		}
		if ( self::STATUS === (string) get_post_field( 'post_status', $id, 'raw' ) ) {
			$wpdb->update( $wpdb->posts, [ 'post_status' => $prev ], [ 'ID' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		delete_post_meta( $id, self::META_FLAG );
		delete_post_meta( $id, self::META_MAP );
		delete_post_meta( $id, self::META_PREV );
		clean_post_cache( $id );
		if ( '' !== $dir ) {
			self::cleanup_dir( $dir );
		}
	}

	/**
	 * Leave the trash without moving any file (uninstall of a media that cannot be restored):
	 * the media is shown again, its files stay in the quarantine folder with their manifest.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function release_status( $id ) {
		self::unhide( (int) $id, '' );
	}

	// ─── Deletion ─────────────────────────────────────────────────────

	/**
	 * A media is about to be deleted for good: remember where its quarantine is, and
	 * make sure WordPress never unlinks a file found at the original paths (not ours).
	 *
	 * @param int $id Attachment ID.
	 */
	public static function before_delete( $id ) {
		$id  = (int) $id;
		$map = self::map( $id );
		if ( null === $map ) {
			return;
		}
		$base = self::basedir();
		$list = [];
		foreach ( $map['files'] as $f ) {
			$rel = self::clean_rel( isset( $f['p'] ) ? $f['p'] : '' );
			if ( '' !== $rel ) {
				$list[] = $base . '/' . $rel;
			}
		}
		self::$guard[ $id ] = array_map( [ __CLASS__, 'norm' ], $list );
		$dir                = self::dir_from_map( $map );
		if ( '' !== $dir ) {
			self::$pending[ $id ] = $dir;
		}
	}

	/**
	 * The media row is deleted: erase its quarantine folder. The guard on the
	 * original paths stays for the rest of the request, because WordPress
	 * unlinks the files of an attachment after this hook.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function after_delete( $id ) {
		$id = (int) $id;
		if ( isset( self::$pending[ $id ] ) ) {
			self::remove_tree( self::$pending[ $id ] );
			unset( self::$pending[ $id ] );
		}
	}

	/**
	 * @param string $file Absolute path about to be deleted by wp_delete_file().
	 * @return string The path, or an empty string to keep the file.
	 */
	public static function guard_file( $file ) {
		if ( [] === self::$guard || ! is_string( $file ) ) {
			return $file;
		}
		$file = self::norm( $file );
		foreach ( self::$guard as $list ) {
			if ( in_array( $file, $list, true ) ) {
				return '';
			}
		}
		return $file;
	}

	/**
	 * Path with its folder resolved (symlinks, ..) when the folder exists, for comparisons.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function norm( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$real = realpath( dirname( $path ) );
		return ( false !== $real ? rtrim( str_replace( '\\', '/', $real ), '/' ) : rtrim( dirname( $path ), '/' ) ) . '/' . basename( $path );
	}

	// ─── Preview in the Trash tab ─────────────────────────────────────

	/**
	 * @param int $id Attachment ID.
	 * @return string Admin URL that streams a small preview of a quarantined image, or empty.
	 */
	public static function thumb_url( $id ) {
		$id = (int) $id;
		if ( ! self::is_quarantined( $id ) || '' === self::preview_file( $id ) ) {
			return '';
		}
		return add_query_arg(
			[
				'action'   => 'olimc_thumb',
				'id'       => $id,
				'_wpnonce' => wp_create_nonce( 'olimc_thumb_' . $id ),
			],
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * admin-post.php?action=olimc_thumb: stream a preview from the quarantine.
	 * Capability and nonce required; real Content-Type; never a public URL.
	 */
	public static function serve_thumb() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked just below.
		if ( ! current_user_can( OLIMC_Admin::capability() ) || ! current_user_can( 'delete_post', $id ) ) {
			status_header( 403 );
			exit;
		}
		check_admin_referer( 'olimc_thumb_' . $id );
		$file = self::preview_file( $id );
		if ( '' === $file ) {
			status_header( 404 );
			exit;
		}
		$mime = self::preview_mime( $file );
		if ( '' === $mime ) {
			status_header( 404 );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		header( 'Cache-Control: private, max-age=300' );
		header( 'Content-Length: ' . (int) filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a file of the quarantine.
		exit;
	}

	/**
	 * @param int $id Attachment ID.
	 * @return string Absolute path of the smallest valid image file in the quarantine, or empty.
	 */
	private static function preview_file( $id ) {
		$map = self::map( $id );
		if ( null === $map ) {
			return '';
		}
		$dir = self::dir_from_map( $map );
		if ( '' === $dir ) {
			return '';
		}
		$candidates = [];
		foreach ( $map['files'] as $f ) {
			$rel = self::clean_rel( isset( $f['p'] ) ? $f['p'] : '' );
			if ( '' === $rel || '' === self::preview_mime_for_name( $rel ) || (int) $f['s'] > self::THUMB_MAX ) {
				continue;
			}
			$candidates[ $dir . '/files/' . $rel ] = (int) $f['s'];
		}
		asort( $candidates );
		foreach ( array_keys( $candidates ) as $abs ) {
			if ( is_file( $abs ) && '' !== self::preview_mime( $abs ) ) {
				return $abs;
			}
		}
		return '';
	}

	/**
	 * @param string $name File name.
	 * @return string Image MIME type by extension, empty for anything else.
	 */
	private static function preview_mime_for_name( $name ) {
		$types = [
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'jpe'  => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
			'bmp'  => 'image/bmp',
			'svg'  => 'image/svg+xml',
		];
		$ext   = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		return isset( $types[ $ext ] ) ? $types[ $ext ] : '';
	}

	/**
	 * @param string $file Absolute path.
	 * @return string Real MIME type (content for raster images), empty when it is not an image.
	 */
	private static function preview_mime( $file ) {
		$by_name = self::preview_mime_for_name( $file );
		if ( 'image/svg+xml' === $by_name ) {
			return $by_name;
		}
		$size = wp_getimagesize( $file );
		$real = ( is_array( $size ) && ! empty( $size['mime'] ) ) ? (string) $size['mime'] : '';
		return 0 === strpos( $real, 'image/' ) ? $real : '';
	}

	// ─── Uninstall ────────────────────────────────────────────────────

	/**
	 * Remove the quarantine folder when nothing is left in it (no media folder).
	 *
	 * @return bool True when the folder is gone or did not exist.
	 */
	public static function remove_if_empty() {
		$root = self::root_dir();
		if ( '' === $root || ! is_dir( $root ) ) {
			return true;
		}
		foreach ( (array) scandir( $root ) as $entry ) {
			if ( '.' === $entry || '..' === $entry || 'index.php' === $entry || '.htaccess' === $entry ) {
				continue;
			}
			$sub = $root . '/' . $entry;
			if ( is_dir( $sub ) && ! is_link( $sub ) ) {
				foreach ( (array) scandir( $sub ) as $item ) {
					if ( '.' !== $item && '..' !== $item && 'index.php' !== $item ) {
						return false; // A media folder is still here.
					}
				}
				continue;
			}
			return false; // Anything else: not ours to remove.
		}
		self::remove_tree( $root );
		return true;
	}

	/**
	 * Explain to a person who finds the folder after the plugin is gone.
	 */
	public static function write_leftover_note() {
		$root = self::root_dir();
		if ( '' === $root || ! is_dir( $root ) ) {
			return;
		}
		$text = "Oli Media Cleaner\n\nThis folder holds files that were in the plugin's trash when the plugin was removed and could not be put back (a file with the same name already existed, or the folder was not writable).\nEach folder is named after a media ID. Its manifest.json lists the original path, size and MD5 of every file; the files are under files/ with their original relative path (inside the uploads folder).\nNothing here is deleted for you. Copy a file back to its original path to restore it.\n";
		file_put_contents( $root . '/README.txt', $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	// ─── Files ────────────────────────────────────────────────────────

	/**
	 * @return string Rules of the .htaccess at the quarantine root.
	 */
	private static function htaccess_rules() {
		return "# Oli Media Cleaner: no direct access to the trash.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n";
	}

	/**
	 * The quarantine root and the token folder may exist without their guard files (removed by hand,
	 * older build): write them again before anything is moved in.
	 */
	private static function ensure_guards() {
		$root = self::root_dir();
		if ( '' === $root || ! is_dir( $root ) ) {
			return;
		}
		$silent = "<?php\n// Silence is golden.\n";
		if ( ! file_exists( $root . '/index.php' ) ) {
			file_put_contents( $root . '/index.php', $silent ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			file_put_contents( $root . '/.htaccess', self::htaccess_rules() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$token_dir = $root . '/' . self::token();
		if ( is_dir( $token_dir ) && ! file_exists( $token_dir . '/index.php' ) ) {
			file_put_contents( $token_dir . '/index.php', $silent ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Create a folder (and the missing parents up to $stop) with an empty index.php in each.
	 * The quarantine root also gets an .htaccess that denies direct access. Nginx ignores
	 * it: the random folder name and the empty index files are the protection there.
	 *
	 * @param string $dir     Folder to create.
	 * @param string $stop    Existing folder under which the new ones are created.
	 * @return bool
	 */
	private static function mkdir_protected( $dir, $stop ) {
		if ( is_dir( $dir ) ) {
			return true;
		}
		$chain = [];
		$cur   = $dir;
		while ( $cur !== $stop && strlen( $cur ) > strlen( $stop ) && ! is_dir( $cur ) ) {
			array_unshift( $chain, $cur );
			$cur = dirname( $cur );
		}
		foreach ( $chain as $path ) {
			if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
				return false;
			}
			if ( ! file_exists( $path . '/index.php' ) ) {
				file_put_contents( $path . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
			if ( basename( $path ) === self::FOLDER && ! file_exists( $path . '/.htaccess' ) ) {
				file_put_contents( $path . '/.htaccess', self::htaccess_rules() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return is_dir( $dir );
	}

	/**
	 * @param string $dir Media folder.
	 * @param array  $map Map.
	 * @return bool
	 */
	private static function write_manifest( $dir, array $map ) {
		$json = wp_json_encode( $map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return false !== $json && false !== file_put_contents( $dir . '/manifest.json', $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Last chance for a site to refuse one move (the whole set is then put back).
	 *
	 * @param string $from Source path.
	 * @param string $to   Destination path.
	 * @return bool
	 */
	private static function may_move( $from, $to ) {
		/**
		 * Whether one file may be moved into or out of the trash. Returning false
		 * makes the move fail and the files already moved for that media go back.
		 *
		 * @param bool   $allow True.
		 * @param string $from  Source path.
		 * @param string $to    Destination path.
		 */
		return (bool) apply_filters( 'olimc_quarantine_allow_move', true, $from, $to );
	}

	/**
	 * Move a file. A rename when possible, else copy, check, delete the source.
	 *
	 * @param string $from       Source.
	 * @param string $to         Destination.
	 * @param bool   $no_clobber Never replace an existing file.
	 * @return true|string True, 'exists' (no_clobber and the destination exists) or 'fail'.
	 */
	private static function move_file( $from, $to, $no_clobber = false ) {
		if ( $no_clobber ) {
			if ( file_exists( $to ) || is_link( $to ) ) {
				return 'exists';
			}
			// link() fails when the destination exists: no race between the check and the move.
			if ( function_exists( 'link' ) && @link( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( @unlink( $from ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
					return true;
				}
				@unlink( $to ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				return 'fail';
			}
			if ( file_exists( $to ) ) {
				return 'exists';
			}
		}
		if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			return true;
		}
		// Another file system: copy, check, then delete the source.
		if ( is_file( $from ) && @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( olimc_filesize( $to ) === olimc_filesize( $from ) && md5_file( $to ) === md5_file( $from ) && @unlink( $from ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				return true;
			}
			@unlink( $to ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		return 'fail';
	}

	/**
	 * Remove a media folder after a restore, only when it holds nothing but the
	 * index and manifest files this class wrote.
	 *
	 * @param string $dir Absolute media folder.
	 * @return bool True when removed.
	 */
	private static function cleanup_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return true;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( ! in_array( $file->getFilename(), [ 'index.php', 'manifest.json' ], true ) ) {
				return false;
			}
		}
		self::remove_tree( $dir );
		return ! is_dir( $dir );
	}

	/**
	 * Delete a folder of the quarantine with everything in it. Refuses any
	 * folder outside the quarantine root.
	 *
	 * @param string $dir Absolute path.
	 */
	private static function remove_tree( $dir ) {
		$root = self::root_dir();
		$dir  = rtrim( str_replace( '\\', '/', $dir ), '/' );
		if ( '' === $root || '' === $dir || 0 !== strpos( $dir . '/', $root . '/' ) || ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

/**
 * Trash backend that really moves the files: see OLIMC_Quarantine.
 */
class OLIMC_Trash_Quarantine_Backend implements OLIMC_Trash_Backend {

	/**
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	public function trash( $id ) {
		return OLIMC_Quarantine::move_in( (int) $id );
	}

	/**
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	public function restore( $id ) {
		return OLIMC_Quarantine::move_back( (int) $id );
	}

	/**
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public function is_trashed( $id ) {
		return (bool) get_post_meta( (int) $id, OLIMC_Quarantine::META_FLAG, true );
	}

	/**
	 * Delete for good: wp_delete_attachment() with the usual guards (kept, shared,
	 * live usage re-check). The quarantine folder is erased by the deletion hooks.
	 *
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
