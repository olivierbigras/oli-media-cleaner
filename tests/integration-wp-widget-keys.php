<?php
/**
 * WordPress integration test (decisions D25 and the alignment that follows): a numeric array KEY that
 * equals a media ID counts as "in use" in widget_* options, theme_mods_* options and custom fields,
 * for the live check AND for the real scan (the media is absent from the review list), and so does a
 * real ID stored as a value. A media that is used nowhere is still listed.
 *
 * Usage (from a test site with the plugin active):
 *   wp eval-file wp-content/plugins/oli-media-cleaner/tests/integration-wp-widget-keys.php
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

wp_set_current_user( 1 );
$fail = 0;
$t    = function ( $c, $m ) use ( &$fail ) {
	echo ( $c ? 'OK: ' : 'FAIL: ' ) . $m . "\n";
	if ( ! $c ) {
		++$fail;
	}
};
$up   = wp_upload_dir();
$uniq = substr( md5( uniqid( '', true ) ), 0, 8 );
$make = function ( $name ) use ( $up, $uniq ) {
	$file = $up['path'] . "/olimc-wk-$name-$uniq.jpg";
	$im   = imagecreatetruecolor( 20, 20 );
	imagejpeg( $im, $file, 60 );
	$id = wp_insert_attachment( [ 'post_title' => "olimc-wk-$name-$uniq", 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ], $file );
	update_post_meta( $id, '_wp_attachment_metadata', [ 'width' => 20, 'height' => 20, 'file' => _wp_relative_upload_path( $file ), 'sizes' => [] ] );
	return $id;
};
$used = function ( $id ) {
	OLIMC_Scanner::reset_upload_path_index();
	return ( new OLIMC_Scanner() )->is_attachment_in_use_now( $id );
};

$k1 = $make( 'key-widget' );   // Only an array key in a widget option: protected.
$k2 = $make( 'key-theme' );    // Only an array key in a theme mod.
$v1 = $make( 'media-widget' ); // Core media widget: attachment_id.
$v2 = $make( 'plugin-widget' ); // Plugin widget: ID as a plain integer value.
$v3 = $make( 'theme-value' );  // Theme mod: ID as a value.
$v5 = $make( 'gallery-widget' ); // Media gallery widget: ids list.
$k3 = $make( 'key-field' );    // Only an array key in a custom field.
$v4 = $make( 'field-value' );  // Custom field: list of IDs as values.
$u0 = $make( 'unused' );       // Used nowhere: must still be listed.
$post = wp_insert_post( [ 'post_title' => "olimc-wk-host-$uniq", 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'x' ] );

update_option( "widget_olimc_wk_a_$uniq", [ $k1 => [ 'title' => 'only a key' ], '_multiwidget' => 1 ], false );
update_option( "theme_mods_olimc_wk_$uniq", [ $k2 => 'only a key', 'color' => 'red' ], false );
update_option( "widget_media_image_$uniq", [ 3 => [ 'attachment_id' => $v1, 'url' => '' ], '_multiwidget' => 1 ], false );
update_option( "widget_olimc_wk_b_$uniq", [ 4 => [ 'title' => 'p', 'image' => $v2 ], '_multiwidget' => 1 ], false );
update_option( "theme_mods_olimc_wk2_$uniq", [ 'header_pic' => $v3 ], false );
update_post_meta( $post, 'wkprobe_map', [ $k3 => 'only a key' ] );
update_post_meta( $post, 'wkprobe_list', [ $v4, $v4 + 100000 ] );
update_option( "widget_media_gallery_$uniq", [ 5 => [ 'ids' => [ $v5 ] ], '_multiwidget' => 1 ], false );

$t( true === $used( $k1 ), 'an ID that is only a widget array key stays protected (D25)' );
$t( true === $used( $k2 ), 'an ID that is only a theme-mod array key stays protected (D25)' );
$t( true === $used( $v1 ), 'a core media widget attachment_id keeps the media protected' );
$t( true === $used( $v2 ), 'a plugin widget holding the ID as a value keeps the media protected' );
$t( true === $used( $v3 ), 'a theme mod holding the ID as a value keeps the media protected' );
$t( true === $used( $v5 ), 'a media gallery widget ids list keeps the media protected' );
// The real scan: every protected media is absent from the review list; the unused one is listed.
OLIMC_Scan::clear_runtime();
OLIMC_Results::invalidate();
OLIMC_Scan::start( 'ajax' );
do {
	$step = OLIMC_Scan::step( 'ajax', 1500 );
} while ( empty( $step['done'] ) && empty( $step['failed'] ) );
$listed = OLIMC_Results::published_id() ? OLIMC_Results::ids( OLIMC_Results::published_id(), [] ) : [];
$listed = array_map( 'intval', $listed );
$t( empty( $step['failed'] ), 'the scan completes' );
$t( in_array( $u0, $listed, true ), 'control: a media used nowhere is listed in "To review"' );
foreach ( [ 'widget key' => $k1, 'theme-mod key' => $k2, 'custom-field key' => $k3, 'media widget value' => $v1, 'plugin widget value' => $v2, 'theme-mod value' => $v3, 'gallery widget ids' => $v5, 'custom-field value' => $v4 ] as $label => $mid ) {
	$t( ! in_array( $mid, $listed, true ), "scan: $label: absent from the review list (counted as used)" );
}
$r = OLIMC_Trash::trash( $k1 );
$t( ! $r['ok'] && 'used' === $r['code'], 'the key-only media is refused for the trash ("used"), nothing is moved' );
$t( null !== ( new OLIMC_Scanner() )->is_attachment_in_use_now( $k1 ), 'and the check answered' );

foreach ( [ "widget_olimc_wk_a_$uniq", "theme_mods_olimc_wk_$uniq", "widget_media_image_$uniq", "widget_olimc_wk_b_$uniq", "theme_mods_olimc_wk2_$uniq", "widget_media_gallery_$uniq" ] as $o ) {
	delete_option( $o );
}
wp_delete_post( $post, true );
foreach ( [ $k1, $k2, $k3, $v1, $v2, $v3, $v4, $v5, $u0 ] as $x ) {
	wp_delete_attachment( $x, true );
}
echo $fail ? "\n$fail FAILED\n" : "\nAll widget-key tests passed.\n";
exit( $fail ? 1 : 0 );
