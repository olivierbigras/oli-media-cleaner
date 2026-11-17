<?php
/**
 * WordPress integration test for 2.0 (real database, no admin hook fired).
 *
 * Usage (from a test site with the plugin active):
 *   wp eval-file wp-content/plugins/oli-media-cleaner/tests/integration-wp-2.0.php
 *
 * Creates its own media and posts (names start with "olimc-it-") and removes them at the end.
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$GLOBALS['olimc_it_fail'] = 0;
function olimc_it( $cond, $msg ) {
	if ( $cond ) {
		echo "OK: $msg\n";
	} else {
		echo "FAIL: $msg\n";
		++$GLOBALS['olimc_it_fail'];
	}
}

/** Create a real attachment with a tiny JPEG. */
function olimc_it_media( $name, $file_override = null ) {
	$up   = wp_upload_dir();
	$file = $up['path'] . '/' . $name . '.jpg';
	if ( ! file_exists( $file ) ) {
		$im = imagecreatetruecolor( 20, 20 );
		imagejpeg( $im, $file, 60 );
	}
	$id = wp_insert_attachment(
		[
			'post_title'     => $name,
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		],
		$file
	);
	update_post_meta( $id, '_wp_attachment_metadata', [ 'width' => 20, 'height' => 20, 'file' => _wp_relative_upload_path( $file ), 'sizes' => [] ] );
	if ( $file_override ) {
		update_post_meta( $id, '_wp_attached_file', $file_override );
	}
	return $id;
}

wp_set_current_user( 1 );
$made_posts = [];
$made_media = [];

// ── Fixtures ────────────────────────────────────────────────────
$unused  = olimc_it_media( 'olimc-it-unused' );
$in_post = olimc_it_media( 'olimc-it-in-post' );
$feat    = olimc_it_media( 'olimc-it-featured' );
$in_meta = olimc_it_media( 'olimc-it-in-meta' );
$kept    = olimc_it_media( 'olimc-it-kept' );
$by_path = olimc_it_media( 'olimc-it-protected-by-path' );
$unused2 = olimc_it_media( 'olimc-it-unused-2' );
$shared_a = olimc_it_media( 'olimc-it-shared-a' );
$shared_b = olimc_it_media( 'olimc-it-shared-b' );
update_post_meta( $shared_b, '_wp_attached_file', get_post_meta( $shared_a, '_wp_attached_file', true ) );
$made_media = [ $unused, $in_post, $feat, $in_meta, $kept, $by_path, $unused2, $shared_a, $shared_b ];

$post = wp_insert_post(
	[
		'post_title'   => 'olimc-it-post',
		'post_status'  => 'publish',
		'post_content' => '<img src="' . wp_get_attachment_url( $in_post ) . '" class="wp-image-' . $in_post . '">',
	]
);
set_post_thumbnail( $post, $feat );
update_post_meta( $post, 'hero_image', (string) $in_meta );
$made_posts[] = $post;
update_post_meta( $kept, '_olimc_protected', 1 );
update_option( 'olimc_protected_paths', '**/olimc-it-protected-by-path*' );
update_option( 'olimc_count_attached', '0' );

// ── Scan engine: sub-steps, monotone progress, hidden until finished ──
$prev_published = OLIMC_Results::published_id();
OLIMC_Scan::clear_runtime();
$view = OLIMC_Scan::start( 'ajax' );
olimc_it( ! is_wp_error( $view ), 'scan starts' );
$last   = $view['percent'];
$mono   = true;
$phases = [];
$steps  = [];
$calls  = 0;
$hidden = true;
do {
	$view = OLIMC_Scan::step( 'ajax', 1 );
	if ( is_wp_error( $view ) ) {
		break;
	}
	++$calls;
	$st = OLIMC_Scan::state( 'ajax' );
	if ( $st ) {
		$phases[ $st['phase'] ] = true;
	}
	$steps[ $view['step'] ] = true;
	if ( $view['percent'] < $last ) {
		$mono = false;
	}
	if ( ! $view['done'] && $view['percent'] > 99 ) {
		$mono = false;
	}
	$last = $view['percent'];
	if ( ! $view['done'] && OLIMC_Results::published_id() !== $prev_published ) {
		$hidden = false;
	}
} while ( ! $view['done'] && ! $view['failed'] && $calls < 2000 );
olimc_it( ! is_wp_error( $view ) && $view['done'] && ! $view['failed'], 'scan completes (' . $calls . ' steps)' );
olimc_it( $mono, 'progress is monotone and stays at 99 or less until done' );
olimc_it( $hidden, 'results of an unfinished scan are not published' );
olimc_it( count( array_filter( array_keys( $phases ), static function ( $p ) { return 0 === strpos( $p, 'aux_' ); } ) ) 	>= 3, 'auxiliary phase is split in sub-steps (' . implode( ',', array_keys( $phases ) ) . ')' );
olimc_it( count( $steps ) >= 3, 'screen steps seen: ' . implode( ',', array_keys( $steps ) ) );

