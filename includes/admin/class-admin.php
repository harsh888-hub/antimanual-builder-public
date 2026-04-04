<?php
/**
 * Admin handler.
 *
 * Registers admin menus, pages, and enqueues admin-specific assets.
 * Uses Inertia.js for rendering admin page components.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_Admin_Admin
 *
 * Handles all WordPress admin functionality for the plugin.
 *
 * @since 1.0.0
 */
class AMB_Admin_Admin {

	/**
	 * Editor page handler.
	 *
	 * @since 1.0.0
	 * @var   AMB_Admin_Editor_Page
	 */
	private $editor_page;

	/**
	 * Settings page handler.
	 *
	 * @since 1.0.0
	 * @var   AMB_Admin_Settings_Page
	 */
	private $settings_page;

	/**
	 * Constructor. Sets up admin hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->editor_page   = new AMB_Admin_Editor_Page();
		$this->settings_page = new AMB_Admin_Settings_Page();

		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_head', array( $this, 'render_menu_icon_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_page_editor_assets' ) );
		add_action( 'edit_form_after_title', array( $this, 'render_classic_editor_button' ) );

		// Handle Inertia XHR requests early, before WP renders any HTML.
		add_action( 'admin_init', array( $this, 'handle_inertia_request' ), 1 );

		// Add builder link to native pages.
		add_filter( 'page_row_actions', array( $this, 'add_builder_row_action' ), 10, 2 );

		// Add "AM Builder" post state badge in the pages list.
		add_filter( 'display_post_states', array( $this, 'add_builder_post_state' ), 10, 2 );

		// Set Inertia asset version.
		AMB_Admin_Inertia::version( AMB_VERSION );

		// Defer shared props setup to admin_init (rest_url requires $wp_rewrite).
		add_action( 'admin_init', array( $this, 'setup_shared_props' ), 0 );
	}

	/**
	 * Setup shared Inertia props available to all admin pages.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function setup_shared_props() {
		$ai_settings     = AMB_Antimanual_Ai_Provider::get_settings_payload();
		$design_defaults = get_option( 'amb_design_defaults', array() );

		AMB_Admin_Inertia::share( array(
			'restUrl'    => esc_url_raw( rest_url( 'amb/v1/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'adminUrl'   => esc_url( admin_url() ),
			'pluginUrl'  => esc_url( AMB_PLUGIN_URL ),
			'version'    => AMB_VERSION,
			'editorUrl'  => esc_url( admin_url( 'admin.php?page=amb-editor' ) ),
			'componentEditorUrl' => esc_url( admin_url( 'admin.php?page=amb-editor&entity_type=' . rawurlencode( AMB_Post_Type_Builder_Component::POST_TYPE ) ) ),
			'antimanualKnowledgeBase' => AMB_Antimanual_Knowledge_Base::get_payload(),
			'aiSettings' => $ai_settings,
			'designDefaults' => $design_defaults,
			'migrationMode'     => get_option( 'amb_migration_mode', 'ai' ),
			'migrationBehavior' => get_option( 'amb_migration_behavior', 'replace' ),
		) );
	}

	/**
	 * Handle Inertia XHR requests.
	 *
	 * Intercepts Inertia requests before WordPress renders any admin chrome,
	 * and returns JSON responses conforming to the Inertia protocol.
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

		$page_map = array(
			'amb-dashboard'  => 'Dashboard',
			'amb-components' => 'Components',
			'amb-html-import' => 'HtmlImport',
			'amb-settings'   => 'Settings',
		);

		if ( ! isset( $page_map[ $page_slug ] ) ) {
			return;
		}

		// Force a full-page reload so the dependency gate renders instead of
		// returning a partial Inertia JSON response with locked content.
		if ( $this->needs_dependency_gate() ) {
			AMB_Admin_Inertia::location( admin_url( 'admin.php?page=' . $page_slug ) );
			return;
		}

		$this->setup_shared_props();
		AMB_Admin_Inertia::render( $page_map[ $page_slug ] );
	}

	/**
	 * Register admin menu pages.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_menus() {
		// Main menu page — Dashboard/Pages listing.
		add_menu_page(
			__( 'Antimanual Builder', 'antimanual-builder' ),
			__( 'AM Builder', 'antimanual-builder' ),
			'edit_pages',
			'amb-dashboard',
			array( $this, 'render_admin_page' ),
			AMB_PLUGIN_URL . 'assets/images/logo.svg',
			30
		);

		// Submenu: All Pages.
		add_submenu_page(
			'amb-dashboard',
			__( 'All Pages', 'antimanual-builder' ),
			__( 'All Pages', 'antimanual-builder' ),
			'edit_pages',
			'amb-dashboard',
			array( $this, 'render_admin_page' )
		);

		// Submenu: Component Library.
		add_submenu_page(
			'amb-dashboard',
			__( 'Components', 'antimanual-builder' ),
			__( 'Components', 'antimanual-builder' ),
			'edit_pages',
			'amb-components',
			array( $this, 'render_admin_page' )
		);

		// Submenu: HTML Import.
		add_submenu_page(
			'amb-dashboard',
			__( 'HTML Import', 'antimanual-builder' ),
			__( 'HTML Import', 'antimanual-builder' ),
			'edit_pages',
			'amb-html-import',
			array( $this, 'render_admin_page' )
		);

		// Submenu: Settings.
		add_submenu_page(
			'amb-dashboard',
			__( 'Settings', 'antimanual-builder' ),
			__( 'Settings', 'antimanual-builder' ),
			'manage_options',
			'amb-settings',
			array( $this, 'render_admin_page' )
		);

		// Hidden: Editor page (full-screen, no admin chrome).
		add_submenu_page(
			'',
			__( 'Editor', 'antimanual-builder' ),
			__( 'Editor', 'antimanual-builder' ),
			'edit_pages',
			'amb-editor',
			array( $this->editor_page, 'render' )
		);
	}

	/**
	 * Check whether the dependency gate should be shown.
	 *
	 * @since  1.1.0
	 * @return bool
	 */
	private function needs_dependency_gate() {
		return ! AMB_Antimanual_Knowledge_Base::is_active();
	}

