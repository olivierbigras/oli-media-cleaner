<?php
/**
 * WP-CLI command of the plugin. There is no screen for it on purpose.
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Oli Media Cleaner.
 */
class OLIMC_CLI {

	/**
	 * Replaces the random folder name of the quarantine (after a leak of a database copy, an export
	 * or a backup). Renames the folder and points every trashed media at it. Run it again if it was
	 * interrupted; use --url=<site> on a multisite.
	 *
	 * ## EXAMPLES
	 *
	 *     wp olimc rotate-token
	 *
	 * @subcommand rotate-token
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function rotate_token( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		$res = OLIMC_Quarantine::rotate_token();
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Folder token replaced. %d trashed media updated.', (int) $res['media'] ) );
	}
}

WP_CLI::add_command( 'olimc', 'OLIMC_CLI' );
