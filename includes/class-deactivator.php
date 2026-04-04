<?php
/**
 * Plugin deactivator.
 *
 * Runs on plugin deactivation to clean up temporary data.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_Deactivator
 *
 * Handles all plugin deactivation tasks.
 *
 * @since 1.0.0
 */
class AMB_Deactivator {

	/**
	 * Run deactivation tasks.
	 *
	 * Flushes rewrite rules and cleans up transients.
	 * Does NOT delete user data or settings — that's handled by uninstall.php.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function deactivate() {
		// Flush rewrite rules.
		flush_rewrite_rules();

		// Clean up any transients.
		self::clean_transients();
	}

	/**
	 * Delete plugin transients.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private static function clean_transients() {
		global $wpdb;

		// Delete all transients with our prefix.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'%_transient_amb_%',
				'%_transient_timeout_amb_%'
			)
		);
	}
}
