<?php
/**
 * REST API base handler.
 *
 * Registers all REST API routes for the plugin by initializing
 * individual endpoint controllers.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_API_Rest_Api
 *
 * Bootstraps all REST API controllers.
 *
 * @since 1.0.0
 */
class AMB_API_Rest_Api {

	/**
	 * REST namespace.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const NAMESPACE = 'amb/v1';

	/**
	 * Constructor. Registers the REST API initialization hook.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all API routes.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_routes() {
		// Include Chat API manually (non-standard class naming).
		require_once AMB_PLUGIN_DIR . 'includes/api/class-chat-api.php';
		
		// Include Builder Meta Trait for shared saving logic.
		require_once AMB_PLUGIN_DIR . 'includes/api/trait-builder-meta-api.php';

		// Include Dependency API for plugin activation.
		require_once AMB_PLUGIN_DIR . 'includes/api/class-dependency-api.php';

		$controllers = array(
			new AMB_API_Pages_Api(),
			new AMB_API_Ai_Api(),
			new AMB_API_Components_Api(),
			new AMB_API_Chat_Api(),
			new AMB_API_Dependency_Api(),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
