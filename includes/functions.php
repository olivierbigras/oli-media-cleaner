<?php
/**
 * Small helpers shared by the scanner classes.
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'olimc_prepare' ) ) {
	/**
	 * wpdb::prepare() with %i (identifier) support on every WordPress version.
	 *
	 * WordPress added %i in 6.2. This plugin supports 5.8, so identifiers are
	 * validated here (letters, digits, underscore, dollar) and written between
	 * backticks; every other placeholder goes to wpdb::prepare() unchanged.
	 *
	 * @param string $query Query with %s, %d, %f and %i placeholders.
	 * @param mixed  ...$args Values, in placeholder order (or one array of them).
	 * @return string Prepared SQL, or an empty string when an identifier is unsafe.
	 */
	function olimc_prepare( $query, ...$args ) {
		global $wpdb;
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$tokens = preg_split( '/(%%|%[sdfi])/', (string) $query, -1, PREG_SPLIT_DELIM_CAPTURE );
		$sql    = '';
		$values = [];
		$i      = 0;
		foreach ( $tokens as $token ) {
			if ( '%i' === $token ) {
				$ident = isset( $args[ $i ] ) ? (string) $args[ $i ] : '';
				++$i;
				if ( '' === $ident || ! preg_match( '/^[A-Za-z0-9_$]+$/', $ident ) ) {
					return '';
				}
				$sql .= '`' . $ident . '`';
			} elseif ( '%s' === $token || '%d' === $token || '%f' === $token ) {
				$values[] = isset( $args[ $i ] ) ? $args[ $i ] : null;
				++$i;
				$sql .= $token;
			} else {
				$sql .= $token;
			}
		}
		if ( [] === $values ) {
			return str_replace( '%%', '%', $sql );
		}
		return (string) $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are preserved above.
	}
}

if ( ! function_exists( 'olimc_filesize' ) ) {
	/**
	 * File size in bytes, 0 when the file does not exist (wp_filesize() needs WordPress 6.0).
	 *
	 * @param string $path Absolute path.
	 * @return int
	 */
	function olimc_filesize( $path ) {
		if ( function_exists( 'wp_filesize' ) ) {
			return (int) wp_filesize( $path );
		}
		return is_file( $path ) ? (int) filesize( $path ) : 0;
	}
}
