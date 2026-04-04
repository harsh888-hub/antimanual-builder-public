<?php
/**
 * Plugin activator.
 *
 * Runs on plugin activation to set up necessary database tables,
 * directories, and default options.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_Activator
 *
 * Handles all plugin activation tasks.
 *
 * @since 1.0.0
 */
class AMB_Activator {

	/**
	 * Run activation tasks.
	 *
	 * Creates upload directories, sets default options,
	 * and flushes rewrite rules.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function activate() {
		self::create_upload_directories();
		self::set_default_options();
		self::create_tables();

		$page_integration = new AMB_Page_Integration();
		$page_integration->register_meta();

		$component_pt = new AMB_Post_Type_Builder_Component();
		$component_pt->register();

		// Flush rewrite rules after registering post types.
		flush_rewrite_rules();

		// Store the plugin version for future upgrade checks.
		update_option( 'amb_version', AMB_VERSION );

		// Set activation timestamp.
		if ( ! get_option( 'amb_activated_at' ) ) {
			update_option( 'amb_activated_at', current_time( 'mysql' ) );
		}
	}

	/**
	 * Create upload directories for generated assets.
	 *
	 * Creates the directory structure:
	 *   wp-content/uploads/antimanual-builder/
	 *   wp-content/uploads/antimanual-builder/css/
	 *   wp-content/uploads/antimanual-builder/js/
	 *   wp-content/uploads/antimanual-builder/images/
	 *   wp-content/uploads/am-builder/css/
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private static function create_upload_directories() {
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] ) . AMB_UPLOAD_DIR;

		$directories = array(
			$base_dir,
			$base_dir . '/css',
			$base_dir . '/js',
			$base_dir . '/images',
			trailingslashit( $upload_dir['basedir'] ) . 'am-builder',
			trailingslashit( $upload_dir['basedir'] ) . 'am-builder/css',
		);

		foreach ( $directories as $dir ) {
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			// Create an index.php to prevent directory listing.
			$index_file = trailingslashit( $dir ) . 'index.php';
			if ( ! file_exists( $index_file ) ) {
				file_put_contents( $index_file, '<?php // Silence is golden.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * Set default plugin options.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private static function set_default_options() {
		$defaults = array(
			'amb_general'        => array(
				'post_types'  => array( 'page', 'post' ),
				'role_access' => array( 'administrator', 'editor' ),
				'css_output'  => 'file',
				'js_output'   => 'file',
			),
		);

		foreach ( $defaults as $option_name => $option_value ) {
			if ( false === get_option( $option_name ) ) {
				add_option( $option_name, $option_value );
			}
		}
	}

	/**
	 * Create custom database tables if needed.
	 *
	 * Creates a table for tracking generated assets and their versions.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'amb_assets';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			asset_type varchar(10) NOT NULL DEFAULT 'css',
			file_path varchar(500) NOT NULL,
			file_url varchar(500) NOT NULL,
			version varchar(32) NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY asset_type (asset_type)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
