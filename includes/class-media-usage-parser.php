<?php
/**
 * Parse post/term/user/option meta values for attachment usage (ACF-agnostic).
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extract attachment IDs from stored meta values.
 */
class OLIMC_Media_Usage_Parser {

	/**
	 * Optional marker list for tests. Null uses wp_upload_dir().
	 *
	 * @var string[]|null
	 */
	private static $marker_override = null;

	/**
	 * @param string[]|null $markers Upload base URLs or directories. Null restores wp_upload_dir().
	 */
	public static function set_upload_markers( $markers ) {
		self::$marker_override = is_array( $markers ) ? array_values( $markers ) : null;
	}

	/**
	 * Base URL, protocol-less URL, URL path, and basedir for the current site.
	 *
	 * Multisite bases that end in /sites/{id} also contribute the network upload root.
	 * Nothing here is a fixed wp-content path.
	 *
	 * @param array $uploads Keys baseurl and basedir, as returned by wp_upload_dir().
	 * @return string[]
	 */
	public static function markers_from_upload_dir( array $uploads ) {
		$markers = [];
		foreach ( [ 'baseurl', 'basedir' ] as $key ) {
			if ( empty( $uploads[ $key ] ) || ! is_string( $uploads[ $key ] ) ) {
				continue;
			}
			$value = rtrim( $uploads[ $key ], "/\\" );
			if ( $value === '' ) {
				continue;
			}
			$markers[] = $value;
			if ( $key === 'baseurl' ) {
				$protocol_less = preg_replace( '#^https?:#', '', $value );
				if ( is_string( $protocol_less ) && $protocol_less !== '' && $protocol_less !== $value ) {
					$markers[] = $protocol_less;
				}
				$path = wp_parse_url( $value, PHP_URL_PATH );
				if ( is_string( $path ) && $path !== '' && $path !== '/' ) {
					$markers[] = rtrim( $path, '/' );
				}
			}
		}
		foreach ( $markers as $marker ) {
			if ( preg_match( '#^(.*?)/sites/\d+$#', rtrim( $marker, '/' ), $match ) && $match[1] !== '' ) {
				$markers[] = $match[1];
			}
		}
		return array_values( array_unique( $markers ) );
	}

