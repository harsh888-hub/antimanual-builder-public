<?php
/**
 * Main plugin class.
 *
 * Handles plugin initialization, loading core components,
 * and orchestrating all plugin functionality.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_Antimanual_Builder
 *
 * Singleton class that bootstraps the entire plugin.
 *
 * @since 1.0.0
 */
class AMB_Antimanual_Builder {

	/**
	 * Singleton instance.
	 *
	 * @since  1.0.0
	 * @var    AMB_Antimanual_Builder|null
	 */
	private static $instance = null;

	/**
	 * Admin handler instance.
	 *
	 * @since  1.0.0
	 * @var    AMB_Admin_Admin|null
	 */
	private $admin = null;

	/**
	 * REST API handler instance.
	 *
	 * @since  1.0.0
	 * @var    AMB_API_Rest_Api|null
	 */
	private $api = null;

	/**
	 * Renderer instance.
	 *
	 * @since  1.0.0
	 * @var    AMB_Render_Renderer|null
	 */
	private $renderer = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since  1.0.0
	 * @return AMB_Antimanual_Builder
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Sets up the plugin.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->load_textdomain();
		$this->register_post_types();
		$this->init_components();
		$this->register_hooks();
	}

	/**
	 * Load plugin text domain for translations.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function load_textdomain() {
		load_plugin_textdomain(
			'antimanual-builder',
			false,
			dirname( AMB_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Register builder post types and integrations.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function register_post_types() {
		new AMB_Page_Integration();
		new AMB_Post_Type_Builder_Component();
	}

	/**
	 * Initialize core components.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function init_components() {
		$this->renderer = new AMB_Render_Renderer();

		if ( is_admin() ) {
			$this->admin = new AMB_Admin_Admin();
		}

		$this->api = new AMB_API_Rest_Api();

		// Initialize asset generator.
		new AMB_Render_Asset_Generator();

		// Initialize template loader for frontend.
		new AMB_Render_Template_Loader();
	}

	/**
	 * Register plugin-wide hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function register_hooks() {
		// Add settings link on plugins page.
		add_filter( 'plugin_action_links_' . AMB_PLUGIN_BASENAME, array( $this, 'add_plugin_links' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_dependency_notice' ) );

		// One-time rewrite rules flush for existing installs (post types must be registered first).
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );

		// Fix cURL timeouts for local environments by enforcing IPv4 explicitly in WordPress HTTP API.
		add_action( 'http_api_curl', array( $this, 'fix_curl_timeouts' ), 10, 1 );

		// Add "Edit with AM Builder" link to the frontend admin bar.
		add_action( 'admin_bar_menu', array( $this, 'add_frontend_admin_bar_link' ), 80 );
	}

	/**
	 * Fix cURL timeouts for local environments.
	 * Forces IPv4 and disables SSL verification for external AI API requests.
	 *
	 * @since  1.0.0
	 * @param  resource $handle cURL handle.
	 * @return void
	 */
	public function fix_curl_timeouts( $handle ) {
		if ( function_exists( 'curl_setopt' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress passes the live cURL handle to this hook specifically for low-level transport tuning.
			curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
		}
	}

	/**
	 * Flush rewrite rules once after post types are registered.
	 *
	 * This catches existing installations where the rewrite rules
	 * were never properly flushed after post type registration.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function maybe_flush_rewrite_rules() {
		if ( ! get_option( 'amb_rewrite_flushed' ) ) {
			flush_rewrite_rules();
			update_option( 'amb_rewrite_flushed', true );
		}
	}

	/**
	 * Render a notice when the required Antimanual dependency is unavailable.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public function maybe_render_dependency_notice() {
		if ( AMB_Antimanual_Knowledge_Base::is_active() ) {
			return;
		}

		if ( ! current_user_can( 'install_plugins' ) && ! current_user_can( 'activate_plugins' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only show the floating notice on the plugins.php page.
		// AM Builder admin pages show a full lock-screen gate instead.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		$dependency   = AMB_Antimanual_Knowledge_Base::get_payload();
		$action_url   = isset( $dependency['primaryActionUrl'] ) ? $dependency['primaryActionUrl'] : '';
		$action_label = isset( $dependency['primaryActionLabel'] ) ? $dependency['primaryActionLabel'] : '';
		$status       = isset( $dependency['status'] ) ? $dependency['status'] : 'not_installed';

		$message = 'not_installed' === $status
			? __( '<strong>AM Builder</strong> requires the Antimanual plugin to be installed and activated.', 'antimanual-builder' )
			: __( '<strong>AM Builder</strong> requires the Antimanual plugin to be active.', 'antimanual-builder' );
		?>
		<div class="notice notice-warning" style="display:flex;align-items:center;gap:12px;padding:10px 14px;">
			<p style="margin:0;flex:1;"><?php echo wp_kses( $message, array( 'strong' => array() ) ); ?></p>
			<?php if ( ! empty( $action_url ) && ! empty( $action_label ) ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( $action_url ); ?>" style="flex-shrink:0;">
					<?php echo esc_html( $action_label ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Add "Edit with AM Builder" node to the frontend admin bar.
	 *
	 * Only fires on singular pages managed by the builder when the current
	 * user has permission to edit the page.
	 *
	 * @since  1.0.0
	 * @param  WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public function add_frontend_admin_bar_link( $wp_admin_bar ) {
		if ( is_admin() ) {
			return;
		}

		if ( ! is_singular( AMB_Page_Integration::POST_TYPE ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		if ( ! AMB_Page_Integration::is_builder_page( $post ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'amb-edit-with-builder',
				'title' => esc_html__( 'Edit with AM Builder', 'antimanual-builder' ),
				'href'  => esc_url( admin_url( 'admin.php?page=amb-editor&post_id=' . absint( $post->ID ) ) ),
				'meta'  => array(
					'class' => 'amb-admin-bar-node',
				),
			)
		);
	}

	/**
	 * Add action links to the plugins page.
	 *
	 * @since  1.0.0
	 * @param  array $links Existing action links.
	 * @return array Modified action links.
	 */
	public function add_plugin_links( $links ) {
		$plugin_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=amb-editor' ) ) . '">' . esc_html__( 'Builder', 'antimanual-builder' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=amb-settings' ) ) . '">' . esc_html__( 'Settings', 'antimanual-builder' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Get the admin handler.
	 *
	 * @since  1.0.0
	 * @return AMB_Admin_Admin|null
	 */
	public function get_admin() {
		return $this->admin;
	}

	/**
	 * Get the REST API handler.
	 *
	 * @since  1.0.0
	 * @return AMB_API_Rest_Api|null
	 */
	public function get_api() {
		return $this->api;
	}

	/**
	 * Get the renderer.
	 *
	 * @since  1.0.0
	 * @return AMB_Render_Renderer|null
	 */
	public function get_renderer() {
		return $this->renderer;
	}
}
