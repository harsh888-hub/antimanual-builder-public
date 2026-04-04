<?php
/**
 * Editor page handler.
 *
 * Manages the full-screen page builder editor.
 * This page hides all WordPress admin chrome and renders
 * an Inertia.js-powered React application for the builder.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_Admin_Editor_Page
 *
 * Handles the standalone editor page that replaces the standard
 * WordPress admin layout with a full-screen builder experience.
 *
 * @since 1.0.0
 */
class AMB_Admin_Editor_Page {

	/**
	 * Constructor. Sets up editor-specific hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'admin_init', array( $this, 'maybe_hide_admin_chrome' ) );
		add_action( 'admin_init', array( $this, 'handle_inertia_request' ), 1 );
	}

	/**
	 * Determine whether the current request is for the builder editor page.
	 *
	 * @since  1.0.0
	 * @return bool True when loading the editor page.
	 */
	private function is_editor_page_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'] ) && 'amb-editor' === sanitize_text_field( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * Handle Inertia XHR requests for the editor page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function handle_inertia_request() {
		if ( ! AMB_Admin_Inertia::is_inertia_request() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page_slug = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'amb-editor' !== $page_slug ) {
			return;
		}

		// Redirect to the dashboard gate when the dependency is not active.
		if ( ! AMB_Antimanual_Knowledge_Base::is_active() ) {
			AMB_Admin_Inertia::location( admin_url( 'admin.php?page=amb-dashboard' ) );
			return;
		}