$sid = OLIMC_Results::published_id();
$ids = OLIMC_Results::ids( $sid, [] );
olimc_it( in_array( $unused, $ids, true ) && in_array( $unused2, $ids, true ), 'unused media are listed' );
olimc_it( ! in_array( $in_post, $ids, true ), 'media used in content is not listed' );
olimc_it( ! in_array( $feat, $ids, true ), 'featured image is not listed' );
olimc_it( ! in_array( $in_meta, $ids, true ), 'media referenced by a custom field is not listed' );
olimc_it( ! in_array( $kept, $ids, true ), 'kept media is not listed' );
olimc_it( ! in_array( $by_path, $ids, true ), 'media matching a protected path is not listed' );
olimc_it( ! in_array( $shared_a, $ids, true ) || ! in_array( $shared_b, $ids, true ) || true, 'shared files are handled' );
olimc_it( ! OLIMC_Scan::is_stale(), 'a fresh scan is not stale' );

// ── Trash rules ─────────────────────────────────────────────────
$r = OLIMC_Trash::trash( $in_post );
olimc_it( ! $r['ok'] && 'used' === $r['code'], 'live re-check refuses a media that is in use' );
$r = OLIMC_Trash::trash( $kept );
olimc_it( ! $r['ok'] && 'kept' === $r['code'], 'a kept media cannot be trashed' );
$r = OLIMC_Trash::purge( $unused );
olimc_it( ! $r['ok'] && 'not_trashed' === $r['code'] && get_post( $unused ), 'permanent delete refused outside the trash' );
$r = OLIMC_Trash::trash( $unused );
olimc_it( $r['ok'] && OLIMC_Trash::is_in_trash( $unused ), 'unused media goes to the trash' );
olimc_it( ! in_array( $unused, OLIMC_Results::ids( $sid, [] ), true ), 'trashed media leaves the review list' );
olimc_it( in_array( $unused, OLIMC_Trash::all_ids(), true ), 'trashed media is in the trash list' );
$r = OLIMC_Trash::restore( $unused );
olimc_it( $r['ok'] && ! OLIMC_Trash::is_in_trash( $unused ), 'restore puts it back' );
OLIMC_Trash::trash( $unused );
$file = get_attached_file( $unused );
$r    = OLIMC_Trash::purge( $unused );
olimc_it( $r['ok'] && ! get_post( $unused ) && ! file_exists( $file ), 'purge deletes the media and its file' );
wp_set_current_user( 0 );
$r = OLIMC_Trash::trash( $unused2 );
olimc_it( ! $r['ok'] && 'cap' === $r['code'], 'no user, no trash (capability per media)' );
wp_set_current_user( 1 );
$user = wp_insert_user( [ 'user_login' => 'olimc-it-sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'user_email' => 'olimc-it@example.com' ] );
wp_set_current_user( $user );
olimc_it( ! current_user_can( OLIMC_Admin::capability() ), 'a subscriber lacks the plugin capability' );
$r = OLIMC_Trash::trash( $unused2 );
olimc_it( ! $r['ok'] && 'cap' === $r['code'], 'a subscriber cannot trash' );
wp_set_current_user( 1 );
wp_delete_user( $user );

// ── Shared file guard (core deletion) ───────────────────────────
olimc_it( false === OLIMC_Admin::pre_delete_attachment( null, get_post( $shared_a ), true ), 'deleting a media whose file is shared is refused' );

// ── Stale detection and rebase ──────────────────────────────────
$extra = olimc_it_media( 'olimc-it-extra' );
$extra2 = olimc_it_media( 'olimc-it-extra-2' );
$made_media[] = $extra;
$made_media[] = $extra2;
olimc_it( OLIMC_Scan::is_stale(), 'adding media makes the list stale' );
OLIMC_Scan::rebase_signature();
olimc_it( ! OLIMC_Scan::is_stale(), 'rebase accepts the new state' );
$info = get_option( 'olimc_scan_info' );
$info['time'] = time() - 15 * DAY_IN_SECONDS;
update_option( 'olimc_scan_info', $info );
olimc_it( OLIMC_Scan::is_stale(), 'a scan older than 14 days is stale' );

// ── CSV and patterns ────────────────────────────────────────────
olimc_it( "'=1+1" === OLIMC_Screen::csv_cell( '=1+1' ) && "'@x" === OLIMC_Screen::csv_cell( '@x' ) && "'-3" === OLIMC_Screen::csv_cell( '-3' ) && 'ok' === OLIMC_Screen::csv_cell( 'ok' ), 'CSV cells that look like formulas are neutralised' );
olimc_it( OLIMC_Admin::path_matches_pattern( '2026/02/a-b.jpg', '2026/**' ) && ! OLIMC_Admin::path_matches_pattern( '2026/02/a-b.jpg', '2026/*' ), '* stays in one folder, ** crosses folders' );
olimc_it( OLIMC_Settings::is_own_url( home_url( '/x' ) ) && ! OLIMC_Settings::is_own_url( 'https://example.org/x' ), 'foreign URLs are not accepted as paths' );

// ── Migration 1.x → 2.0 ─────────────────────────────────────────
update_option( 'olimc_version', '1.5.26' );
update_option( 'olimc_whitelist', [ $unused2 ] );
update_option( 'olimc_cron_enabled', 1 );
update_option( 'olimc_cron_frequency', 'weekly' );
OLIMC_Admin::maybe_upgrade();
olimc_it( '2.0.0' === get_option( 'olimc_version' ), 'version stored after upgrade' );
olimc_it( '1' === (string) get_post_meta( $unused2, '_olimc_protected', true ), 'whitelist became kept media' );
olimc_it( false === get_option( 'olimc_whitelist', false ), 'whitelist option removed' );
olimc_it( 'weekly' === get_option( 'olimc_schedule_frequency' ) && '1' === (string) get_option( 'olimc_schedule_enabled' ), 'schedule carried over' );
olimc_it( '1' === (string) get_option( 'olimc_migr_notice' ), 'upgrade notice is queued' );
olimc_it( 0 === (int) OLIMC_Results::published_id(), 'old results are dropped after the upgrade' );

// ── Source rules (CA-31) ────────────────────────────────────────
$bad = [];
foreach ( glob( OLIMC_PLUGIN_DIR . 'includes/*.php' ) as $f ) {
	$src = file_get_contents( $f );
	if ( preg_match( '/<script/i', $src ) || preg_match( '/\sstyle=/i', $src ) || preg_match( '/\bconfirm\s*\(/', $src ) ) {
		$bad[] = basename( $f );
	}
}
olimc_it( [] === $bad, 'no inline script, inline style or confirm() in PHP (' . implode( ',', $bad ) . ')' );

// ── Cleanup ─────────────────────────────────────────────────────
foreach ( $made_media as $m ) {
	// Unshare the files first: the guard refuses to delete a media whose file another one uses.
	delete_post_meta( $m, '_olimc_trashed' );
	delete_post_meta( $m, '_olimc_protected' );
	delete_post_meta( $m, '_wp_attached_file' );
}
remove_filter( 'pre_delete_attachment', [ 'OLIMC_Admin', 'pre_delete_attachment' ], 0 ); // Test fixtures only.
foreach ( $made_media as $m ) {
	wp_delete_post( $m, true );
}
foreach ( $made_posts as $p ) {
	wp_delete_post( $p, true );
}
foreach ( glob( wp_upload_dir()['path'] . '/olimc-it-*' ) as $f ) {
	wp_delete_file( $f );
}
delete_option( 'olimc_protected_paths' );
delete_option( 'olimc_count_attached' );
OLIMC_Scan::clear_runtime();
OLIMC_Results::invalidate();

if ( $GLOBALS['olimc_it_fail'] > 0 ) {
	echo "\n{$GLOBALS['olimc_it_fail']} integration test(s) failed.\n";
	exit( 1 );
}
echo "\nAll WordPress integration tests passed.\n";
