<?php
/**
 * Reproducible tests for ACF / meta attachment detection (no full WordPress required for parser tests).
 *
 * Usage: php tests/run-tests.php
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
$root = dirname( __DIR__ );
require_once $root . '/includes/class-media-usage-parser.php';

$failures = 0;

/**
 * @param bool   $condition Condition.
 * @param string $message   Message.
 */
function olimc_test_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		echo "FAIL: {$message}\n";
		$failures++;
		return;
	}
	echo "OK: {$message}\n";
}

OLIMC_Media_Usage_Parser::set_upload_markers(
	[
		'https://example.com/wp-content/uploads',
		'//example.com/wp-content/uploads',
		'/wp-content/uploads',
	]
);

$known_attachments = [ 61273 => true, 61309 => true, 99 => true, 37455 => true ];
$resolve_path      = function ( $path ) {
	if ( strpos( $path, 'concert-poster' ) !== false ) {
		return 61273;
	}
	return 0;
};
$is_attachment = function ( $id ) use ( $known_attachments ) {
	return isset( $known_attachments[ (int) $id ] );
};

// Concert CPT — ACF image return format: attachment ID.
$ids = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( '61273', $resolve_path, $is_attachment );
olimc_test_assert( in_array( 61273, $ids, true ), 'concert ACF image field (ID return format)' );

// Concert — ACF image array return format (badge image).
$array_value = serialize(
	[
		'ID'    => 61273,
		'id'    => 61273,
		'title' => 'Badge',
		'url'   => 'https://example.com/wp-content/uploads/2026/02/concert-poster-1080-fr.jpg',
	]
);
$ids = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( $array_value, $resolve_path, $is_attachment );
olimc_test_assert( in_array( 61273, $ids, true ), 'concert ACF image field (array return format)' );

// Concert — URL return format.
$url_value = 'https://example.com/wp-content/uploads/2026/02/concert-poster-1080-fr.jpg';
$ids       = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( $url_value, $resolve_path, $is_attachment );
olimc_test_assert( in_array( 61273, $ids, true ), 'concert ACF image field (URL return format)' );

// Gallery: serialized list of IDs (regression — previously ignored).
$gallery = serialize( [ 61273, 61309 ] );
$ids     = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( $gallery, $resolve_path, $is_attachment );
olimc_test_assert( count( $ids ) === 2, 'ACF gallery serialized ID list' );

// ACF field reference detection.
olimc_test_assert(
	OLIMC_Media_Usage_Parser::is_acf_field_reference( '_image', 'field_abc123' ),
	'ACF reference meta _image => field_xxx'
);
olimc_test_assert(
	! OLIMC_Media_Usage_Parser::is_acf_field_reference( '_thumbnail_id', '61273' ),
	'WordPress _thumbnail_id is not an ACF reference'
);
olimc_test_assert(
	OLIMC_Media_Usage_Parser::acf_value_key_from_reference( '_image' ) === 'image',
	'ACF value key from reference'
 );

// Repeater row meta shape.
$repeater_value = '61309';
$ids            = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( $repeater_value, $resolve_path, $is_attachment );
olimc_test_assert( in_array( 61309, $ids, true ), 'nested repeater image sub-field (numeric ID)' );