	/**
	 * Render an admin page using Inertia.
	 *
	 * Maps the current page slug to an Inertia component name
	 * and renders the initial page load with data-page attribute.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_admin_page() {
		if ( $this->needs_dependency_gate() ) {
			$this->render_dependency_gate();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page_slug = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'amb-dashboard';

		$page_map = array(
			'amb-dashboard'  => 'Dashboard',
			'amb-components' => 'Components',
			'amb-html-import' => 'HtmlImport',
			'amb-settings'   => 'Settings',
		);

		$component = isset( $page_map[ $page_slug ] ) ? $page_map[ $page_slug ] : 'Dashboard';
		AMB_Admin_Inertia::render( $component );
	}

	/**
	 * Render the polished dependency lock screen.
	 *
	 * Outputs a self-contained, styled gate page that replaces the normal
	 * admin content area when the Antimanual parent plugin is not active.
	 * No React assets are loaded on this screen.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	private function render_dependency_gate() {
		$kb           = AMB_Antimanual_Knowledge_Base::get_payload();
		$status       = isset( $kb['status'] ) ? $kb['status'] : 'not_installed';
		$action_url   = isset( $kb['primaryActionUrl'] ) ? $kb['primaryActionUrl'] : '';
		$action_label = isset( $kb['primaryActionLabel'] ) ? $kb['primaryActionLabel'] : '';
		$can_manage   = ! empty( $kb['canManageDependency'] );
		$logo_url     = esc_url( AMB_PLUGIN_URL . 'assets/images/logo.svg' );

		if ( 'not_installed' === $status ) {
			$badge_text  = __( 'Not Installed', 'antimanual-builder' );
			$badge_bg    = '#fef2f2';
			$badge_color = '#cc1818';
			$headline    = __( 'Install Antimanual to Get Started', 'antimanual-builder' );
			$description = __( 'AM Builder is an add-on for the Antimanual plugin. Install and activate Antimanual to unlock all builder features — AI page generation, component library, and shared AI provider settings.', 'antimanual-builder' );
		} else {
			$badge_text  = __( 'Plugin Inactive', 'antimanual-builder' );
			$badge_bg    = '#fffbeb';
			$badge_color = '#b45309';
			$headline    = __( 'Activate Antimanual to Continue', 'antimanual-builder' );
			$description = __( 'The Antimanual plugin is installed but not currently active. Activate it to unlock all AM Builder features — AI page generation, component library, and shared AI provider settings.', 'antimanual-builder' );
		}
		?>
		<style id="amb-gate-styles">
			.amb-gate {
				display: flex;
				align-items: flex-start;
				justify-content: center;
				min-height: calc(100vh - 120px);
				padding: 48px 20px;
				box-sizing: border-box;
			}
			.amb-gate__card {
				background: #ffffff;
				border: 1px solid #dcdcde;
				border-radius: 16px;
				padding: 52px 56px;
				max-width: 580px;
				width: 100%;
				text-align: center;
				box-shadow: 0 2px 20px rgba(0,0,0,.06);
			}
			.amb-gate__logorow {
				display: flex;
				align-items: center;
				justify-content: center;
				gap: 9px;
				margin-bottom: 36px;
			}
			.amb-gate__logorow img {
				width: 26px;
				height: 26px;
			}
			.amb-gate__logotype {
				font-size: 15px;
				font-weight: 700;
				letter-spacing: -.25px;
				color: #1d2327;
			}
			.amb-gate__icon-wrap {
				display: flex;
				align-items: center;
				justify-content: center;
				width: 72px;
				height: 72px;
				background: #f6f7f7;
				border-radius: 50%;
				margin: 0 auto 20px;
			}
			.amb-gate__icon-wrap svg {
				width: 32px;
				height: 32px;
				color: #646970;
			}
			.amb-gate__badge {
				display: inline-flex;
				align-items: center;
				gap: 7px;
				font-size: 11px;
				font-weight: 700;
				letter-spacing: .5px;
				text-transform: uppercase;
				padding: 5px 12px;
				border-radius: 100px;
				margin-bottom: 20px;
			}
			.amb-gate__badge-dot {
				width: 7px;
				height: 7px;
				border-radius: 50%;
				flex-shrink: 0;
				display: inline-block;
			}
			.amb-gate__title {
				font-size: 22px;
				font-weight: 700;
				color: #1d2327;
				margin: 0 0 12px;
				line-height: 1.35;
				border: none;
				padding: 0;
			}
			.amb-gate__desc {
				font-size: 14px;
				color: #50575e;
				line-height: 1.75;
				margin: 0 0 28px;
			}
			.amb-gate__features {
				list-style: none;
				padding: 0;
				margin: 0 0 32px;
				border: 1px solid #f0f0f1;
				border-radius: 10px;
				overflow: hidden;
				text-align: left;
			}
			.amb-gate__feature {
				display: flex;
				align-items: center;
				gap: 10px;
				padding: 11px 16px;
				font-size: 13px;
				color: #3c434a;
				border-bottom: 1px solid #f0f0f1;
			}
			.amb-gate__feature:last-child { border-bottom: none; }
			.amb-gate__feature-icon {
				flex-shrink: 0;
				display: flex;
				align-items: center;
				color: #00a32a;
			}
			.amb-gate__feature-icon svg { width: 15px; height: 15px; }
			.amb-gate__btn {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				gap: 7px;
				background: #2271b1;
				color: #ffffff !important;
				text-decoration: none !important;
				padding: 11px 24px;
				border-radius: 8px;
				font-size: 14px;
				font-weight: 600;
				line-height: 1;
				cursor: pointer;
				border: none;
				box-shadow: 0 1px 3px rgba(0,0,0,.15);
				transition: background .15s, box-shadow .15s;
			}
			.amb-gate__btn:hover {
				background: #135e96 !important;
				box-shadow: 0 2px 6px rgba(0,0,0,.2);
			}
			.amb-gate__btn svg { width: 15px; height: 15px; }
			.amb-gate__no-access {
				font-size: 13px;
				color: #646970;
				margin: 0;
				padding: 12px 16px;
				background: #f6f7f7;
				border-radius: 8px;
			}
			.amb-gate__version {
				margin-top: 28px;
				font-size: 12px;
				color: #a7aaad;
			}
		</style>
		<div class="amb-gate">
			<div class="amb-gate__card">

				<div class="amb-gate__logorow">
					<img src="<?php echo $logo_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" alt="" aria-hidden="true" />
					<span class="amb-gate__logotype"><?php esc_html_e( 'AM Builder', 'antimanual-builder' ); ?></span>
				</div>

				<div class="amb-gate__icon-wrap">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
						<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
					</svg>
				</div>

				<span class="amb-gate__badge" style="background:<?php echo esc_attr( $badge_bg ); ?>;color:<?php echo esc_attr( $badge_color ); ?>;">
					<span class="amb-gate__badge-dot" style="background:<?php echo esc_attr( $badge_color ); ?>;"></span>
					<?php echo esc_html( $badge_text ); ?>
				</span>

				<h1 class="amb-gate__title"><?php echo esc_html( $headline ); ?></h1>
				<p class="amb-gate__desc"><?php echo esc_html( $description ); ?></p>

				<ul class="amb-gate__features">
					<li class="amb-gate__feature">
						<span class="amb-gate__feature-icon">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
						</span>
						<?php esc_html_e( 'AI-powered page generation and migration', 'antimanual-builder' ); ?>
					</li>
					<li class="amb-gate__feature">
						<span class="amb-gate__feature-icon">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
						</span>
						<?php esc_html_e( 'Shared AI provider settings from Antimanual', 'antimanual-builder' ); ?>
					</li>
					<li class="amb-gate__feature">
						<span class="amb-gate__feature-icon">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
						</span>
						<?php esc_html_e( 'Knowledge Base context for smarter AI responses', 'antimanual-builder' ); ?>
					</li>
				</ul>

				<?php if ( $can_manage && ! empty( $action_url ) && ! empty( $action_label ) ) : ?>
					<a href="<?php echo esc_url( $action_url ); ?>" class="amb-gate__btn">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.638L10.23 5.29a.75.75 0 1 1 1.04-1.08l5.5 5.25a.75.75 0 0 1 0 1.08l-5.5 5.25a.75.75 0 1 1-1.04-1.08l4.158-3.96H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd"/></svg>
						<?php echo esc_html( $action_label ); ?>
					</a>
				<?php else : ?>
					<p class="amb-gate__no-access">
						<?php esc_html_e( 'Please contact your site administrator to install and activate the Antimanual plugin.', 'antimanual-builder' ); ?>
					</p>
				<?php endif; ?>

				<p class="amb-gate__version">AM Builder <?php echo esc_html( AMB_VERSION ); ?></p>

			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue admin-specific assets.
	 *
	 * Only loads on Antimanual Builder admin pages (not the editor).
	 *
	 * @since  1.0.0
	 * @param  string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		// Only enqueue on our admin pages (skip the editor — it has its own assets).
		$admin_pages = array(
			'toplevel_page_amb-dashboard',
			'am-builder_page_amb-components',
			'am-builder_page_amb-html-import',
			'am-builder_page_amb-settings',
		);

		if ( ! in_array( $hook_suffix, $admin_pages, true ) ) {
			return;
		}

		// Don't load the React bundle when the dependency gate is showing —
		// there is no #app mount point on the gate screen.
		if ( $this->needs_dependency_gate() ) {
			return;
		}

		$asset_file = AMB_PLUGIN_DIR . 'build/admin.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => AMB_VERSION,
		);

		wp_enqueue_style(
			'amb-admin',
			AMB_PLUGIN_URL . 'build/admin.css',
			array(),
			$asset['version']
		);

		wp_enqueue_script(
			'amb-admin',
			AMB_PLUGIN_URL . 'build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
	}

	/**
	 * Render consistent sizing for the custom admin menu icon.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_menu_icon_styles() {
		?>
		<style id="amb-menu-icon-styles">
			#toplevel_page_amb-dashboard .wp-menu-image img {
				width: 18px;
				height: 18px;
				padding-top: 8px;
				opacity: 1;
			}
		</style>
		<?php
	}

	/**
	 * Enqueue the Gutenberg page editor launcher.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function enqueue_page_editor_assets() {
		$screen = get_current_screen();
		$post   = $this->get_current_admin_page_post();

		if ( ! $screen || ! $post || AMB_Page_Integration::POST_TYPE !== $screen->post_type ) {
			return;
		}

		if ( method_exists( $screen, 'is_block_editor' ) && ! $screen->is_block_editor() ) {
			return;
		}

		$action = $this->get_page_builder_action( $post );
		if ( 'none' === $action ) {
			return;
		}

		$asset_file = AMB_PLUGIN_DIR . 'build/page-editor.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(
				'wp-components',
				'wp-data',
				'wp-dom-ready',
				'wp-element',
				'wp-i18n',
			),
			'version'      => AMB_VERSION,
		);

		wp_enqueue_script(
			'amb-page-editor',
			AMB_PLUGIN_URL . 'build/page-editor.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$style_file = AMB_PLUGIN_DIR . 'build/page-editor.css';
		if ( file_exists( $style_file ) ) {
			wp_enqueue_style(
				'amb-page-editor',
				AMB_PLUGIN_URL . 'build/page-editor.css',
				array( 'wp-edit-blocks' ),
				$asset['version']
			);
		}

		wp_add_inline_script(
			'amb-page-editor',
			'window.ambPageEditorConfig = ' . wp_json_encode(
				array(
					'editorBaseUrl' => $this->get_builder_editor_url(),
					'action'        => $action,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Render a fallback button for the classic page editor.
	 *
	 * @since  1.0.0
	 * @param  WP_Post $post Current post.
	 * @return void
	 */
	public function render_classic_editor_button( $post ) {
		if ( ! ( $post instanceof \WP_Post ) || AMB_Page_Integration::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$action = $this->get_page_builder_action( $post );
		if ( 'none' === $action ) {
			return;
		}

		$url   = $this->get_builder_editor_url( $post->ID, $action );
		$label = 'edit' === $action
			? esc_html__( 'Edit with AM Builder', 'antimanual-builder' )
			: esc_html__( 'Migrate to AM Builder', 'antimanual-builder' );
		?>
		<div class="amb-classic-editor-launch" style="margin: 16px 0 20px;">
			<a href="<?php echo esc_url( $url ); ?>" class="button button-primary button-large">
				<?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Render the builder Knowledge Base handoff screen.
	 *
	 * Redirects to Antimanual when available, otherwise shows a short
	 * dependency message and next step.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_antimanual_knowledge_base_page() {
		if ( AMB_Antimanual_Knowledge_Base::is_available() ) {
			wp_safe_redirect( AMB_Antimanual_Knowledge_Base::get_admin_url() );
			exit;
		}

		$message    = AMB_Antimanual_Knowledge_Base::get_status_message();
		$action_url = AMB_Antimanual_Knowledge_Base::is_installed()
			? admin_url( 'admin.php?page=antimanual' )
			: admin_url( 'plugins.php' );
		$action_label = AMB_Antimanual_Knowledge_Base::is_installed()
			? __( 'Open Antimanual', 'antimanual-builder' )
			: __( 'Manage Plugins', 'antimanual-builder' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Knowledge Base', 'antimanual-builder' ); ?></h1>
			<p><?php echo esc_html( $message ); ?></p>
			<p>
				<a href="<?php echo esc_url( $action_url ); ?>" class="button button-primary">
					<?php echo esc_html( $action_label ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Get the editor page handler.
	 *
	 * @since  1.0.0
	 * @return AMB_Admin_Editor_Page
	 */
	public function get_editor_page() {
		return $this->editor_page;
	}

	/**
	 * Add "Edit with AM Builder" link to row actions.
	 *
	 * @since  1.0.0
	 * @param  array   $actions Row actions.
	 * @param  WP_Post $post    Current post object.
	 * @return array
	 */
	public function add_builder_row_action( $actions, $post ) {
		if ( AMB_Page_Integration::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$action = $this->get_page_builder_action( $post );
		if ( 'none' === $action ) {
			return $actions;
		}

		$url   = $this->get_builder_editor_url( $post->ID, $action );
		$label = 'edit' === $action
			? esc_html__( 'Edit with AM Builder', 'antimanual-builder' )
			: esc_html__( 'Migrate to AM Builder', 'antimanual-builder' );

		$actions['amb_editor'] = sprintf(
				'<a href="%s" style="color: #6366f1; font-weight: 600;">%s</a>',
				esc_url( $url ),
				$label
			);

		return $actions;
	}

	/**
	 * Add "AM Builder" post state to pages managed by the builder.
	 *
	 * @since  1.0.0
	 * @param  array   $post_states Existing post states.
	 * @param  WP_Post $post        Current post object.
	 * @return array
	 */
	public function add_builder_post_state( $post_states, $post ) {
		if ( AMB_Page_Integration::POST_TYPE !== $post->post_type ) {
			return $post_states;
		}

		if ( AMB_Page_Integration::is_builder_page( $post ) ) {
			$post_states['amb_builder'] = esc_html__( 'AM Builder', 'antimanual-builder' );
		}

		return $post_states;
	}

	/**
	 * Build the admin URL for launching the AM Builder editor.
	 *
	 * @since  1.0.0
	 * @param  int $post_id Optional post ID.
	 * @return string
	 */
	private function get_builder_editor_url( $post_id = 0, $action = 'edit' ) {
		$url = admin_url( 'admin.php?page=amb-editor' );

		if ( ! empty( $post_id ) ) {
			$url = add_query_arg( 'post_id', absint( $post_id ), $url );
		}

		if ( 'migrate' === $action ) {
			$url = add_query_arg( 'amb_action', 'migrate', $url );
		}

		return $url;
	}

	/**
	 * Determine which builder action should be shown for a page.
	 *
	 * @since  1.0.0
	 * @param  WP_Post $post Page post object.
	 * @return string `edit`, `migrate`, or `none`.
	 */
	private function get_page_builder_action( $post ) {
		if ( ! ( $post instanceof \WP_Post ) ) {
			return 'none';
		}

		if ( AMB_Page_Integration::is_builder_page( $post ) ) {
			return 'edit';
		}

		if ( AMB_Page_Integration::has_migratable_content( $post ) ) {
			return 'migrate';
		}

		return 'none';
	}

	/**
	 * Resolve the current page post in the admin editor context.
	 *
	 * @since  1.0.0
	 * @return WP_Post|null
	 */
	private function get_current_admin_page_post() {
		global $post;

		if ( $post instanceof \WP_Post ) {
			return $post;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id ) {
			return null;
		}

		$current_post = get_post( $post_id );

		return $current_post instanceof \WP_Post ? $current_post : null;
	}

}
