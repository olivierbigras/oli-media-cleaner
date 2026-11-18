<?php
/**
 * WordPress integration test: false-positive regression (one media per source).
 *
 * Usage (from a test site with the plugin active):
 *   wp eval-file wp-content/plugins/oli-media-cleaner/tests/integration-wp-sources.php
 *
 * Each media is placed in exactly one source; none of them may be listed as unused.
 * Names start with "olimc-src-"; everything is removed at the end.
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$GLOBALS['olimc_src_fail'] = 0;
function olimc_src( $cond, $msg ) {
	echo ( $cond ? 'OK: ' : 'FAIL: ' ) . $msg . "\n";
	if ( ! $cond ) {
		++$GLOBALS['olimc_src_fail'];
	}
}
function olimc_src_media( $name ) {
	$up   = wp_upload_dir();
	$file = $up['path'] . '/' . $name . '.jpg';
	$im   = imagecreatetruecolor( 20, 20 );
	imagejpeg( $im, $file, 60 );
	$id = wp_insert_attachment(
		[
			'post_title'     => $name,
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		],
		$file
	);
	update_post_meta( $id, '_wp_attachment_metadata', [ 'width' => 20, 'height' => 20, 'file' => _wp_relative_upload_path( $file ), 'sizes' => [] ] );
	return $id;
}

wp_set_current_user( 1 );
$ids    = [];
$cleanup = [];
$names  = [ 'unused', 'content', 'featured', 'gallery', 'variation', 'elementor', 'css', 'themefile', 'widget', 'logo', 'icon', 'serialized', 'json', 'usermeta', 'termmeta', 'termdesc', 'shortcode_a', 'shortcode_b', 'blockgal_a', 'blockgal_b', 'blockimg', 'wpimage', 'gal_attached', 'gal_idattr', 'wt_gallery', 'wt_wpimage', 'wb_image', 'wb_shortcode', 'wb_gallery', 'wm_gallery', 'wm_image' ];
foreach ( $names as $n ) {
	$ids[ $n ] = olimc_src_media( 'olimc-src-' . $n );
}
$ids['plainchild'] = olimc_src_media( 'olimc-src-plainchild' ); // Control: attached to a post without any gallery.
$url = static function ( $n ) use ( $ids ) {
	return wp_get_attachment_url( $ids[ $n ] );
};

// Post content (block with an id and an <img> with the URL).
$cleanup['posts'][] = wp_insert_post( [ 'post_title' => 'olimc-src post', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => '<!-- wp:image {"id":' . $ids['content'] . '} --><img src="' . esc_url( $url( 'content' ) ) . '" class="wp-image-' . $ids['content'] . '"/><!-- /wp:image -->' ] );
// Media cited only by ID, with no upload URL anywhere in the content.
$cleanup['posts'][] = wp_insert_post( [ 'post_title' => 'olimc-src shortcode', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => '[gallery ids="' . $ids['shortcode_a'] . ',' . $ids['shortcode_b'] . '"]' ] );
$cleanup['posts'][] = wp_insert_post( [ 'post_title' => 'olimc-src block gallery', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => '<!-- wp:gallery {"ids":[' . $ids['blockgal_a'] . ',' . $ids['blockgal_b'] . '],"linkTo":"none"} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->' ] );
$cleanup['posts'][] = wp_insert_post( [ 'post_title' => 'olimc-src block image', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => '<!-- wp:image {"id":' . $ids['blockimg'] . ',"sizeSlug":"large"} --><figure class="wp-block-image"></figure><!-- /wp:image -->' ] );
$cleanup['posts'][] = wp_insert_post( [ 'post_title' => 'olimc-src class only', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => '<p><img class="wp-image-' . $ids['wpimage'] . '" alt=""></p>' ] );
// [gallery] without ids shows the media attached to the post (post_parent), also through id="N".
$gp = wp_insert_post( [ 'post_title' => 'olimc-src default gallery', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'Photos: [gallery columns="3"]' ] );
$cleanup['posts'][] = $gp;
wp_update_post( [ 'ID' => $ids['gal_attached'], 'post_parent' => $gp ] );
$gq = wp_insert_post( [ 'post_title' => 'olimc-src parent of gallery by id', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'No gallery here.' ] );
$cleanup['posts'][] = $gq;
wp_update_post( [ 'ID' => $ids['gal_idattr'], 'post_parent' => $gq ] );
$cleanup['posts'][] = wp_insert_post( [ 'post_title' => 'olimc-src gallery by id', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => '[gallery id="' . $gq . '" size="thumbnail"]' ] );
$pc = wp_insert_post( [ 'post_title' => 'olimc-src plain parent', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'Nothing to show.' ] );
$cleanup['posts'][] = $pc;
wp_update_post( [ 'ID' => $ids['plainchild'], 'post_parent' => $pc ] );
// Featured image.
$p = wp_insert_post( [ 'post_title' => 'olimc-src featured', 'post_status' => 'publish', 'post_type' => 'post' ] );
set_post_thumbnail( $p, $ids['featured'] );
$cleanup['posts'][] = $p;
// WooCommerce gallery and variation (post meta, no WooCommerce needed).
$p = wp_insert_post( [ 'post_title' => 'olimc-src product', 'post_status' => 'publish', 'post_type' => 'post' ] );
update_post_meta( $p, '_product_image_gallery', '999999,' . $ids['gallery'] );
$cleanup['posts'][] = $p;
$p = wp_insert_post( [ 'post_title' => 'olimc-src variation', 'post_status' => 'publish', 'post_type' => 'post' ] );
update_post_meta( $p, '_thumbnail_id', $ids['variation'] );
$cleanup['posts'][] = $p;
// Elementor (JSON in post meta).
$p = wp_insert_post( [ 'post_title' => 'olimc-src elementor', 'post_status' => 'publish', 'post_type' => 'page' ] );
update_post_meta( $p, '_elementor_data', wp_slash( wp_json_encode( [ [ 'elType' => 'widget', 'settings' => [ 'image' => [ 'id' => $ids['elementor'], 'url' => $url( 'elementor' ) ] ] ] ] ) ) );
$cleanup['posts'][] = $p;
// Customizer additional CSS.
wp_update_custom_css_post( 'body{background:url(' . $url( 'css' ) . ')}' );
// Theme PHP file (active theme, removed afterwards).
$theme_file = get_stylesheet_directory() . '/olimc-src-test.php';
file_put_contents( $theme_file, "<?php // <img src=\"" . $url( 'themefile' ) . "\">\n" );
// Widget, site logo, site icon.
update_option(
	'widget_text',
	[
		2             => [ 'title' => 't', 'text' => '<img src="' . $url( 'widget' ) . '">' ],
		3             => [ 'title' => 'g', 'text' => '[gallery ids="' . $ids['wt_gallery'] . '"]' ],
		4             => [ 'title' => 'i', 'text' => '<p><img class="alignnone wp-image-' . $ids['wt_wpimage'] . '" alt=""></p>' ],
		'_multiwidget' => 1,
	]
);
// Block widgets and media widgets, cited by identifier only (no upload URL).
update_option(
	'widget_block',
	[
		2             => [ 'content' => '<!-- wp:image {"id":' . $ids['wb_image'] . ',"sizeSlug":"large"} --><figure class="wp-block-image"></figure><!-- /wp:image -->' ],
		3             => [ 'content' => '<!-- wp:shortcode -->[gallery ids="' . $ids['wb_shortcode'] . '"]<!-- /wp:shortcode -->' ],
		4             => [ 'content' => '<!-- wp:gallery {"ids":[' . $ids['wb_gallery'] . ',999999],"linkTo":"none"} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->' ],
		'_multiwidget' => 1,
	]
);
update_option( 'widget_media_gallery', [ 2 => [ 'title' => 'm', 'ids' => [ $ids['wm_gallery'], 999998 ] ], '_multiwidget' => 1 ] );
update_option( 'widget_media_image', [ 2 => [ 'title' => 'm', 'attachment_id' => $ids['wm_image'], 'url' => '' ], '_multiwidget' => 1 ] );
set_theme_mod( 'custom_logo', $ids['logo'] );
update_option( 'site_icon', $ids['icon'] );
// Serialized and JSON meta, user meta, term meta, term description.
$p = wp_insert_post( [ 'post_title' => 'olimc-src meta', 'post_status' => 'publish', 'post_type' => 'post' ] );
update_post_meta( $p, 'src_serialized', [ 'a' => [ 'img' => (string) $ids['serialized'] ] ] );
update_post_meta( $p, 'src_json', wp_json_encode( [ 'image' => $url( 'json' ) ] ) );
$cleanup['posts'][] = $p;
update_user_meta( 1, 'src_avatar', $ids['usermeta'] );
$t = wp_insert_term( 'olimc-src-term', 'category' );
$cleanup['term'] = $t['term_id'];
// Written directly: core filters strip markup from a description saved through the API in some contexts.
global $wpdb;
$wpdb->update( $wpdb->term_taxonomy, [ 'description' => '<img src="' . $url( 'termdesc' ) . '">' ], [ 'term_id' => $t['term_id'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
update_term_meta( $t['term_id'], 'src_thumb', $ids['termmeta'] );

// Scan.
OLIMC_Scan::clear_runtime();
OLIMC_Results::invalidate();
OLIMC_Scan::start( 'ajax' );
$guard = 0;
do {
	$v = OLIMC_Scan::step( 'ajax', 1500 );
} while ( empty( $v['done'] ) && empty( $v['failed'] ) && ++$guard < 500 );
olimc_src( ! empty( $v['done'] ), 'scan completes' );

$table  = $wpdb->prefix . 'olimc_results';
$listed = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM {$table} WHERE scan_id = %s", OLIMC_Results::published_id() ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
olimc_src( in_array( $ids['unused'], $listed, true ), 'control: the unreferenced media is listed' );
olimc_src( in_array( $ids['plainchild'], $listed, true ), 'control: media attached to a post without a gallery is listed' );
olimc_src( true === ( new OLIMC_Scanner() )->is_attachment_in_use_now( $ids['gal_attached'] ), 'live check: media attached to a post with [gallery] is in use' );
olimc_src( true === ( new OLIMC_Scanner() )->is_attachment_in_use_now( $ids['gal_idattr'] ), 'live check: media shown by [gallery id="N"] is in use' );
foreach ( [ 'wt_gallery', 'wt_wpimage', 'wb_image', 'wb_shortcode', 'wb_gallery', 'wm_gallery', 'wm_image' ] as $w ) {
	olimc_src( true === ( new OLIMC_Scanner() )->is_attachment_in_use_now( $ids[ $w ] ), "live check: widget media '$w' is in use" );
}
olimc_src( false === ( new OLIMC_Scanner() )->is_attachment_in_use_now( $ids['plainchild'] ), 'live check: control media is not in use' );
foreach ( $names as $n ) {
	if ( 'unused' === $n ) {
		continue;
	}
	olimc_src( ! in_array( $ids[ $n ], $listed, true ), "source '$n': media not listed" );
}

// Cleanup (the shared-file guard is lifted for these fixtures only; the files are removed by hand).
remove_filter( 'pre_delete_attachment', [ 'OLIMC_Admin', 'pre_delete_attachment' ], 0 );
foreach ( $ids as $id ) {
	delete_post_meta( $id, '_olimc_trashed' );
	delete_post_meta( $id, '_wp_attached_file' );
	wp_delete_attachment( $id, true );
}
foreach ( glob( wp_upload_dir()['path'] . '/olimc-src-*.jpg' ) ?: [] as $f ) {
	unlink( $f );
}
foreach ( $cleanup['posts'] as $pid ) {
	wp_delete_post( $pid, true );
}
wp_delete_term( $cleanup['term'], 'category' );
delete_user_meta( 1, 'src_avatar' );
delete_option( 'widget_text' );
delete_option( 'widget_block' );
delete_option( 'widget_media_gallery' );
delete_option( 'widget_media_image' );
remove_theme_mod( 'custom_logo' );
delete_option( 'site_icon' );
wp_update_custom_css_post( '' );
if ( file_exists( $theme_file ) ) {
	unlink( $theme_file );
}
OLIMC_Scan::clear_runtime();
OLIMC_Results::invalidate();

echo $GLOBALS['olimc_src_fail'] ? "\n{$GLOBALS['olimc_src_fail']} FAILED\n" : "\nAll source tests passed.\n";
exit( $GLOBALS['olimc_src_fail'] ? 1 : 0 );