		$props = $this->get_editor_props();
		AMB_Admin_Inertia::render( 'Editor', $props );
	}

	/**
	 * Get props for the editor Inertia page.
	 *
	 * @since  1.0.0
	 * @return array
	 */
	private function get_editor_props() {
		$post_data = $this->get_current_post_data();

		return array(
			'restUrl'       => esc_url_raw( rest_url( 'amb/v1/' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'adminUrl'      => esc_url( admin_url() ),
			'dashboardUrl'  => esc_url( admin_url( 'admin.php?page=amb-dashboard' ) ),
			'editorUrl'     => esc_url( admin_url( 'admin.php?page=amb-editor' ) ),
			'componentEditorUrl' => esc_url( admin_url( 'admin.php?page=amb-editor&entity_type=' . rawurlencode( AMB_Post_Type_Builder_Component::POST_TYPE ) ) ),
			'pluginUrl'     => esc_url( AMB_PLUGIN_URL ),
			'uploadsUrl'    => esc_url( wp_upload_dir()['baseurl'] . '/' . AMB_UPLOAD_DIR . '/' ),
			'version'       => AMB_VERSION,
			'entityType'    => $this->get_requested_entity_type(),
			'editorAction'  => $this->get_requested_editor_action(),
			'migrationMode'     => get_option( 'amb_migration_mode', 'ai' ),
			'migrationBehavior' => get_option( 'amb_migration_behavior', 'replace' ),
			'postData'      => $post_data,
			'aiSettings'    => $this->get_safe_ai_settings(),
			'designDefaults' => get_option( 'amb_design_defaults', array() ),
			'antimanualKnowledgeBase' => AMB_Antimanual_Knowledge_Base::get_payload(),
			'blocks'        => $this->get_registered_blocks(),
			'user'          => array(
				'id'          => get_current_user_id(),
				'name'        => wp_get_current_user()->display_name,
				'canPublish'  => current_user_can( 'publish_pages' ),
				'canEditCSS'  => current_user_can( 'edit_css' ),
			),
			'siteInfo'      => array(
				'title' => get_bloginfo( 'name' ),
				'url'   => get_site_url(),
			),
		);
	}

	/**
	 * Conditionally hide WordPress admin chrome for the editor page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function maybe_hide_admin_chrome() {
		if ( ! $this->is_editor_page_request() ) {
			return;
		}

		global $title;

		if ( empty( $title ) ) {
			$title = __( 'Editor', 'antimanual-builder' );
		}

		// Remove admin bar.
		add_filter( 'show_admin_bar', '__return_false' );

		// Add full-screen class to body.
		add_filter( 'admin_body_class', array( $this, 'add_body_class' ) );

		// Hide admin menu and bar via CSS.
		add_action( 'admin_head', array( $this, 'hide_chrome_css' ) );
	}

	/**
	 * Add custom body class for the editor.
	 *
	 * @since  1.0.0
	 * @param  string $classes Space-separated list of body classes.
	 * @return string Modified body classes.
	 */
	public function add_body_class( $classes ) {
		return ( $classes ?? '' ) . ' amb-editor-page amb-fullscreen';
	}

	/**
	 * Output CSS to hide WordPress admin chrome.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function hide_chrome_css() {
		?>
		<style>
			/* Hide all WordPress admin chrome for the builder */
			#wpadminbar,
			#adminmenumain,
			#adminmenuback,
			#adminmenuwrap,
			#wpfooter,
			.notice,
			.update-nag,
			.updated,
			.error:not(.amb-error) {
				display: none !important;
			}

			html.wp-toolbar {
				padding-top: 0 !important;
			}

			#wpcontent,
			#wpbody,
			#wpbody-content {
				margin-left: 0 !important;
				padding: 0 !important;
				float: none !important;
			}

			.amb-editor-page #wpcontent {
				height: 100vh;
				overflow: hidden;
			}

			.amb-editor-page #wpbody-content {
				height: 100vh;
				overflow: hidden;
			}

			#amb-editor-root {
				width: 100vw;
				height: 100vh;
				position: fixed;
				top: 0;
				left: 0;
				z-index: 99999;
			}
		</style>
		<?php
	}

	/**
	 * Enqueue editor-specific assets.
	 *
	 * @since  1.0.0
	 * @param  string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_editor_assets( $hook_suffix ) {
		// Only enqueue on the editor page.
		if ( 'admin_page_amb-editor' !== $hook_suffix ) {
			return;
		}

		$asset_file = AMB_PLUGIN_DIR . 'build/editor.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ),
			'version'      => AMB_VERSION,
		);

		$asset_dependencies = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] )
			? $asset['dependencies']
			: array();
		$script_dependencies = array();
		$style_dependencies  = array( 'amb-google-fonts' );

		foreach ( $asset_dependencies as $dependency ) {
			if ( ! is_string( $dependency ) || '' === $dependency ) {
				continue;
			}

			if ( preg_match( '#^(wp-[^/]+)/build-style/style(?:-rtl)?\\.css$#', $dependency, $matches ) ) {
				$style_dependencies[] = $matches[1];
				continue;
			}

			if ( str_ends_with( $dependency, '.css' ) ) {
				continue;
			}

			$script_dependencies[] = $dependency;
		}

		if ( in_array( 'wp-block-editor', $script_dependencies, true ) ) {
			$style_dependencies[] = 'wp-block-editor';
		}

		$style_dependencies = array_values( array_unique( $style_dependencies ) );
		$script_dependencies = array_values( array_unique( $script_dependencies ) );

		// Google Fonts.
		wp_enqueue_style(
			'amb-google-fonts',
			'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap',
			array(),
			AMB_VERSION
		);

		// Editor styles.
		wp_enqueue_style(
			'amb-editor',
			AMB_PLUGIN_URL . 'build/editor.css',
			$style_dependencies,
			$asset['version']
		);

		// Editor script.
		wp_enqueue_script(
			'amb-editor',
			AMB_PLUGIN_URL . 'build/editor.js',
			$script_dependencies,
			$asset['version'],
			true
		);

		// WordPress media uploader.
		wp_enqueue_media();
	}

	/**
	 * Get current post data for editing.
	 *
	 * @since  1.0.0
	 * @return array|null Post data array or null if creating new.
	 */
	private function get_current_post_data() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

		if ( ! $post_id ) {
			return null;
		}

		$post = get_post( $post_id );
		if (
			! $post ||
			! in_array( $post->post_type, array( AMB_Page_Integration::POST_TYPE, AMB_Post_Type_Builder_Component::POST_TYPE ), true ) ||
			! current_user_can( 'edit_post', $post_id )
		) {
			return null;
		}

		$blocks        = get_post_meta( $post_id, '_amb_blocks', true );
		$rendered_html = get_post_meta( $post_id, '_amb_rendered_html', true );

		if ( empty( $blocks ) && ! empty( $post->post_content ) ) {
			$blocks = wp_json_encode( array(
				array(
					'id'      => 'amb-' . uniqid(),
					'type'    => 'html',
					'content' => array( 'html' => $post->post_content ),
					'styles'  => new stdClass(),
				)
			) );

			if ( empty( $rendered_html ) ) {
				$rendered_html = $post->post_content;
			}
		}

		$is_component = AMB_Post_Type_Builder_Component::POST_TYPE === $post->post_type;

		return array(
			'id'             => $post->ID,
			'entityType'     => $is_component ? 'component' : 'page',
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'excerpt'        => $post->post_excerpt,
			'permalink'      => $is_component ? null : get_permalink( $post_id ),
			'date'           => $post->post_date,
			'modified'       => $post->post_modified,
			'blocks'         => $blocks,
			'renderedHtml'   => $rendered_html,
			'customCss'      => get_post_meta( $post_id, '_amb_custom_css', true ),
			'pageSettings'   => get_post_meta( $post_id, '_amb_page_settings', true ),
			'designOverrides' => get_post_meta( $post_id, '_amb_design_overrides', true ),
			'isBuilderPage'  => $is_component ? true : AMB_Page_Integration::is_builder_page( $post ),
			'canMigrate'     => $is_component ? false : AMB_Page_Integration::has_migratable_content( $post ),
		);
	}

	/**
	 * Get the requested editor entity type from the URL.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private function get_requested_entity_type() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$entity_type = isset( $_GET['entity_type'] ) ? sanitize_key( wp_unslash( $_GET['entity_type'] ) ) : '';

		return AMB_Post_Type_Builder_Component::POST_TYPE === $entity_type ? 'component' : 'page';
	}

	/**
	 * Get the requested editor action from the URL.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	private function get_requested_editor_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['amb_action'] ) ? sanitize_text_field( wp_unslash( $_GET['amb_action'] ) ) : 'edit';

		return 'migrate' === $action ? 'migrate' : 'edit';
	}

	/**
	 * Get AI settings with sensitive data removed.
	 *
	 * @since  1.0.0
	 * @return array Safe AI settings (no API keys).
	 */
	private function get_safe_ai_settings() {
		return AMB_Antimanual_Ai_Provider::get_settings_payload();
	}

	/**
	 * Get registered blocks for the editor.
	 *
	 * @since  1.0.0
	 * @return array List of available block types.
	 */
	private function get_registered_blocks() {
		/**
		 * Filter the list of registered blocks.
		 *
		 * @since 1.0.0
		 * @param array $blocks List of block definitions.
		 */
		return apply_filters( 'amb_registered_blocks', $this->get_core_blocks() );
	}

	/**
	 * Get core block definitions.
	 *
	 * @since  1.0.0
	 * @return array Core block definitions.
	 */
	private function get_core_blocks() {
		return array(
			// Layout blocks.
			array(
				'type'     => 'section',
				'label'    => __( 'Section', 'antimanual-builder' ),
				'icon'     => 'layout',
				'category' => 'layout',
				'isLayout' => true,
			),
			array(
				'type'     => 'row',
				'label'    => __( 'Row', 'antimanual-builder' ),
				'icon'     => 'columns',
				'category' => 'layout',
				'isLayout' => true,
			),
			array(
				'type'     => 'column',
				'label'    => __( 'Column', 'antimanual-builder' ),
				'icon'     => 'align-left',
				'category' => 'layout',
				'isLayout' => true,
			),
			array(
				'type'     => 'container',
				'label'    => __( 'Container', 'antimanual-builder' ),
				'icon'     => 'align-center',
				'category' => 'layout',
				'isLayout' => true,
			),

			// Basic blocks.
			array(
				'type'     => 'heading',
				'label'    => __( 'Heading', 'antimanual-builder' ),
				'icon'     => 'heading',
				'category' => 'basic',
			),
			array(
				'type'     => 'paragraph',
				'label'    => __( 'Paragraph', 'antimanual-builder' ),
				'icon'     => 'text',
				'category' => 'basic',
			),
			array(
				'type'     => 'image',
				'label'    => __( 'Image', 'antimanual-builder' ),
				'icon'     => 'image',
				'category' => 'basic',
			),
			array(
				'type'     => 'button',
				'label'    => __( 'Button', 'antimanual-builder' ),
				'icon'     => 'button',
				'category' => 'basic',
			),
			array(
				'type'     => 'spacer',
				'label'    => __( 'Spacer', 'antimanual-builder' ),
				'icon'     => 'spacing',
				'category' => 'basic',
			),
			array(
				'type'     => 'divider',
				'label'    => __( 'Divider', 'antimanual-builder' ),
				'icon'     => 'minus',
				'category' => 'basic',
			),
			array(
				'type'     => 'video',
				'label'    => __( 'Video', 'antimanual-builder' ),
				'icon'     => 'video',
				'category' => 'basic',
			),
			array(
				'type'     => 'icon',
				'label'    => __( 'Icon', 'antimanual-builder' ),
				'icon'     => 'star',
				'category' => 'basic',
			),
			array(
				'type'     => 'html',
				'label'    => __( 'HTML', 'antimanual-builder' ),
				'icon'     => 'code',
				'category' => 'basic',
			),
			array(
				'type'     => 'list',
				'label'    => __( 'List', 'antimanual-builder' ),
				'icon'     => 'list',
				'category' => 'basic',
			),

			// Advanced blocks.
			array(
				'type'     => 'tabs',
				'label'    => __( 'Tabs', 'antimanual-builder' ),
				'icon'     => 'table',
				'category' => 'advanced',
			),
			array(
				'type'     => 'accordion',
				'label'    => __( 'Accordion', 'antimanual-builder' ),
				'icon'     => 'arrow-down',
				'category' => 'advanced',
			),
			array(
				'type'     => 'slider',
				'label'    => __( 'Slider', 'antimanual-builder' ),
				'icon'     => 'slides',
				'category' => 'advanced',
			),
			array(
				'type'     => 'form',
				'label'    => __( 'Form', 'antimanual-builder' ),
				'icon'     => 'feedback',
				'category' => 'advanced',
			),
			array(
				'type'     => 'testimonial',
				'label'    => __( 'Testimonial', 'antimanual-builder' ),
				'icon'     => 'format-quote',
				'category' => 'advanced',
			),
			array(
				'type'     => 'pricing-table',
				'label'    => __( 'Pricing Table', 'antimanual-builder' ),
				'icon'     => 'money-alt',
				'category' => 'advanced',
			),
			array(
				'type'     => 'counter',
				'label'    => __( 'Counter', 'antimanual-builder' ),
				'icon'     => 'plus-alt',
				'category' => 'advanced',
			),
			array(
				'type'     => 'progress-bar',
				'label'    => __( 'Progress Bar', 'antimanual-builder' ),
				'icon'     => 'minus',
				'category' => 'advanced',
			),
			array(
				'type'     => 'social-icons',
				'label'    => __( 'Social Icons', 'antimanual-builder' ),
				'icon'     => 'share',
				'category' => 'advanced',
			),
			array(
				'type'     => 'map',
				'label'    => __( 'Map', 'antimanual-builder' ),
				'icon'     => 'location',
				'category' => 'advanced',
			),
		);
	}

	/**
	 * Render the editor page using Inertia.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to access the page builder.', 'antimanual-builder' ) );
		}

		// Redirect to the dashboard gate when the dependency is not active.
		if ( ! AMB_Antimanual_Knowledge_Base::is_active() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=amb-dashboard' ) );
			exit;
		}

		$props = $this->get_editor_props();
		echo '<div id="amb-editor-root" data-page="' . esc_attr( wp_json_encode( array(
			'component' => 'Editor',
			'props'     => $props,
			'url'       => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/',
			'version'   => AMB_VERSION,
		) ) ) . '"></div>';
	}
}
