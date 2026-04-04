<?php
/**
 * Antimanual Knowledge Base bridge.
 *
 * Exposes the external Antimanual Knowledge Base as the single KB source
 * for Antimanual Builder.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_Antimanual_Knowledge_Base
 */
class AMB_Antimanual_Knowledge_Base {

	/**
	 * Get the Antimanual plugin file path relative to the plugins directory.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	private static function get_plugin_file() {
		return 'antimanual/antimanual.php';
	}

	/**
	 * Check whether the Antimanual plugin files exist on disk.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	private static function plugin_exists() {
		return file_exists( WP_PLUGIN_DIR . '/' . self::get_plugin_file() );
	}

	/**
	 * Check whether the Antimanual plugin is installed.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	public static function is_installed() {
		return self::plugin_exists();
	}

	/**
	 * Check whether the Antimanual plugin is active.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	public static function is_active() {
		if ( defined( 'ANTIMANUAL_VERSION' ) ) {
			return true;
		}

		if ( ! self::plugin_exists() ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return function_exists( 'is_plugin_active' ) && is_plugin_active( self::get_plugin_file() );
	}

	/**
	 * Check whether the Antimanual Knowledge Base is available for use.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	public static function is_available() {
		if ( ! self::is_active() || ! class_exists( '\Antimanual\KnowledgeContextBuilder' ) ) {
			return false;
		}

		if ( function_exists( 'atml_is_module_enabled' ) ) {
			return (bool) atml_is_module_enabled( 'knowledge_base' );
		}

		return true;
	}

	/**
	 * Get the Antimanual Knowledge Base admin URL.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public static function get_admin_url() {
		return admin_url( 'admin.php?page=atml-knowledge-base' );
	}

	/**
	 * Get the Antimanual plugin admin URL.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public static function get_plugin_admin_url() {
		return admin_url( 'admin.php?page=antimanual' );
	}

	/**
	 * Get the Antimanual Knowledge Base REST URL.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public static function get_rest_url() {
		return rest_url( 'antimanual/v1/' );
	}

	/**
	 * Get the current Knowledge Base availability status.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public static function get_status() {
		if ( ! self::is_installed() ) {
			return 'not_installed';
		}

		if ( ! self::is_active() ) {
			return 'inactive';
		}

		if ( ! self::is_available() ) {
			return 'module_disabled';
		}

		if ( self::get_total_items() <= 0 ) {
			return 'empty';
		}

		return 'ready';
	}

	/**
	 * Get a short status message for UI surfaces.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public static function get_status_message() {
		$status = self::get_status();

		if ( 'not_installed' === $status ) {
			return __( 'Install and activate the Antimanual plugin to use the Knowledge Base in AM Builder.', 'antimanual-builder' );
		}

		if ( 'inactive' === $status ) {
			return __( 'Activate the Antimanual plugin to use the Knowledge Base in AM Builder.', 'antimanual-builder' );
		}

		if ( 'module_disabled' === $status ) {
			return __( 'Enable the Knowledge Base module in Antimanual to use it in AM Builder.', 'antimanual-builder' );
		}

		if ( 'empty' === $status ) {
			return __( 'Add content in Antimanual -> Knowledge Base before attaching it in AM Builder.', 'antimanual-builder' );
		}

		return '';
	}

	/**
	 * Check whether the current user can manage the dependency.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	private static function can_manage_dependency() {
		return current_user_can( 'install_plugins' ) || current_user_can( 'activate_plugins' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Get the primary dependency action URL for the current status.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	private static function get_primary_action_url() {
		if ( ! self::can_manage_dependency() ) {
			return '';
		}

		$status = self::get_status();

		if ( 'not_installed' === $status && current_user_can( 'install_plugins' ) ) {
			return wp_nonce_url(
				self_admin_url( 'update.php?action=install-plugin&plugin=antimanual' ),
				'install-plugin_antimanual'
			);
		}

		if ( 'inactive' === $status && current_user_can( 'activate_plugins' ) ) {
			$url = add_query_arg(
				array(
					'action' => 'activate',
					'plugin' => self::get_plugin_file(),
				),
				self_admin_url( 'plugins.php' )
			);

			return wp_nonce_url(
				$url,
				'activate-plugin_' . self::get_plugin_file()
			);
		}

		if ( current_user_can( 'manage_options' ) ) {
			return self::get_plugin_admin_url();
		}

		return '';
	}

	/**
	 * Get the primary dependency action label for the current status.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	private static function get_primary_action_label() {
		$status = self::get_status();

		if ( 'not_installed' === $status ) {
			return __( 'Install Antimanual', 'antimanual-builder' );
		}

		if ( 'inactive' === $status ) {
			return __( 'Activate Antimanual', 'antimanual-builder' );
		}

		return __( 'Open Antimanual', 'antimanual-builder' );
	}

	/**
	 * Get the total number of indexed Antimanual KB sources.
	 *
	 * @since  1.0.0
	 * @return int
	 */
	public static function get_total_items() {
		if ( ! self::is_available() || ! class_exists( '\Antimanual\Embedding' ) ) {
			return 0;
		}

		$stats = \Antimanual\Embedding::get_stats();

		if ( ! is_array( $stats ) ) {
			return 0;
		}

		return array_reduce(
			$stats,
			static function( $total, $item ) {
				return $total + (int) ( $item['total'] ?? 0 );
			},
			0
		);
	}

	/**
	 * Build a UI payload for admin/editor surfaces.
	 *
	 * @since  1.0.0
	 * @return array
	 */
	public static function get_payload() {
		return array(
			'installed'  => self::is_installed(),
			'active'     => self::is_active(),
			'available'  => self::is_available(),
			'status'     => self::get_status(),
			'totalItems' => self::get_total_items(),
			'adminUrl'   => esc_url_raw( self::get_admin_url() ),
			'pluginAdminUrl' => esc_url_raw( self::get_plugin_admin_url() ),
			'restUrl'    => esc_url_raw( self::get_rest_url() ),
			'message'    => self::get_status_message(),
			'canManageDependency' => self::can_manage_dependency(),
			'primaryActionUrl' => esc_url_raw( self::get_primary_action_url() ),
			'primaryActionLabel' => self::get_primary_action_label(),
		);
	}

	/**
	 * Build context from the external Antimanual Knowledge Base.
	 *
	 * @since  1.0.0
	 * @param  string $query Query to search against the KB.
	 * @return string
	 */
	public static function build_context_for_query( $query ) {
		if ( ! self::is_available() ) {
			return '';
		}

		$query = sanitize_text_field( (string) $query );

		if ( '' === $query ) {
			return '';
		}

		return \Antimanual\KnowledgeContextBuilder::build_context( array(), $query );
	}
}
