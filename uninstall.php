<?php
/**
 * Uninstall handler.
 *
 * Fires when the plugin is deleted via the WordPress admin.
 * Cleans up all plugin data including options, post types,
 * database tables, and uploaded assets.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete all plugin data.
 */
function amb_uninstall() {
	global $wpdb;

	$builder_post_types = array( 'amb_page', 'amb_component' );
	foreach ( $builder_post_types as $post_type ) {
		$post_ids = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $post_ids as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	}

	$meta_keys = array(
		'_amb_builder_enabled',
		'_amb_blocks',
		'_amb_rendered_html',
		'_amb_custom_css',
		'_amb_page_settings',
		'_amb_component_type',
		'_amb_design_overrides',
	);

	$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})",
			$meta_keys
		)
	);

	// Delete plugin options.
	$options = array(
		'amb_version',
		'amb_activated_at',
		'amb_ai_settings',
		'amb_general',
		'amb_rewrite_flushed',
		'amb_design_defaults',
		'amb_migration_mode',
		'amb_migration_behavior',
		'amb_pages_list_cache_version',
		'amb_components_list_cache_version',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Delete transients.
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_amb_%' OR option_name LIKE '%_transient_timeout_amb_%'"
	);

	// Drop custom tables.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}amb_assets" );

	// Delete uploaded assets.
	$upload_dir = wp_upload_dir();
	$amb_dir    = trailingslashit( $upload_dir['basedir'] ) . 'antimanual-builder';
	$generated_css_dir = trailingslashit( $upload_dir['basedir'] ) . 'am-builder';

	if ( is_dir( $amb_dir ) ) {
		amb_delete_directory( $amb_dir );
	}

	if ( is_dir( $generated_css_dir ) ) {
		amb_delete_directory( $generated_css_dir );
	}

	// Flush rewrite rules.
	flush_rewrite_rules();
}

/**
 * Recursively delete a directory and its contents.
 *
 * @param string $dir Directory path.
 * @return void
 */
function amb_delete_directory( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$files = array_diff( scandir( $dir ), array( '.', '..' ) );

	foreach ( $files as $file ) {
		$path = trailingslashit( $dir ) . $file;
		if ( is_dir( $path ) ) {
			amb_delete_directory( $path );
		} else {
			wp_delete_file( $path );
		}
	}

	global $wp_filesystem;

	if ( empty( $wp_filesystem ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}

	if ( $wp_filesystem ) {
		$wp_filesystem->rmdir( $dir );
	}
}

amb_uninstall();