// HTML must not keep the tag, query, or fragment after the upload URL.
$pdf_path = '2025/12/16_12_2025_programme-excerpts-2026.pdf';
$photo    = '2026/02/photo.jpg';
$html_cases = [
	'<a href="https://example.com/wp-content/uploads/' . $pdf_path . '">extraits</a>' => [ $pdf_path ],
	'<img src="https://example.com/wp-content/uploads/' . $photo . '" alt="">'          => [ $photo ],
	"<img src='https://example.com/wp-content/uploads/{$photo}' alt=''>"               => [ $photo ],
	'<img srcset="https://example.com/wp-content/uploads/' . $photo . ' 1080w, https://example.com/wp-content/uploads/2026/02/photo-300x300.jpg 300w">' => [ $photo, '2026/02/photo-300x300.jpg' ],
	'See <https://example.com/wp-content/uploads/' . $photo . '>'                       => [ $photo ],
	'url(https://example.com/wp-content/uploads/' . $photo . ')'                        => [ $photo ],
	'https://example.com/wp-content/uploads/' . $photo . '?ver=2'                       => [ $photo ],
	'https://example.com/wp-content/uploads/' . $photo . '#page=2'                      => [ $photo ],
];
foreach ( $html_cases as $html => $expected_paths ) {
	$paths = OLIMC_Media_Usage_Parser::relative_upload_paths( $html );
	sort( $paths );
	$expect = $expected_paths;
	sort( $expect );
	olimc_test_assert( $paths === $expect, 'upload path stops at HTML delimiter: ' . $html );
}
$resolve_pdf = function ( $path ) use ( $pdf_path ) {
	return $path === $pdf_path ? 57912 : 0;
};
$linked = '<a href="https://example.com/wp-content/uploads/' . $pdf_path . '">extraits</a>';
$ids    = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( $linked, $resolve_pdf, $is_attachment );
olimc_test_assert( $ids === [ 57912 ], 'linked PDF in published HTML resolves to attachment 57912' );

// Bedrock serves /app/uploads. Markers come from wp_upload_dir(), never a fixed directory.
$bedrock = OLIMC_Media_Usage_Parser::markers_from_upload_dir(
	[
		'baseurl' => 'https://example.com/app/uploads',
		'basedir' => '/srv/www/example.com/app/uploads',
	]
);
olimc_test_assert( in_array( 'https://example.com/app/uploads', $bedrock, true ), 'Bedrock baseurl is an upload marker' );
olimc_test_assert( in_array( '//example.com/app/uploads', $bedrock, true ), 'Bedrock protocol-less baseurl is an upload marker' );
olimc_test_assert( in_array( '/app/uploads', $bedrock, true ), 'Bedrock URL path is an upload marker' );
olimc_test_assert( in_array( '/srv/www/example.com/app/uploads', $bedrock, true ), 'Bedrock basedir is an upload marker' );

$multisite = OLIMC_Media_Usage_Parser::markers_from_upload_dir(
	[
		'baseurl' => 'https://example.com/app/uploads/sites/3',
		'basedir' => '/srv/www/example.com/app/uploads/sites/3',
	]
);
olimc_test_assert( in_array( 'https://example.com/app/uploads', $multisite, true ), 'multisite marker includes the network upload root' );
olimc_test_assert( in_array( '/app/uploads', $multisite, true ), 'multisite marker includes the network upload path' );

OLIMC_Media_Usage_Parser::set_upload_markers( $bedrock );
$resolve_bedrock = function ( $path ) {
	if ( $path === '2024/06/tour-poster.jpg' || $path === 'sites/3/2024/06/tour-poster.jpg' ) {
		return 37455;
	}
	return 0;
};
$bedrock_url = 'https://example.com/app/uploads/2024/06/tour-poster.jpg';
$ids         = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( $bedrock_url, $resolve_bedrock, $is_attachment );
olimc_test_assert( in_array( 37455, $ids, true ), 'ACF image field (URL return format, Bedrock /app/uploads)' );

$protocol_less = '//example.com/app/uploads/2024/06/tour-poster.jpg';
$ids           = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( $protocol_less, $resolve_bedrock, $is_attachment );
olimc_test_assert( in_array( 37455, $ids, true ), 'protocol-less Bedrock upload URL' );

$relative = 'See /app/uploads/2024/06/tour-poster.jpg in the page.';
$ids      = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( $relative, $resolve_bedrock, $is_attachment );
olimc_test_assert( in_array( 37455, $ids, true ), 'relative Bedrock upload path' );

OLIMC_Media_Usage_Parser::set_upload_markers( $multisite );
$site_url = 'https://example.com/app/uploads/sites/3/2024/06/tour-poster.jpg';
$ids      = OLIMC_Media_Usage_Parser::extract_attachment_ids_from_value( $site_url, $resolve_bedrock, $is_attachment );
olimc_test_assert( in_array( 37455, $ids, true ), 'multisite upload URL resolves with or without the sites prefix' );

if ( $failures > 0 ) {
	echo "\n{$failures} test(s) failed.\n";
	exit( 1 );
}

echo "\nAll parser tests passed.\n";
exit( 0 );