	/**
	 * @return string[]
	 */
	public static function upload_location_markers() {
		if ( is_array( self::$marker_override ) ) {
			return self::$marker_override;
		}
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return [];
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) ) {
			return [];
		}
		return self::markers_from_upload_dir( $uploads );
	}

	/**
	 * Shortest upload path, for a single LIKE that matches http, https, and protocol-less URLs.
	 *
	 * @return string
	 */
	public static function upload_search_fragment() {
		$best = '';
		foreach ( self::upload_location_markers() as $marker ) {
			$path = $marker;
			if ( preg_match( '#^https?:#', $marker ) || strpos( $marker, '//' ) === 0 ) {
				$parsed = wp_parse_url( strpos( $marker, '//' ) === 0 ? 'http:' . $marker : $marker, PHP_URL_PATH );
				$path   = is_string( $parsed ) ? $parsed : '';
			}
			$path = rtrim( (string) $path, "/\\" );
			if ( $path !== '' && ( $best === '' || strlen( $path ) < strlen( $best ) ) ) {
				$best = $path;
			}
		}
		return $best;
	}

	/**
	 * @param string $text Text that may contain an upload URL or path.
	 * @return string[] Paths relative to the uploads directory.
	 */
	public static function relative_upload_paths( $text ) {
		if ( ! is_string( $text ) || $text === '' ) {
			return [];
		}
		$markers = self::upload_location_markers();
		usort(
			$markers,
			function ( $left, $right ) {
				return strlen( (string) $right ) - strlen( (string) $left );
			}
		);
		$found = [];
		foreach ( $markers as $marker ) {
			$marker = rtrim( (string) $marker, '/' );
			if ( $marker === '' ) {
				continue;
			}
			// Stop at the first quote, tag, parenthesis, query, or fragment.
			// A run of non-whitespace keeps the rest of the HTML (href="file.pdf">text).
			$pattern = '~' . preg_quote( $marker, '~' ) . '/([^\s"\'<>)?#,;}]+)~';
			if ( ! preg_match_all( $pattern, $text, $matches ) ) {
				continue;
			}
			foreach ( $matches[1] as $path ) {
				$path = rawurldecode( (string) $path );
				$path = ltrim( $path, '/' );
				if ( $path !== '' ) {
					$found[] = $path;
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * @param string $text Text.
	 * @return bool
	 */
	public static function text_references_uploads( $text ) {
		return self::relative_upload_paths( $text ) !== [];
	}

	private static $non_acf_reference_keys = [
		'_thumbnail_id',
		'_wp_page_template',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_image_alt',
		'_edit_lock',
		'_edit_last',
		'_encloseme',
		'_pingme',
		'_elementor_data',
		'_elementor_css',
		'_product_image_gallery',
	];

	/**
	 * Whether a meta row is an ACF field reference (_fieldname => field_xxx).
	 *
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return bool
	 */
	public static function is_acf_field_reference( $meta_key, $meta_value ) {
		if ( ! is_string( $meta_key ) || strlen( $meta_key ) < 2 || $meta_key[0] !== '_' ) {
			return false;
		}
		if ( in_array( $meta_key, self::$non_acf_reference_keys, true ) ) {
			return false;
		}
		if ( ! is_string( $meta_value ) ) {
			return false;
		}
		return strncmp( $meta_value, 'field_', 6 ) === 0;
	}

	/**
	 * Value meta key paired with an ACF reference key (_image => image).
	 *
	 * @param string $reference_key Meta key starting with underscore.
	 * @return string
	 */
	public static function acf_value_key_from_reference( $reference_key ) {
		return substr( $reference_key, 1 );
	}

	/**
	 * Extract attachment IDs from a single meta value.
	 *
	 * @param mixed    $value            Raw meta value.
	 * @param callable $resolve_path     function( string $upload_relative_path ): int attachment ID or 0.
	 * @param callable $is_attachment_id function( int $id ): bool.
	 * @return int[]
	 */
	public static function extract_attachment_ids_from_value( $value, callable $resolve_path, callable $is_attachment_id ) {
		$found = [];

		if ( is_numeric( $value ) ) {
			$id = (int) $value;
			if ( $id > 0 && $is_attachment_id( $id ) ) {
				$found[] = $id;
			}
			return $found;
		}

		if ( ! is_string( $value ) || $value === '' ) {
			return $found;
		}

		// Comma-separated IDs (galleries stored as plain string).
		if ( preg_match( '/^[0-9]+(,[0-9]+)*$/', $value ) ) {
			foreach ( explode( ',', $value ) as $part ) {
				$id = (int) $part;
				if ( $id > 0 && $is_attachment_id( $id ) ) {
					$found[] = $id;
				}
			}
			return $found;
		}

		// URL string return format (site upload base, not a fixed directory).
		if ( self::text_references_uploads( $value ) ) {
			$found = array_merge( $found, self::extract_ids_from_uploads_string( $value, $resolve_path ) );
		}

		// Serialized PHP.
		if ( self::looks_serialized( $value ) ) {
			$data = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $value ) : @unserialize( $value );
			if ( is_array( $data ) ) {
				$found = array_merge( $found, self::extract_attachment_ids_from_array( $data, $resolve_path, $is_attachment_id ) );
			}
			return array_values( array_unique( array_map( 'intval', $found ) ) );
		}

		// JSON (some exports / plugins).
		if ( strlen( $value ) > 0 && ( $value[0] === '[' || $value[0] === '{' ) ) {
			$data = json_decode( $value, true );
			if ( is_array( $data ) ) {
				$found = array_merge( $found, self::extract_attachment_ids_from_array( $data, $resolve_path, $is_attachment_id ) );
			}
		}

		return array_values( array_unique( array_map( 'intval', $found ) ) );
	}

	/**
	 * Recursively walk arrays (ACF image array, gallery, repeater rows).
	 *
	 * @param array    $data             Data.
	 * @param callable $resolve_path     Path resolver.
	 * @param callable $is_attachment_id Attachment validator.
	 * @return int[]
	 */
	public static function extract_attachment_ids_from_array( array $data, callable $resolve_path, callable $is_attachment_id ) {
		$found = [];

		foreach ( $data as $key => $item ) {
			if ( is_array( $item ) ) {
				if ( isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
					$id = (int) $item['id'];
					if ( $id > 0 && $is_attachment_id( $id ) ) {
						$found[] = $id;
					}
				}
				if ( isset( $item['ID'] ) && is_numeric( $item['ID'] ) ) {
					$id = (int) $item['ID'];
					if ( $id > 0 && $is_attachment_id( $id ) ) {
						$found[] = $id;
					}
				}
				if ( isset( $item['url'] ) && is_string( $item['url'] ) && self::text_references_uploads( $item['url'] ) ) {
					$found = array_merge( $found, self::extract_ids_from_uploads_string( $item['url'], $resolve_path ) );
				}
				$found = array_merge( $found, self::extract_attachment_ids_from_array( $item, $resolve_path, $is_attachment_id ) );
			} elseif ( is_numeric( $item ) ) {
				$id = (int) $item;
				if ( $id > 0 && $is_attachment_id( $id ) ) {
					$found[] = $id;
				}
			} elseif ( is_string( $item ) && self::text_references_uploads( $item ) ) {
				$found = array_merge( $found, self::extract_ids_from_uploads_string( $item, $resolve_path ) );
			}
		}

		return $found;
	}

	/**
	 * @param string   $text         Text containing upload URLs.
	 * @param callable $resolve_path Path resolver.
	 * @return int[]
	 */
	public static function extract_ids_from_uploads_string( $text, callable $resolve_path ) {
		$found = [];
		foreach ( self::relative_upload_paths( $text ) as $path ) {
			$id = self::resolve_upload_relative_path( $path, $resolve_path );
			if ( $id > 0 ) {
				$found[] = $id;
			}
		}
		return $found;
	}

	/**
	 * Resolve a path relative to the upload root. Also tries without a multisite sites/{id}/ prefix.
	 *
	 * @param string   $path         Relative path.
	 * @param callable $resolve_path Resolver.
	 * @return int
	 */
	private static function resolve_upload_relative_path( $path, callable $resolve_path ) {
		$id = (int) $resolve_path( $path );
		if ( $id <= 0 && preg_match( '#^sites/\d+/(.+)$#', $path, $match ) ) {
			$id = (int) $resolve_path( $match[1] );
		}
		return $id;
	}

	/**
	 * @param string $value Meta value.
	 * @return bool
	 */
	public static function looks_serialized( $value ) {
		return is_string( $value ) && strlen( $value ) > 1 && ( $value[0] === 'a' || $value[0] === 'O' ) && strpos( $value, ':' ) !== false;
	}
}
