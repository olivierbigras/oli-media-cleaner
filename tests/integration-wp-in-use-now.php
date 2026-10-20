<?php
/**
 * WordPress integration test: refuse deletion of media that is in use now.
 *
 * Usage (from a site with the plugin active):
 *   wp eval-file wp-content/plugins/oli-media-cleaner/tests/integration-wp-in-use-now.php
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

if ( ! class_exists( 'OLIMC_Scanner' ) || ! class_exists( 'OLIMC_Admin' ) ) {
	fwrite( STDERR, "Oli Media Cleaner is not loaded.\n" );
	exit( 1 );
}

$failed = 0;

/**
 * @param bool   $condition Condition.
 * @param string $message   Message.
 */
function olimc_wp_inuse_assert( $condition, $message ) {
	global $failed;
	if ( ! $condition ) {
		echo "FAIL: {$message}\n";
		$failed++;
		return;
	}
	echo "OK: {$message}\n";
}

$upload = wp_upload_dir();
if ( ! empty( $upload['error'] ) || empty( $upload['path'] ) || empty( $upload['url'] ) ) {
	fwrite( STDERR, "Upload directory is not available.\n" );
	exit( 1 );
}
if ( ! wp_mkdir_p( $upload['path'] ) ) {
	fwrite( STDERR, "Could not create the upload directory.\n" );
	exit( 1 );
}

/**
 * @return int Attachment ID.
 */
function olimc_wp_inuse_make_attachment() {
	$upload   = wp_upload_dir();
	$filename = 'olimc-inuse-' . wp_generate_password( 6, false ) . '.jpg';
	$dest     = trailingslashit( $upload['path'] ) . $filename;
	file_put_contents( $dest, 'fake' );
	$attachment_id = wp_insert_attachment(
		[
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'OLIMC in-use test',
			'post_status'    => 'inherit',
			'guid'           => trailingslashit( $upload['url'] ) . $filename,
		],
		$dest
	);
	$relative = _wp_relative_upload_path( $dest );
	update_post_meta( $attachment_id, '_wp_attached_file', $relative );
	wp_update_attachment_metadata( $attachment_id, [ 'file' => $relative ] );
	return (int) $attachment_id;
}

$created_posts = [];
$created_atts  = [];

update_option( 'olimc_scan_date', current_time( 'mysql' ), false );
OLIMC_Scanner::reset_upload_path_index();

$content_id = olimc_wp_inuse_make_attachment();
$created_atts[] = $content_id;
$created_posts[] = wp_insert_post(
	[
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'OLIMC content use',
		'post_content' => '<!-- wp:image {"id":' . $content_id . '} --><img class="wp-image-' . $content_id . '" src="' . esc_url( wp_get_attachment_url( $content_id ) ) . '" /><!-- /wp:image -->',
	]
);
olimc_wp_inuse_assert( ( new OLIMC_Scanner() )->is_attachment_in_use_now( $content_id ) === true, 'WP: content / block / URL is in use' );
olimc_wp_inuse_assert( OLIMC_Admin::must_keep_attachment_files( $content_id ) === true, 'WP: must_keep content-used media' );

$featured_id = olimc_wp_inuse_make_attachment();
$created_atts[] = $featured_id;
$created_posts[] = wp_insert_post(
	[
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'OLIMC featured',
		'post_content' => 'Hello',
	]
);
update_post_meta( end( $created_posts ), '_thumbnail_id', $featured_id );
olimc_wp_inuse_assert( ( new OLIMC_Scanner() )->is_attachment_in_use_now( $featured_id ) === true, 'WP: featured image is in use' );

$woo_id = olimc_wp_inuse_make_attachment();
$created_atts[] = $woo_id;
$created_posts[] = wp_insert_post(
	[
		'post_type'    => 'product',
		'post_status'  => 'publish',
		'post_title'   => 'OLIMC product',
		'post_content' => 'Product',
	]
);
update_post_meta( end( $created_posts ), '_product_image_gallery', (string) $woo_id );
olimc_wp_inuse_assert( ( new OLIMC_Scanner() )->is_attachment_in_use_now( $woo_id ) === true, 'WP: WooCommerce gallery is in use' );

if ( function_exists( 'acf_add_local_field_group' ) ) {
	acf_add_local_field_group(
		[
			'key'    => 'group_olimc_inuse',
			'title'  => 'OLIMC in-use',
			'fields' => [
				[
					'key'   => 'field_olimc_inuse_image',
					'label' => 'Image',
					'name'  => 'image',
					'type'  => 'image',
				],
			],
		]
	);
}
$acf_id = olimc_wp_inuse_make_attachment();
$created_atts[] = $acf_id;
$created_posts[] = wp_insert_post(
	[
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'OLIMC acf',
		'post_content' => 'ACF',
	]
);
update_post_meta( end( $created_posts ), 'image', (string) $acf_id );
update_post_meta( end( $created_posts ), '_image', 'field_olimc_inuse_image' );
olimc_wp_inuse_assert( ( new OLIMC_Scanner() )->is_attachment_in_use_now( $acf_id ) === true, 'WP: ACF image field is in use' );

$unused_id = olimc_wp_inuse_make_attachment();
$created_atts[] = $unused_id;
olimc_wp_inuse_assert( ( new OLIMC_Scanner() )->is_attachment_in_use_now( $unused_id ) === false, 'WP: unused attachment is not in use' );
olimc_wp_inuse_assert( OLIMC_Admin::must_keep_attachment_files( $unused_id ) === false, 'WP: unused media may be deleted after a scan' );

$scan_date = get_option( 'olimc_scan_date' );
delete_option( 'olimc_scan_date' );
add_filter(
	'olimc_must_keep_attachment',
	static function ( $keep, $post_id ) use ( $unused_id ) {
		if ( (int) $post_id === (int) $unused_id ) {
			return true;
		}
		return $keep;
	},
	10,
	2
);
olimc_wp_inuse_assert( OLIMC_Admin::must_keep_attachment_files( $unused_id ) === true, 'WP: olimc_must_keep_attachment filter' );
remove_all_filters( 'olimc_must_keep_attachment' );
if ( $scan_date ) {
	update_option( 'olimc_scan_date', $scan_date, false );
}

foreach ( $created_posts as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}
foreach ( $created_atts as $attachment_id ) {
	wp_delete_attachment( (int) $attachment_id, true );
}

if ( $failed > 0 ) {
	exit( 1 );
}

echo "Integration test passed for is_attachment_in_use_now / must_keep.\n";
