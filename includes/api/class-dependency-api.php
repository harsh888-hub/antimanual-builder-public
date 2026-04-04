<?php
/**
 * Dependency management REST API endpoint.
 *
 * Provides an AJAX endpoint to activate the Antimanual plugin
 * from within the editor without navigating away.
 *
 * @package Antimanual_Builder
 * @since   1.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_API_Dependency_Api
 *
 * Handles plugin activation via REST API so the editor modal
 * can stay in context and show post-activation guidance.
 *
 * @since 1.1.0
 */
class AMB_API_Dependency_Api {

	/**
	 * Register REST routes.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			AMB_API_Rest_Api::NAMESPACE,
			'/activate-dependency',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'activate_dependency' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'plugin' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function( $value ) {
							return 'antimanual' === $value;
						},
					),
				),
			)
		);
	}

	/**
	 * Permission check — requires activate_plugins capability.
	 *
	 * @since  1.1.0
	 * @return bool|\WP_Error
	 */
	public function check_permissions() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to activate plugins.', 'antimanual-builder' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Activate the Antimanual dependency plugin.
	 *
	 * @since  1.1.0
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function activate_dependency( $request ) {
		$plugin_slug = sanitize_text_field( $request->get_param( 'plugin' ) );

		if ( 'antimanual' !== $plugin_slug ) {
			return new \WP_Error(
				'invalid_plugin',
				__( 'Only the Antimanual plugin can be activated through this endpoint.', 'antimanual-builder' ),
				array( 'status' => 400 )
			);
		}

		$plugin_file = 'antimanual/antimanual.php';

		// Check if plugin files exist on disk.
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
			return new \WP_Error(
				'plugin_not_found',
				__( 'The Antimanual plugin is not installed. Please install it first.', 'antimanual-builder' ),
				array( 'status' => 404 )
			);
		}

		// Check if already active.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( $plugin_file ) ) {
			return rest_ensure_response( array(
				'activated'       => true,
				'knowledgeBase'   => AMB_Antimanual_Knowledge_Base::get_payload(),
			) );
		}

		// Attempt activation.
		$result = activate_plugin( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				'activation_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'activated'       => true,
			'knowledgeBase'   => AMB_Antimanual_Knowledge_Base::get_payload(),
		) );
	}
}
