<?php
/**
 * Settings page handler.
 *
	 * Manages the plugin settings page for builder-specific settings.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_Admin_Settings_Page
 *
 * Handles the settings page for plugin configuration.
 *
 * @since 1.0.0
 */
class AMB_Admin_Settings_Page {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register plugin settings.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_settings() {
		// General settings.
		register_setting(
			'amb_settings_group',
			'amb_general',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_general_settings' ),
			)
		);
	}

	/**
	 * Sanitize general settings.
	 *
	 * @since  1.0.0
	 * @param  array $input Raw input data.
	 * @return array Sanitized settings.
	 */
	public function sanitize_general_settings( $input ) {
		$sanitized = array();

		$sanitized['post_types'] = isset( $input['post_types'] ) && is_array( $input['post_types'] )
			? array_map( 'sanitize_text_field', $input['post_types'] )
			: array( 'page', 'post' );

		$sanitized['role_access'] = isset( $input['role_access'] ) && is_array( $input['role_access'] )
			? array_map( 'sanitize_text_field', $input['role_access'] )
			: array( 'administrator', 'editor' );

		$sanitized['css_output'] = isset( $input['css_output'] )
			? sanitize_text_field( $input['css_output'] )
			: 'file';

		$sanitized['js_output'] = isset( $input['js_output'] )
			? sanitize_text_field( $input['js_output'] )
			: 'file';

		return $sanitized;
	}

	/**
	 * Render the settings page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access settings.', 'antimanual-builder' ) );
		}
		echo '<div class="amb-container"></div>';
	}
}
