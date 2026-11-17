<?php
/**
 * WordPress integration test: CPT event + ACF image meta must mark attachment as used.
 *
 * Usage (from site with plugin active):
 *   wp eval-file wp-content/plugins/oli-media-cleaner/tests/integration-wp-event-acf.php
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

if ( ! class_exists( 'OLIMC_Scanner' ) ) {
	fwrite( STDERR, "Oli Media Cleaner is not loaded.\n" );
	exit( 1 );
}

if ( function_exists( 'acf_add_local_field_group' ) ) {
	acf_add_local_field_group(
		[
			'key'      => 'group_olimc_test_event',
			'title'    => 'OLIMC test event',
			'fields'   => [
				[
					'key'   => 'field_olimc_test_image',
					'label' => 'Image',
					'name'  => 'image',
					'type'  => 'image',
				],
			],
			'location' => [
				[
					[
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'event',
					],
				],
			],
		]
	);
}

if ( ! post_type_exists( 'event' ) ) {
	register_post_type(
		'event',
		[
			'public' => false,
			'label'  => 'Event (OLIMC test)',
		]
	);
}

/**
 * @param int    $attachment_id Attachment ID.
 * @param string $format        id|array|url.
 * @return int Post ID.
 */
function olimc_test_create_event_with_image( $attachment_id, $format ) {
	$post_id = wp_insert_post(
		[
			'post_type'   => 'event',
			'post_status' => 'publish',
			'post_title'  => 'OLIMC test event ' . wp_generate_password( 4, false ),
		]
	);

	if ( ! $post_id ) {
		return 0;
	}

	update_post_meta( $post_id, '_image', 'field_olimc_test_image' );

	if ( 'id' === $format ) {
		update_post_meta( $post_id, 'image', (string) $attachment_id );
	} elseif ( 'array' === $format ) {
		update_post_meta(
			$post_id,
			'image',
			[
				'ID'  => $attachment_id,
				'id'  => $attachment_id,
				'url' => wp_get_attachment_url( $attachment_id ),
			]
		);
	} else {
		update_post_meta( $post_id, 'image', wp_get_attachment_url( $attachment_id ) );
	}

	return (int) $post_id;
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
$filename = 'olimc-test-' . wp_generate_password( 6, false ) . '.jpg';
$dest     = trailingslashit( $upload['path'] ) . $filename;
file_put_contents( $dest, 'fake' );
$attachment_id = wp_insert_attachment(
	[
		'post_mime_type' => 'image/jpeg',
		'post_title'     => 'OLIMC test attachment',
		'post_status'    => 'inherit',
		'guid'           => trailingslashit( $upload['url'] ) . $filename,
	],
	$dest
);
require_once ABSPATH . 'wp-admin/includes/image.php';
$relative = _wp_relative_upload_path( $dest );
if ( ! is_string( $relative ) || $relative === '' || strpos( $relative, '..' ) !== false ) {
	fwrite( STDERR, "Attachment file is not inside the upload directory.\n" );
	exit( 1 );
}
update_post_meta( $attachment_id, '_wp_attached_file', $relative );
wp_update_attachment_metadata( $attachment_id, [ 'file' => $relative ] );

$formats = [ 'id', 'array', 'url' ];
$failed  = 0;

foreach ( $formats as $format ) {
	$event_id = olimc_test_create_event_with_image( $attachment_id, $format );
	$scanner    = new OLIMC_Scanner();
	$used       = $scanner->collect_used_ids();
	$ok         = in_array( (int) $attachment_id, $used, true );
	if ( ! $ok ) {
		echo "FAIL: attachment {$attachment_id} not detected with format {$format}\n";
		$failed++;
	} else {
		echo "OK: format {$format}\n";
	}
	wp_delete_post( $event_id, true );
}

wp_delete_attachment( $attachment_id, true );

if ( $failed > 0 ) {
	exit( 1 );
}

echo "Integration test passed for all ACF image return formats.\n";
