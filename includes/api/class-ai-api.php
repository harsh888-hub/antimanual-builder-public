<?php
/**
 * AI REST API controller.
 *
 * Handles AI-powered page generation and refinement endpoints.
 * Relays prompts to the configured AI provider and returns structured responses.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_API_Ai_Api
 *
 * REST API endpoints for AI page generation and refinement.
 *
 * @since 1.0.0
 */
class AMB_API_Ai_Api {

	use AMB_API_Builder_Meta_Trait;

	/**
	 * Cached project file contents for the current request.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	private $project_file_content_cache = array();

	/**
	 * REST namespace.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	private $namespace = 'amb/v1';

	/**
	 * Maximum number of files to inspect from an uploaded ZIP.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const PROJECT_IMPORT_MAX_FILES = 5000;

	/**
	 * Maximum text bytes per file to include in AI analysis.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const PROJECT_IMPORT_MAX_TEXT_BYTES = 200000;

	/**
	 * Maximum number of HTML pages to create from one ZIP import.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const PROJECT_IMPORT_MAX_PAGES = 60;

	/**
	 * Maximum number of HTML files to include in AI page planning.
	 *
	 * Larger projects fall back to deterministic file analysis to avoid
	 * long-running provider requests and cURL timeouts.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const PROJECT_IMPORT_MAX_AI_HTML_FILES = 10;

	/**
	 * Maximum encoded summary payload size to send for AI page planning.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const PROJECT_IMPORT_MAX_AI_SUMMARY_BYTES = 18000;

	/**
	 * Register routes.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_routes() {
		// Generate a full page from a prompt.
		register_rest_route(
			$this->namespace,
			'/ai/generate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => array( $this, 'can_use_ai' ),
				'args'                => array(
					'prompt' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'context' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// Refine an existing page based on instructions.
		register_rest_route(
			$this->namespace,
			'/ai/refine',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refine' ),
				'permission_callback' => array( $this, 'can_use_ai' ),
				'args'                => array(
					'prompt' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'html' => array(
						'required' => true,
					),
					'selectedSection' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// Rebuild an existing native page into an AM Builder page using AI.
		register_rest_route(
			$this->namespace,
			'/ai/migrate-page',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'migrate_page' ),
				'permission_callback' => array( $this, 'can_use_ai' ),
				'args'                => array(
					'postId' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Enhance/expand a user prompt into a more detailed one.
		register_rest_route(
			$this->namespace,
			'/ai/enhance-prompt',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'enhance_prompt' ),
				'permission_callback' => array( $this, 'can_use_ai' ),
				'args'                => array(
					'prompt' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// Convert AI HTML response to block JSON.
		register_rest_route(
			$this->namespace,
			'/ai/html-to-blocks',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'html_to_blocks' ),
				'permission_callback' => array( $this, 'can_use_ai' ),
				'args'                => array(
					'html' => array(
						'required' => true,
					),
				),
			)
		);

		// Analyze or import a ZIP-based HTML project into multiple pages.
		register_rest_route(
			$this->namespace,
			'/ai/import-project-zip',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_project_zip' ),
				'permission_callback' => array( $this, 'can_import_project_zip' ),
				'args'                => array(
					'mode' => array(
						'default'           => 'analyze',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Save Design Defaults.
		register_rest_route(
			$this->namespace,
			'/preferences/design-defaults',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_design_defaults' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
			)
		);

		// Save Migration Mode preference.
		register_rest_route(
			$this->namespace,
			'/preferences/migration-mode',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_migration_mode' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
				'args'                => array(
					'mode' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => function ( $value ) {
							return in_array( $value, array( 'ai', 'direct' ), true );
						},
					),
				),
			)
		);

		// Direct (no-AI) page migration.
		register_rest_route(
			$this->namespace,
			'/migrate-page-direct',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'migrate_page_direct' ),
				'permission_callback' => function () {
					if ( ! current_user_can( 'edit_pages' ) ) {
						return new \WP_Error(
							'rest_forbidden',
							__( 'You do not have permission to migrate pages.', 'antimanual-builder' ),
							array( 'status' => 403 )
						);
					}
					return true;
				},
				'args'                => array(
					'postId' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Save Migration Behavior preference.
		register_rest_route(
			$this->namespace,
			'/preferences/migration-behavior',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_migration_behavior' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
				'args'                => array(
					'behavior' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => function ( $value ) {
							return in_array( $value, array( 'replace', 'duplicate' ), true );
						},
					),
				),
			)
		);
	}

	/**
	 * Check if user can manage options (to save settings).
	 *
	 * @since  1.0.0
	 * @return bool|WP_Error
	 */
	public function can_manage_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage settings.', 'antimanual-builder' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Check if the current user can import ZIP projects.
	 *
	 * @since  1.0.0
	 * @return bool|WP_Error
	 */
	public function can_import_project_zip() {
		if ( ! current_user_can( 'edit_pages' ) || ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to import ZIP projects.', 'antimanual-builder' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Save Design Defaults.
	 *
	 * Stores global design preferences that will be used as defaults in the editor.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_design_defaults( $request ) {
		$payload = $request->get_json_params();
		$allowed_tones = array( 'professional', 'friendly', 'creative', 'formal', 'casual', 'persuasive' );
		$allowed_design_styles = array( '', 'neo-brutalism', 'neumorphism', 'glassmorphism', 'editorial-minimal', 'retro', 'flat-design' );
		$allowed_font_presets = array( '', 'poppins-inter', 'playfair-lato', 'outfit-dm', 'space-mono', 'raleway-opensans' );
		$allowed_border_radius = array( '', 'sharp', 'rounded', 'pill' );
		$allowed_spacing = array( '', 'compact', 'balanced', 'airy' );

		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'invalid_payload', __( 'Invalid payload.', 'antimanual-builder' ), array( 'status' => 400 ) );
		}

		$design_defaults = array();

		if ( isset( $payload['tone'] ) ) {
			$tone = sanitize_key( $payload['tone'] );
			if ( in_array( $tone, $allowed_tones, true ) ) {
				$design_defaults['tone'] = $tone;
			}
		}

		if ( isset( $payload['designStyle'] ) ) {
			$design_style = sanitize_text_field( $payload['designStyle'] );
			if ( in_array( $design_style, $allowed_design_styles, true ) ) {
				$design_defaults['designStyle'] = $design_style;
			}
		}

		if ( isset( $payload['fontPreset'] ) ) {
			$font_preset = sanitize_text_field( $payload['fontPreset'] );
			if ( in_array( $font_preset, $allowed_font_presets, true ) ) {
				$design_defaults['fontPreset'] = $font_preset;
			}
		}

		if ( isset( $payload['headingFont'] ) ) {
			$design_defaults['headingFont'] = sanitize_text_field( $payload['headingFont'] );
		}

		if ( isset( $payload['bodyFont'] ) ) {
			$design_defaults['bodyFont'] = sanitize_text_field( $payload['bodyFont'] );
		}

		if ( isset( $payload['borderRadius'] ) ) {
			$border_radius = sanitize_text_field( $payload['borderRadius'] );
			if ( in_array( $border_radius, $allowed_border_radius, true ) ) {
				$design_defaults['borderRadius'] = $border_radius;
			}
		}

		if ( isset( $payload['spacing'] ) ) {
			$spacing = sanitize_text_field( $payload['spacing'] );
			if ( in_array( $spacing, $allowed_spacing, true ) ) {
				$design_defaults['spacing'] = $spacing;
			}
		}

		if ( isset( $payload['brandColors'] ) && is_array( $payload['brandColors'] ) ) {
			$brand_colors = array();
			if ( isset( $payload['brandColors']['primary'] ) ) {
				$brand_colors['primary'] = sanitize_hex_color( $payload['brandColors']['primary'] ) ?: '';
			}
			if ( isset( $payload['brandColors']['secondary'] ) ) {
				$brand_colors['secondary'] = sanitize_hex_color( $payload['brandColors']['secondary'] ) ?: '';
			}
			if ( isset( $payload['brandColors']['background'] ) ) {
				$brand_colors['background'] = sanitize_hex_color( $payload['brandColors']['background'] ) ?: '';
			}
			if ( isset( $payload['brandColors']['accent'] ) ) {
				$brand_colors['accent'] = sanitize_hex_color( $payload['brandColors']['accent'] ) ?: '';
			}
			$design_defaults['brandColors'] = $brand_colors;
		}

		update_option( 'amb_design_defaults', $design_defaults );

		return new \WP_REST_Response(
			array( 'message' => __( 'Design defaults saved successfully.', 'antimanual-builder' ) ),
			200
		);
	}

	/**
	 * Check if user can use AI features.
	 *
	 * @since  1.0.0
	 * @return bool|WP_Error
	 */
	public function can_use_ai() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to use AI features.', 'antimanual-builder' ),
				array( 'status' => 403 )
			);
		}

		$api_key = $this->get_configured_ai_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'ai_not_configured',
				AMB_Antimanual_Ai_Provider::get_status_message(),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Get the configured AI API key for the active provider.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	private function get_configured_ai_api_key() {
		return AMB_Antimanual_Ai_Provider::get_api_key();
	}

	/**
	 * Generate a full page from a prompt.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate( $request ) {
		$prompt  = $request->get_param( 'prompt' );
		$context = $request->get_param( 'context' );

		$system_prompt = $this->build_generation_system_prompt();
		$user_prompt   = $prompt;

		if ( ! empty( $context ) ) {
			$user_prompt .= "\n\nAdditional context: " . $context;
		}

		$response = $this->call_ai_api( $system_prompt, $user_prompt );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_generated_page_response( isset( $response['content'] ) ? $response['content'] : '' );

		return new \WP_REST_Response(
			array(
				'html'    => $parsed['html'],
				'title'   => $parsed['title'],
				'message' => __( 'Page generated successfully.', 'antimanual-builder' ),
			),
			200
		);
	}

	/**
	 * Migrate an existing page into an AM Builder page using AI.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function migrate_page( $request ) {
		$post_id = (int) $request->get_param( 'postId' );
		$post    = get_post( $post_id );

		if ( ! $post || AMB_Page_Integration::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'not_found',
				__( 'Page not found.', 'antimanual-builder' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to migrate this page.', 'antimanual-builder' ),
				array( 'status' => 403 )
			);
		}

		if ( AMB_Page_Integration::is_builder_page( $post ) ) {
			return new \WP_Error(
				'already_builder_page',
				__( 'This page is already managed by AM Builder.', 'antimanual-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( ! AMB_Page_Integration::has_migratable_content( $post ) ) {
			return new \WP_Error(
				'empty_page',
				__( 'This page does not have enough content to migrate.', 'antimanual-builder' ),
				array( 'status' => 400 )
			);
		}

		$system_prompt = $this->build_generation_system_prompt();
		$user_prompt   = $this->build_migration_prompt( $post );
		$response      = $this->call_ai_api( $system_prompt, $user_prompt );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = $this->parse_generated_page_response( isset( $response['content'] ) ? $response['content'] : '' );

		// Determine the target post ID based on migration behavior.
		$behavior  = get_option( 'amb_migration_behavior', 'replace' );
		$target_id = $post_id;

		if ( 'duplicate' === $behavior ) {
			$new_id = $this->duplicate_page( $post );
			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}
			$target_id = $new_id;
		}

		return new \WP_REST_Response(
			array(
				'html'      => $parsed['html'],
				'title'     => ! empty( $parsed['title'] ) ? $parsed['title'] : $post->post_title,
				'message'   => __( 'Page migrated successfully.', 'antimanual-builder' ),
				'newPostId' => $target_id !== $post_id ? $target_id : null,
			),
			200
		);
	}

	/**
	 * Migrate a page directly without AI.
	 *
	 * Renders the page content through WordPress filters, collects
	 * front-end CSS from the active theme and plugins, and saves
	 * the result as an AM Builder page preserving the exact design.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function migrate_page_direct( $request ) {
		$post_id = (int) $request->get_param( 'postId' );
		$post    = get_post( $post_id );

		if ( ! $post || AMB_Page_Integration::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'not_found',
				__( 'Page not found.', 'antimanual-builder' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to migrate this page.', 'antimanual-builder' ),
				array( 'status' => 403 )
			);
		}

		if ( AMB_Page_Integration::is_builder_page( $post ) ) {
			return new \WP_Error(
				'already_builder_page',
				__( 'This page is already managed by AM Builder.', 'antimanual-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( ! AMB_Page_Integration::has_migratable_content( $post ) ) {
			return new \WP_Error(
				'empty_page',
				__( 'This page does not have enough content to migrate.', 'antimanual-builder' ),
				array( 'status' => 400 )
			);
		}

		// Render the page content through WordPress filters to get final HTML.
		$rendered_html = apply_filters( 'the_content', $post->post_content );

		// Collect CSS from the front-end by simulating a page render.
		$css = $this->collect_frontend_css_for_page( $post );

		// Combine into a self-contained HTML block with scoped styles.
		$final_html = $rendered_html;
		if ( ! empty( $css ) ) {
			$final_html = "<style>\n" . $css . "\n</style>\n" . $rendered_html;
		}

		// Determine the target post ID based on migration behavior.
		$behavior  = get_option( 'amb_migration_behavior', 'replace' );
		$target_id = $post_id;

		if ( 'duplicate' === $behavior ) {
			$new_id = $this->duplicate_page( $post );
			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}
			$target_id = $new_id;
		}

		return new \WP_REST_Response(
			array(
				'html'      => $final_html,
				'title'     => $post->post_title,
				'message'   => __( 'Page migrated successfully.', 'antimanual-builder' ),
				'newPostId' => $target_id !== $post_id ? $target_id : null,
			),
			200
		);
	}

	/**
	 * Save migration mode preference.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function save_migration_mode( $request ) {
		$mode = sanitize_key( $request->get_param( 'mode' ) );

		if ( ! in_array( $mode, array( 'ai', 'direct' ), true ) ) {
			$mode = 'ai';
		}

		update_option( 'amb_migration_mode', $mode, false );

		return new \WP_REST_Response(
			array( 'message' => __( 'Migration mode saved successfully.', 'antimanual-builder' ) ),
			200
		);
	}

	/**
	 * Save migration behavior preference.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function save_migration_behavior( $request ) {
		$behavior = sanitize_key( $request->get_param( 'behavior' ) );

		if ( ! in_array( $behavior, array( 'replace', 'duplicate' ), true ) ) {
			$behavior = 'replace';
		}

		update_option( 'amb_migration_behavior', $behavior, false );

		return new \WP_REST_Response(
			array( 'message' => __( 'Migration behavior saved successfully.', 'antimanual-builder' ) ),
			200
		);
	}

	/**
	 * Create a draft duplicate of a page.
	 *
	 * Copies the post's core fields into a new draft post.
	 * The original page remains untouched.
	 *
	 * @since  1.0.0
	 * @param  WP_Post $post The source post to duplicate.
	 * @return int|WP_Error  New post ID on success, WP_Error on failure.
	 */
	private function duplicate_page( $post ) {
		$new_post_data = array(
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_type'    => $post->post_type,
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
			'post_parent'  => $post->post_parent,
		);

		$new_id = wp_insert_post( $new_post_data, true );

		if ( is_wp_error( $new_id ) ) {
			return new \WP_Error(
				'duplicate_failed',
				__( 'Failed to create duplicate page.', 'antimanual-builder' ),
				array( 'status' => 500 )
			);
		}

		// Copy featured image if set.
		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		if ( $thumbnail_id ) {
			set_post_thumbnail( $new_id, $thumbnail_id );
		}

		return $new_id;
	}

	/**
	 * Collect front-end CSS for a given page.
	 *
	 * Simulates a front-end page load to capture enqueued stylesheets,
	 * then fetches their contents and returns them as a combined string.
	 *
	 * @since  1.0.0
	 * @param  WP_Post $post Page post object.
	 * @return string Combined CSS content.
	 */
	private function collect_frontend_css_for_page( $post ) {
		global $wp_styles;

		// Set up the global post context for proper style enqueueing.
		$original_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		// Reset and re-enqueue styles as if loading the front-end.
		if ( ! ( $wp_styles instanceof \WP_Styles ) ) {
			$wp_styles = new \WP_Styles();
		}

		do_action( 'wp_enqueue_scripts' );

		$css_parts = array();

		// Collect the relevant stylesheet URLs.
		$style_handles = $wp_styles->queue;

		foreach ( $style_handles as $handle ) {
			if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
				continue;
			}

			$style = $wp_styles->registered[ $handle ];
			$src   = $style->src ?? '';

			if ( empty( $src ) ) {
				continue;
			}

			// Skip admin and builder-specific styles.
			if (
				strpos( $handle, 'amb-' ) === 0 ||
				strpos( $handle, 'admin' ) !== false ||
				strpos( $handle, 'dashicons' ) !== false
			) {
				continue;
			}

			// Resolve relative URLs.
			if ( strpos( $src, '//' ) === false ) {
				$src = site_url( $src );
			}

			// Convert URL to local file path for faster reading.
			$local_path = $this->url_to_local_path( $src );

			if ( $local_path && file_exists( $local_path ) ) {
				$content = file_get_contents( $local_path );
				if ( ! empty( $content ) ) {
					$css_parts[] = "/* Source: {$handle} */\n" . $content;
				}
			}

			// Also collect any inline styles for this handle.
			if ( ! empty( $style->extra['after'] ) ) {
				$css_parts[] = "/* Inline: {$handle} */\n" . implode( "\n", $style->extra['after'] );
			}
		}

		// Restore original post context.
		if ( $original_post ) {
			$GLOBALS['post'] = $original_post;
			setup_postdata( $original_post );
		} else {
			wp_reset_postdata();
		}

		return implode( "\n\n", $css_parts );
	}

	/**
	 * Convert a URL to a local filesystem path.
	 *
	 * @since  1.0.0
	 * @param  string $url URL to resolve.
	 * @return string|false Local path or false if not resolvable.
	 */
	private function url_to_local_path( $url ) {
		$site_url    = site_url();
		$abspath     = untrailingslashit( ABSPATH );
		$content_url = content_url();
		$content_dir = untrailingslashit( WP_CONTENT_DIR );

		// Try content directory first (most common for themes/plugins).
		if ( strpos( $url, $content_url ) === 0 ) {
			return $content_dir . substr( $url, strlen( $content_url ) );
		}

		// Try site URL.
		if ( strpos( $url, $site_url ) === 0 ) {
			return $abspath . substr( $url, strlen( $site_url ) );
		}

		// Protocol-relative URL.
		$scheme_less_site = preg_replace( '#^https?:#', '', $site_url );
		if ( strpos( $url, $scheme_less_site ) === 0 ) {
			return $abspath . substr( $url, strlen( $scheme_less_site ) );
		}

		return false;
	}

	/**
	 * Refine an existing page based on instructions.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refine( $request ) {
		$prompt           = $request->get_param( 'prompt' );
		$html             = $request->get_param( 'html' );
		$selected_section = $request->get_param( 'selectedSection' );

		$system_prompt = $this->build_refinement_system_prompt();

		$user_prompt = "Here is the current page HTML:\n\n" . $html;

		if ( ! empty( $selected_section ) ) {
			$user_prompt .= "\n\nThe user selected this specific section to modify:\n" . $selected_section;
		}

		$user_prompt .= "\n\nUser's refinement request: " . $prompt;

		$response = $this->call_ai_api( $system_prompt, $user_prompt );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return new \WP_REST_Response(
			array(
				'html'    => $response['content'],
				'message' => __( 'Page refined successfully.', 'antimanual-builder' ),
			),
			200
		);
	}

	/**
	 * Enhance a user prompt.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function enhance_prompt( $request ) {
		$prompt = $request->get_param( 'prompt' );

		$system_prompt = 'You are a web design expert. The user will give you a brief description of a web page they want to build. '
			. 'Expand their description into a detailed, comprehensive prompt that covers layout, sections, content, colors, and functionality. '
			. 'Keep the enhanced prompt concise but detailed. Return ONLY the enhanced prompt text, nothing else.';

		$response = $this->call_ai_api( $system_prompt, $prompt );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return new \WP_REST_Response(
			array(
				'enhancedPrompt' => $response['content'],
			),
			200
		);
	}

	/**
	 * Convert HTML to block JSON.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function html_to_blocks( $request ) {
		$html = $request->get_param( 'html' );

		// Parse HTML into block structure.
		$blocks = $this->parse_html_to_blocks( $html );

		return new \WP_REST_Response(
			array(
				'blocks' => $blocks,
			),
			200
		);
	}

	/**
	 * Analyze or import a ZIP-based HTML project into multiple builder pages.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_project_zip( $request ) {
		$mode  = 'create' === $request->get_param( 'mode' ) ? 'create' : 'analyze';
		$files = $request->get_file_params();
		$file  = isset( $files['projectZip'] ) ? $files['projectZip'] : null;
		$this->project_file_content_cache = array();

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			return new \WP_Error(
				'missing_zip',
				__( 'Upload a ZIP project file before starting the import.', 'antimanual-builder' ),
				array( 'status' => 400 )
			);
		}

		$filename  = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$tmp_name  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$file_size = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( '' === $filename || '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return new \WP_Error(
				'invalid_zip',
				__( 'The uploaded project file could not be read.', 'antimanual-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( $file_size <= 0 ) {
			return new \WP_Error(
				'empty_zip',
				__( 'The uploaded ZIP project is empty.', 'antimanual-builder' ),
				array( 'status' => 400 )
			);
		}

		$filetype  = wp_check_filetype_and_ext( $tmp_name, $filename );
		$extension = ! empty( $filetype['ext'] )
			? strtolower( $filetype['ext'] )
			: strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( 'zip' !== $extension ) {
			return new \WP_Error(
				'invalid_zip_type',
				__( 'Please upload a valid ZIP file for project import.', 'antimanual-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error(
				'zip_unavailable',
				__( 'ZIP imports require the ZipArchive PHP extension.', 'antimanual-builder' ),
				array( 'status' => 500 )
			);
		}

		$project = $this->extract_project_zip_manifest( $file['tmp_name'] );
		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$analysis = $this->analyze_project_pages_with_ai( $project );
		if ( is_wp_error( $analysis ) ) {
			return $analysis;
		}

		$prepared_pages = array();
		foreach ( $analysis['pages'] as $page_candidate ) {
			$prepared_page = $this->prepare_project_page_import(
				$page_candidate,
				$project,
				$analysis['pages']
			);

			if ( ! empty( $prepared_page ) ) {
				$prepared_pages[] = $prepared_page;
			}
		}

		if ( empty( $prepared_pages ) ) {
			return new \WP_Error(
				'no_pages_prepared',
				__( 'No importable HTML pages were found in this ZIP project.', 'antimanual-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( 'analyze' === $mode ) {
			return new \WP_REST_Response(
				array(
					'mode'        => 'analyze',
					'fileCount'   => count( $project['files'] ),
					'htmlCount'   => count( $project['html_files'] ),
					'projectNotes'=> $analysis['notes'],
					'pages'       => array_map(
						array( $this, 'format_project_import_page_summary' ),
						$prepared_pages
					),
				),
				200
			);
		}

		$created_pages = $this->create_project_import_pages( $prepared_pages, $project );
		if ( is_wp_error( $created_pages ) ) {
			return $created_pages;
		}

		return new \WP_REST_Response(
			array(
				'mode'         => 'create',
				'message'      => __( 'ZIP project imported successfully.', 'antimanual-builder' ),
				'createdPages' => $created_pages,
				'projectNotes' => $analysis['notes'],
			),
			200
		);
	}

	/**
	 * Extract a safe project manifest from a ZIP file.
	 *
	 * @since  1.0.0
	 * @param  string $zip_path ZIP file path.
	 * @return array|WP_Error
	 */
	private function extract_project_zip_manifest( $zip_path ) {
		$zip    = new \ZipArchive();
		$result = $zip->open( $zip_path );

		if ( true !== $result ) {
			return new \WP_Error(
				'invalid_zip',
				__( 'The ZIP project could not be opened. Please upload a valid ZIP file.', 'antimanual-builder' ),
				array( 'status' => 400 )
			);
		}

		$files      = array();
		$html_files = array();
		$file_count = 0;

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$stat = $zip->statIndex( $index );
			if ( ! is_array( $stat ) || empty( $stat['name'] ) ) {
				continue;
			}

			$path = $this->normalize_project_path( $stat['name'] );
			if ( empty( $path ) || str_ends_with( $path, '/' ) || $this->should_skip_project_path( $path ) ) {
				continue;
			}

			if ( $file_count >= self::PROJECT_IMPORT_MAX_FILES ) {
				break;
			}

			++$file_count;

			$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			$is_text   = in_array( $extension, array( 'html', 'htm', 'css', 'js', 'mjs', 'json', 'txt', 'svg', 'xml' ), true );

			$file_record = array(
				'path'      => $path,
				'size'      => isset( $stat['size'] ) ? (int) $stat['size'] : 0,
				'extension' => $extension,
				'is_text'   => $is_text,
				'index'     => $index,
			);

			$files[ $path ] = $file_record;

			if ( in_array( $extension, array( 'html', 'htm' ), true ) ) {
				$html_files[] = $path;
			}
		}

		$zip->close();

		if ( empty( $html_files ) ) {
			return new \WP_Error(
				'no_html_files',
				__( 'The ZIP project does not contain any HTML files to import.', 'antimanual-builder' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'zip_path'     => $zip_path,
			'files'        => $files,
			'html_files'   => $html_files,
			'root_prefix'  => $this->detect_project_root_prefix( array_keys( $files ) ),
		);
	}

	/**
	 * Analyze ZIP project HTML files with AI to identify real pages.
	 *
	 * @since  1.0.0
	 * @param  array $project Project manifest.
	 * @return array|WP_Error
	 */
	private function analyze_project_pages_with_ai( $project ) {
		$html_summaries = array();

		foreach ( $project['html_files'] as $html_file ) {
			$summary = $this->summarize_project_html_file( $html_file, $project );

			if ( ! empty( $summary ) ) {
				$html_summaries[] = $summary;
			}
		}

		if ( empty( $html_summaries ) ) {
			return new \WP_Error(
				'empty_project_summary',
				__( 'The ZIP project HTML files could not be summarized for import.', 'antimanual-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $this->get_configured_ai_api_key() ) {
			return $this->fallback_project_page_plan(
				$project,
				$html_summaries,
				array(
					__( 'AI is not configured, so AM Builder used fast file-based page detection for this ZIP project.', 'antimanual-builder' ),
				)
			);
		}

		$summary_payload = wp_json_encode( $html_summaries );
		$summary_bytes   = is_string( $summary_payload ) ? strlen( $summary_payload ) : 0;

		if (
			count( $html_summaries ) > self::PROJECT_IMPORT_MAX_AI_HTML_FILES ||
			$summary_bytes > self::PROJECT_IMPORT_MAX_AI_SUMMARY_BYTES
		) {
			return $this->fallback_project_page_plan(
				$project,
				$html_summaries,
				array(
					sprintf(
						/* translators: %s: HTML file count. */
						__( 'Large ZIP project detected (%s HTML files). Fast file-based page detection was used instead of AI to avoid import timeouts.', 'antimanual-builder' ),
						number_format_i18n( count( $html_summaries ) )
					),
				)
			);
		}

		$system_prompt = 'You are an expert web project analyst. The user will provide summaries of HTML files from a website ZIP project. '
			. 'Identify which files represent full standalone pages that should become WordPress pages. '
			. 'Ignore partials, shared templates, snippets, includes, popups, test fixtures, and non-page fragments. '
			. 'Prefer files that look like public-facing pages such as home, about, contact, services, blog, pricing, or landing pages. '
			. 'Return JSON only with this shape: {"notes":["..."],"pages":[{"sourceFile":"path/to/file.html","title":"Suggested Page Title","description":"Short reason this is a page"}]}. '
			. 'Keep the pages list to at most ' . self::PROJECT_IMPORT_MAX_PAGES . ' items. '
			. 'Do not include markdown fences or explanations outside the JSON.';

		$user_prompt = "Project HTML file summaries:\n" . $summary_payload;
		$response    = $this->call_ai_api( $system_prompt, $user_prompt );

		if ( is_wp_error( $response ) ) {
			return $this->fallback_project_page_plan(
				$project,
				$html_summaries,
				array(
					sprintf(
						/* translators: %s: AI error message. */
						__( 'AI page detection could not finish (%s). Fast file-based page detection was used instead.', 'antimanual-builder' ),
						sanitize_text_field( $response->get_error_message() )
					),
				)
			);
		}

		$parsed_pages = $this->parse_project_page_plan(
			isset( $response['content'] ) ? $response['content'] : '',
			$project
		);

		if ( empty( $parsed_pages['pages'] ) ) {
			$parsed_pages = $this->fallback_project_page_plan(
				$project,
				$html_summaries,
				array(
					__( 'AI page detection returned no usable page plan, so fast file-based page detection was used instead.', 'antimanual-builder' ),
				)
			);
		}

		return $parsed_pages;
	}

	/**
	 * Summarize an HTML file for AI analysis.
	 *
	 * @since  1.0.0
	 * @param  string $path    Project file path.
	 * @param  array  $project Project manifest.
	 * @return array|null
	 */
	private function summarize_project_html_file( $path, $project ) {
		$content = $this->get_project_file_content( $project, $path );

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return null;
		}

		$title    = $this->extract_title_from_html_string( $content );
		$headline = $this->extract_headline_from_html_string( $content );
		$text     = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $content ) ) );
		$excerpt  = mb_substr( $text, 0, 220 );
		preg_match_all( '/<link[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $stylesheet_matches );
		preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $script_matches );

		return array(
			'path'         => $path,
			'title'        => $title,
			'headline'     => $headline,
			'excerpt'      => $excerpt,
			'stylesheets'  => isset( $stylesheet_matches[1] ) ? array_slice( $stylesheet_matches[1], 0, 8 ) : array(),
			'scripts'      => isset( $script_matches[1] ) ? array_slice( $script_matches[1], 0, 8 ) : array(),
			'filenameHint' => strtolower( basename( $path ) ),
			'directoryDepth' => substr_count( $path, '/' ),
			'hasDocumentShell' => (bool) preg_match( '/<(?:html|body)\b/i', $content ),
		);
	}

	/**
	 * Parse the AI page plan for a ZIP project.
	 *
	 * @since  1.0.0
	 * @param  string $content Raw AI response.
	 * @param  array  $project Project manifest.
	 * @return array
	 */
	private function parse_project_page_plan( $content, $project ) {
		$trimmed = trim( $content );

		if ( preg_match( '/^```(?:json)?\s*\n?(.*?)\n?```$/s', $trimmed, $fence_match ) ) {
			$trimmed = trim( $fence_match[1] );
		}

		$parsed = json_decode( $trimmed, true );
		$pages  = array();
		$notes  = array();

		if ( is_array( $parsed ) ) {
			if ( ! empty( $parsed['notes'] ) && is_array( $parsed['notes'] ) ) {
				$notes = array_values(
					array_filter(
						array_map( 'sanitize_text_field', $parsed['notes'] )
					)
				);
			}

			if ( ! empty( $parsed['pages'] ) && is_array( $parsed['pages'] ) ) {
				foreach ( $parsed['pages'] as $page ) {
					$source_file = isset( $page['sourceFile'] ) ? $this->normalize_project_path( $page['sourceFile'] ) : '';

					if ( empty( $source_file ) || ! isset( $project['files'][ $source_file ] ) ) {
						continue;
					}

					$pages[] = array(
						'sourceFile'   => $source_file,
						'title'        => isset( $page['title'] ) ? sanitize_text_field( $page['title'] ) : '',
						'description'  => isset( $page['description'] ) ? sanitize_text_field( $page['description'] ) : '',
					);
				}
			}
		}

		$pages = array_slice( $pages, 0, self::PROJECT_IMPORT_MAX_PAGES );

		return array(
			'notes' => $notes,
			'pages' => $pages,
		);
	}

	/**
	 * Heuristic fallback when AI page planning is unavailable or empty.
	 *
	 * @since  1.0.0
	 * @param  array $project        Project manifest.
	 * @param  array $html_summaries HTML summaries.
	 * @param  array $extra_notes    Additional notes to include.
	 * @return array
	 */
	private function fallback_project_page_plan( $project, $html_summaries, $extra_notes = array() ) {
		$ignored_tokens = array( 'partial', 'header', 'footer', 'nav', 'menu', 'snippet', 'include', 'layout', 'template', 'modal', 'popup', 'component', 'elements', 'fragment', 'widget', 'dialog', 'embed', 'test', 'demo', 'example' );
		$page_keywords  = array( 'index', 'home', 'about', 'contact', 'service', 'pricing', 'portfolio', 'team', 'faq', 'career', 'blog', 'news', 'shop', 'product', 'landing', 'terms', 'privacy', 'support' );
		$pages          = array();

		foreach ( $html_summaries as $summary ) {
			$filename = isset( $summary['filenameHint'] ) ? (string) $summary['filenameHint'] : '';
			$path     = isset( $summary['path'] ) ? (string) $summary['path'] : '';
			$score    = 0;
			$should_ignore = false;

			foreach ( $ignored_tokens as $token ) {
				if ( false !== strpos( $filename, $token ) || false !== strpos( $path, '/' . $token ) ) {
					$should_ignore = true;
					break;
				}
			}

			if ( $should_ignore || str_starts_with( $filename, '_' ) ) {
				continue;
			}

			$basename = strtolower( basename( $path, '.' . pathinfo( $path, PATHINFO_EXTENSION ) ) );
			if ( in_array( $basename, array( 'index', 'home', 'default' ), true ) ) {
				$score += 30;
			}

			if ( ! empty( $summary['title'] ) ) {
				$score += 10;
			}

			if ( ! empty( $summary['headline'] ) ) {
				$score += 8;
			}

			if ( ! empty( $summary['hasDocumentShell'] ) ) {
				$score += 8;
			}

			if ( isset( $summary['directoryDepth'] ) ) {
				$score += max( 0, 8 - (int) $summary['directoryDepth'] * 2 );
			}

			if ( ! empty( $summary['excerpt'] ) && strlen( (string) $summary['excerpt'] ) > 80 ) {
				$score += 5;
			}

			foreach ( $page_keywords as $keyword ) {
				if ( false !== strpos( $basename, $keyword ) || false !== strpos( $path, '/' . $keyword ) ) {
					$score += 6;
					break;
				}
			}

			$pages[] = array(
				'sourceFile'  => $summary['path'],
				'title'       => ! empty( $summary['title'] ) ? $summary['title'] : ucwords( str_replace( array( '-', '_' ), ' ', basename( $summary['path'], '.' . pathinfo( $summary['path'], PATHINFO_EXTENSION ) ) ) ),
				'description' => __( 'Detected as a standalone HTML page by fallback rules.', 'antimanual-builder' ),
				'score'       => $score,
			);
		}

		usort(
			$pages,
			static function ( $left, $right ) {
				$left_score  = isset( $left['score'] ) ? (int) $left['score'] : 0;
				$right_score = isset( $right['score'] ) ? (int) $right['score'] : 0;

				if ( $left_score === $right_score ) {
					return strcmp( (string) $left['sourceFile'], (string) $right['sourceFile'] );
				}

				return $right_score <=> $left_score;
			}
		);

		$pages = array_map(
			static function ( $page ) {
				unset( $page['score'] );
				return $page;
			},
			$pages
		);

		$notes = array_merge(
			array(
				__( 'AM Builder used fast file-based page detection for this ZIP project.', 'antimanual-builder' ),
			),
			array_values(
				array_filter(
					array_map( 'sanitize_text_field', $extra_notes )
				)
			)
		);

		if ( count( $pages ) > self::PROJECT_IMPORT_MAX_PAGES ) {
			$notes[] = sprintf(
				/* translators: 1: number of imported pages, 2: number of skipped pages. */
				__( 'Only the first %1$s detected pages will be imported from this ZIP project. %2$s additional HTML pages were skipped.', 'antimanual-builder' ),
				number_format_i18n( self::PROJECT_IMPORT_MAX_PAGES ),
				number_format_i18n( count( $pages ) - self::PROJECT_IMPORT_MAX_PAGES )
			);
		}

		return array(
			'notes' => array_values( array_unique( $notes ) ),
			'pages' => array_slice( $pages, 0, self::PROJECT_IMPORT_MAX_PAGES ),
		);
	}

	/**
	 * Prepare one analyzed project page for builder import.
	 *
	 * @since  1.0.0
	 * @param  array $page_candidate Analyzed page candidate.
	 * @param  array $project        Project manifest.
	 * @param  array $all_pages      All page candidates.
	 * @return array|null
	 */
	private function prepare_project_page_import( $page_candidate, $project, $all_pages ) {
		$source_file = isset( $page_candidate['sourceFile'] ) ? $page_candidate['sourceFile'] : '';
		$content     = $this->get_project_file_content( $project, $source_file );

		if ( empty( $source_file ) || ! is_string( $content ) || '' === trim( $content ) ) {
			return null;
		}

		$warnings = array();
		$page_map = array();
		foreach ( $all_pages as $page ) {
			if ( ! empty( $page['sourceFile'] ) ) {
				$page_map[ $page['sourceFile'] ] = true;
			}
		}

		$custom_css   = array();
		$head_markup  = array();
		$footer_markup = array();
		$dom          = $this->load_project_html_document( $content );

		if ( ! ( $dom instanceof \DOMDocument ) ) {
			return null;
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			$body = $dom->documentElement;
		}
		$html_element = $dom->getElementsByTagName( 'html' )->item( 0 );

		$xpath = new \DOMXPath( $dom );

		foreach ( iterator_to_array( $xpath->query( '//style' ) ) as $style_element ) {
			$custom_css[] = $this->rewrite_project_css_urls(
				$style_element->textContent,
				$source_file,
				$project,
				$warnings
			);
			$style_element->parentNode->removeChild( $style_element );
		}

		foreach ( iterator_to_array( $xpath->query( '//link[@href]' ) ) as $link_element ) {
			$rel  = strtolower( (string) $link_element->getAttribute( 'rel' ) );
			$href = (string) $link_element->getAttribute( 'href' );

			if ( false !== strpos( $rel, 'stylesheet' ) ) {
				$resolved = $this->resolve_project_reference( $href, $source_file, $project );

				if ( 'local' === $resolved['type'] ) {
					$css_content = $this->collect_project_stylesheet_content(
						$resolved['path'],
						$project,
						$warnings
					);
					if ( '' !== trim( $css_content ) ) {
						$custom_css[] = $css_content;
					}
				} elseif ( 'external' === $resolved['type'] ) {
					$link_element->setAttribute( 'href', $resolved['value'] );
					$head_markup[] = $dom->saveHTML( $link_element );
				}

				$link_element->parentNode->removeChild( $link_element );
				continue;
			}

			$resolved = $this->resolve_project_reference( $href, $source_file, $project, $page_map );
			if ( 'local-asset' === $resolved['type'] && ! empty( $resolved['value'] ) ) {
				$link_element->setAttribute( 'href', $resolved['value'] );
			} elseif ( 'internal-page' === $resolved['type'] ) {
				$link_element->setAttribute( 'href', $resolved['value'] );
			} elseif ( 'external' === $resolved['type'] ) {
				$link_element->setAttribute( 'href', $resolved['value'] );
			}

			$head_markup[] = $dom->saveHTML( $link_element );
			$link_element->parentNode->removeChild( $link_element );
		}

		foreach ( iterator_to_array( $xpath->query( '//script' ) ) as $script_element ) {
			$src = (string) $script_element->getAttribute( 'src' );

			if ( $src ) {
				$resolved = $this->resolve_project_reference( $src, $source_file, $project );

				if ( 'local-asset' === $resolved['type'] && ! empty( $resolved['value'] ) ) {
					// JavaScript files are uploaded as separate assets and referenced via placeholder.
					$script_element->setAttribute( 'src', $resolved['value'] );
					$footer_markup[] = $dom->saveHTML( $script_element );
				} elseif ( 'external' === $resolved['type'] ) {
					$script_element->setAttribute( 'src', $resolved['value'] );
					$footer_markup[] = $dom->saveHTML( $script_element );
				}
			} else {
				// Inline script without src attribute - preserve as-is.
				$footer_markup[] = $dom->saveHTML( $script_element );
			}

			$script_element->parentNode->removeChild( $script_element );
		}

		if ( $body instanceof \DOMElement ) {
			$this->rewrite_project_dom_assets( $body, $source_file, $project, $page_map, $warnings );
			$this->ensure_project_body_sections( $dom, $body );
		}

		$rendered_html = $this->get_inner_html( $body );
		$title         = ! empty( $page_candidate['title'] )
			? $page_candidate['title']
			: $this->extract_title_from_html_string( $content );

		return array(
			'title'              => $title ? $title : __( 'Imported Project Page', 'antimanual-builder' ),
			'sourceFile'         => $source_file,
			'description'        => isset( $page_candidate['description'] ) ? $page_candidate['description'] : '',
			'renderedHtml'       => trim( $rendered_html ),
			'customCss'          => trim( implode( "\n\n", array_filter( $custom_css ) ) ),
			'importedHeadMarkup' => trim( implode( "\n", array_filter( $head_markup ) ) ),
			'importedFooterMarkup' => trim( implode( "\n", array_filter( $footer_markup ) ) ),
			'importedHtmlAttributes' => $this->extract_project_document_attributes( $html_element ),
			'importedBodyAttributes' => $this->extract_project_document_attributes( $body ),
			'warnings'           => array_values( array_unique( array_filter( $warnings ) ) ),
		);
	}

	/**
	 * Load project HTML into a DOMDocument without deprecated HTML-ENTITIES conversion.
	 *
	 * @since  1.0.0
	 * @param  string $html Raw HTML content.
	 * @return DOMDocument|null
	 */
	private function load_project_html_document( $html ) {
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return null;
		}

		$dom                  = new \DOMDocument( '1.0', 'UTF-8' );
		$previous_error_state = libxml_use_internal_errors( true );
		$loaded               = $dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		foreach ( iterator_to_array( $dom->childNodes ) as $node ) {
			if ( $node instanceof \DOMProcessingInstruction && 'xml' === strtolower( $node->target ) ) {
				$dom->removeChild( $node );
				break;
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_error_state );

		if ( ! $loaded ) {
			return null;
		}

		return $dom;
	}

	/**
	 * Create draft pages from prepared ZIP project imports.
	 *
	 * @since  1.0.0
	 * @param  array $prepared_pages Prepared page payloads.
	 * @return array|WP_Error
	 */
	private function create_project_import_pages( $prepared_pages, $project ) {
		$asset_paths = $this->collect_project_asset_paths_from_prepared_pages( $prepared_pages );
		$asset_url_map = $this->upload_project_import_assets(
			$asset_paths,
			$project,
			'import-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false )
		);

		if ( is_wp_error( $asset_url_map ) ) {
			return $asset_url_map;
		}

		$created       = array();
		$permalink_map = array();

		foreach ( $prepared_pages as $prepared_page ) {
			$prepared_page = $this->replace_project_asset_placeholders_in_page(
				$prepared_page,
				$asset_url_map
			);

			$sanitized_rendered_html = isset( $prepared_page['renderedHtml'] )
				? $this->sanitize_builder_html_value( $prepared_page['renderedHtml'] )
				: '';
			$sanitized_custom_css    = isset( $prepared_page['customCss'] )
				? $this->sanitize_builder_css_value( $prepared_page['customCss'] )
				: '';
			$page_settings           = $this->sanitize_builder_page_settings_array(
				array(
					'layoutMode'             => 'canvas',
					'importedHeadMarkup'     => $prepared_page['importedHeadMarkup'],
					'importedFooterMarkup'   => $prepared_page['importedFooterMarkup'],
					'importedHtmlAttributes' => $prepared_page['importedHtmlAttributes'],
					'importedBodyAttributes' => $prepared_page['importedBodyAttributes'],
				)
			);

			$post_id = wp_insert_post(
				array(
					'post_type'    => AMB_Page_Integration::POST_TYPE,
					'post_title'   => sanitize_text_field( $prepared_page['title'] ),
					'post_status'  => 'draft',
					'post_author'  => get_current_user_id(),
					'post_content' => $sanitized_rendered_html,
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			$this->save_builder_meta(
				$post_id,
				array(
					'renderedHtml' => $sanitized_rendered_html,
					'customCss'    => $sanitized_custom_css,
				)
			);
			update_post_meta(
				$post_id,
				'_amb_page_settings',
				wp_slash( wp_json_encode( $page_settings ) )
			);

			$created[] = array(
				'id'          => $post_id,
				'title'       => $prepared_page['title'],
				'sourceFile'  => $prepared_page['sourceFile'],
				'pageSettings'=> $page_settings,
				'renderedHtml'=> $sanitized_rendered_html,
				'customCss'   => $sanitized_custom_css,
				'warnings'    => $prepared_page['warnings'],
			);
			$permalink_map[ $prepared_page['sourceFile'] ] = get_permalink( $post_id );
		}

		foreach ( $created as &$created_page ) {
			$updated_html = $this->sanitize_builder_html_value( $this->replace_project_internal_placeholders(
				$created_page['renderedHtml'],
				$permalink_map
			) );
			$updated_settings = $this->sanitize_builder_page_settings_array( array(
				'layoutMode'             => 'canvas',
				'importedHeadMarkup'     => $this->replace_project_internal_placeholders(
					$created_page['pageSettings']['importedHeadMarkup'],
					$permalink_map
				),
				'importedFooterMarkup' => $this->replace_project_internal_placeholders(
					$created_page['pageSettings']['importedFooterMarkup'],
					$permalink_map
				),
				'importedHtmlAttributes' => isset( $created_page['pageSettings']['importedHtmlAttributes'] ) ? $created_page['pageSettings']['importedHtmlAttributes'] : array(),
				'importedBodyAttributes' => isset( $created_page['pageSettings']['importedBodyAttributes'] ) ? $created_page['pageSettings']['importedBodyAttributes'] : array(),
			) );

			wp_update_post(
				array(
					'ID'           => $created_page['id'],
					'post_content' => $updated_html,
				)
			);
			update_post_meta( $created_page['id'], '_amb_rendered_html', $updated_html );
			update_post_meta(
				$created_page['id'],
				'_amb_page_settings',
				wp_slash( wp_json_encode( $updated_settings ) )
			);
			do_action( 'amb_page_saved', $created_page['id'], get_post( $created_page['id'] ) );

			$created_page = array(
				'id'        => $created_page['id'],
				'title'     => get_the_title( $created_page['id'] ),
				'permalink' => get_permalink( $created_page['id'] ),
				'editUrl'   => admin_url( 'admin.php?page=amb-editor&post_id=' . $created_page['id'] ),
				'sourceFile'=> $created_page['sourceFile'],
				'warnings'  => $created_page['warnings'],
			);
		}
		unset( $created_page );

		if ( class_exists( 'AMB_API_Pages_Api' ) ) {
			update_option( AMB_API_Pages_Api::LIST_CACHE_VERSION_OPTION, time(), false );
		}

		return $created;
	}

	/**
	 * Format a prepared project page for admin analysis output.
	 *
	 * @since  1.0.0
	 * @param  array $page Prepared page.
	 * @return array
	 */
	private function format_project_import_page_summary( $page ) {
		return array(
			'title'       => $page['title'],
			'sourceFile'  => $page['sourceFile'],
			'description' => $page['description'],
			'warningCount'=> count( $page['warnings'] ),
			'warnings'    => $page['warnings'],
			'sections'    => substr_count( $page['renderedHtml'], 'data-amb-section=' ),
			'htmlLength'  => strlen( $page['renderedHtml'] ),
		);
	}

	/**
	 * Rewrite local assets and internal page links in a DOM subtree.
	 *
	 * @since  1.0.0
	 * @param  DOMElement $root       Root element.
	 * @param  string     $source_file Current source file.
	 * @param  array      $project    Project manifest.
	 * @param  array      $page_map   Internal page file map.
	 * @param  array      $warnings   Warning collector.
	 * @return void
	 */
	private function rewrite_project_dom_assets( $root, $source_file, $project, $page_map, &$warnings ) {
		$elements = $root->getElementsByTagName( '*' );

		foreach ( $elements as $element ) {
			if ( ! ( $element instanceof \DOMElement ) ) {
				continue;
			}

			foreach ( array( 'src', 'href', 'poster', 'data-src' ) as $attribute_name ) {
				if ( ! $element->hasAttribute( $attribute_name ) ) {
					continue;
				}

				$current_value = (string) $element->getAttribute( $attribute_name );
				$resolved      = $this->resolve_project_reference( $current_value, $source_file, $project, $page_map );

				if ( in_array( $resolved['type'], array( 'local-asset', 'internal-page', 'external' ), true ) && ! empty( $resolved['value'] ) ) {
					$element->setAttribute( $attribute_name, $resolved['value'] );
				} elseif ( 'unresolved' === $resolved['type'] ) {
					$warnings[] = sprintf(
						/* translators: %s: Unresolved project asset path. */
						__( 'Could not resolve project asset "%s".', 'antimanual-builder' ),
						$current_value
					);
				}
			}

			if ( $element->hasAttribute( 'srcset' ) ) {
				$element->setAttribute(
					'srcset',
					$this->rewrite_project_srcset(
						(string) $element->getAttribute( 'srcset' ),
						$source_file,
						$project,
						$warnings
					)
				);
			}

			if ( $element->hasAttribute( 'style' ) ) {
				$element->setAttribute(
					'style',
					$this->rewrite_project_css_urls(
						(string) $element->getAttribute( 'style' ),
						$source_file,
						$project,
						$warnings
					)
				);
			}
		}
	}

	/**
	 * Resolve a project-relative reference.
	 *
	 * @since  1.0.0
	 * @param  string $value       Raw reference.
	 * @param  string $source_file Current source file path.
	 * @param  array  $project     Project manifest.
	 * @param  array  $page_map    Known internal page map.
	 * @return array
	 */
	private function resolve_project_reference( $value, $source_file, $project, $page_map = array() ) {
		$value = trim( (string) $value );

		if ( '' === $value || '#' === $value || str_starts_with( $value, '#' ) ) {
			return array( 'type' => 'fragment', 'value' => $value );
		}

		if ( preg_match( '#^(?:[a-z][a-z0-9+\-.]*:)?//#i', $value ) || preg_match( '#^(?:data|mailto|tel|javascript):#i', $value ) ) {
			return array( 'type' => 'external', 'value' => $value );
		}

		foreach ( $this->get_project_reference_candidates( $value, $source_file, $project ) as $candidate_path ) {
			if ( isset( $page_map[ $candidate_path ] ) ) {
				return array(
					'type'  => 'internal-page',
					'value' => 'amb-internal://' . rawurlencode( $candidate_path ),
					'path'  => $candidate_path,
				);
			}

			if ( isset( $project['files'][ $candidate_path ] ) ) {
				$file = $project['files'][ $candidate_path ];

				if ( $this->should_inline_project_file_reference( $file ) ) {
					return array(
						'type' => 'local',
						'path' => $candidate_path,
					);
				}

				$asset_placeholder = $this->project_file_to_placeholder( $candidate_path );

				return array(
					'type'  => $asset_placeholder ? 'local-asset' : 'unresolved',
					'value' => $asset_placeholder,
					'path'  => $candidate_path,
				);
			}
		}

		return array(
			'type'  => 'unresolved',
			'value' => $value,
		);
	}

	/**
	 * Rewrite CSS url() references for project assets.
	 *
	 * @since  1.0.0
	 * @param  string $css         CSS content.
	 * @param  string $source_file Current source file.
	 * @param  array  $project     Project manifest.
	 * @param  array  $warnings    Warning collector.
	 * @return string
	 */
	private function rewrite_project_css_urls( $css, $source_file, $project, &$warnings ) {
		return preg_replace_callback(
			'/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
			function( $matches ) use ( $source_file, $project, &$warnings ) {
				$resolved = $this->resolve_project_reference( $matches[2], $source_file, $project );

				if ( in_array( $resolved['type'], array( 'local-asset', 'external' ), true ) && ! empty( $resolved['value'] ) ) {
					return 'url("' . $resolved['value'] . '")';
				}

				if ( 'unresolved' === $resolved['type'] ) {
					$warnings[] = sprintf(
						/* translators: %s: Unresolved CSS asset path. */
						__( 'Could not resolve CSS asset "%s".', 'antimanual-builder' ),
						$matches[2]
					);
				}

				return $matches[0];
			},
			(string) $css
		);
	}

	/**
	 * Inline local CSS @import rules and rewrite asset URLs.
	 *
	 * @since  1.0.0
	 * @param  string $path        CSS file path.
	 * @param  array  $project     Project manifest.
	 * @param  array  $warnings    Warning collector.
	 * @param  array  $visited     Already processed stylesheet paths.
	 * @return string
	 */
	private function collect_project_stylesheet_content( $path, $project, &$warnings, &$visited = array() ) {
		$path = $this->normalize_project_path( $path );

		if ( '' === $path || isset( $visited[ $path ] ) ) {
			return '';
		}

		$visited[ $path ] = true;
		$css              = $this->get_project_file_content( $project, $path );

		if ( ! is_string( $css ) || '' === trim( $css ) ) {
			return '';
		}

		$css = preg_replace_callback(
			'/@import\s+(?:url\(\s*)?["\']?([^"\')\s]+)["\']?\s*\)?\s*([^;]*);/i',
			function( $matches ) use ( $path, $project, &$warnings, &$visited ) {
				$import_target = isset( $matches[1] ) ? (string) $matches[1] : '';
				$media_query   = trim( isset( $matches[2] ) ? (string) $matches[2] : '' );
				$resolved      = $this->resolve_project_reference( $import_target, $path, $project );

				if ( 'local' === $resolved['type'] && ! empty( $resolved['path'] ) ) {
					$imported_css = $this->collect_project_stylesheet_content(
						$resolved['path'],
						$project,
						$warnings,
						$visited
					);

					if ( '' === trim( $imported_css ) ) {
						return '';
					}

					if ( '' !== $media_query ) {
						return '@media ' . $media_query . " {\n" . $imported_css . "\n}";
					}

					return $imported_css;
				}

				if ( 'external' === $resolved['type'] && ! empty( $resolved['value'] ) ) {
					return '@import url("' . $resolved['value'] . '")' . ( '' !== $media_query ? ' ' . $media_query : '' ) . ';';
				}

				$warnings[] = sprintf(
					/* translators: %s: Imported stylesheet path that could not be resolved. */
					__( 'Could not resolve imported stylesheet "%s".', 'antimanual-builder' ),
					$import_target
				);

				return '';
			},
			$css
		);

		return $this->rewrite_project_css_urls( $css, $path, $project, $warnings );
	}

	/**
	 * Rewrite srcset values for project assets.
	 *
	 * @since  1.0.0
	 * @param  string $srcset      Srcset string.
	 * @param  string $source_file Current source file.
	 * @param  array  $project     Project manifest.
	 * @param  array  $warnings    Warning collector.
	 * @return string
	 */
	private function rewrite_project_srcset( $srcset, $source_file, $project, &$warnings ) {
		$candidates = array_map( 'trim', explode( ',', (string) $srcset ) );
		$rewritten  = array();

		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}

			$parts    = preg_split( '/\s+/', $candidate );
			$raw_url  = array_shift( $parts );
			$resolved = $this->resolve_project_reference( $raw_url, $source_file, $project );
			$value    = in_array( $resolved['type'], array( 'local-asset', 'external' ), true ) && ! empty( $resolved['value'] )
				? $resolved['value']
				: $raw_url;

			if ( 'unresolved' === $resolved['type'] ) {
				$warnings[] = sprintf(
					/* translators: %s: Unresolved responsive asset path. */
					__( 'Could not resolve responsive asset "%s".', 'antimanual-builder' ),
					$raw_url
				);
			}

			$rewritten[] = trim( $value . ' ' . implode( ' ', $parts ) );
		}

		return implode( ', ', $rewritten );
	}

	/**
	 * Ensure imported project content has editable section markers.
	 *
	 * @since  1.0.0
	 * @param  DOMDocument $dom  DOM document.
	 * @param  DOMElement  $body Body element.
	 * @return void
	 */
	private function ensure_project_body_sections( $dom, $body ) {
		$section_tags = array( 'section', 'article', 'header', 'footer', 'aside', 'nav', 'main', 'form', 'div' );
		$direct_candidates = array();

		foreach ( $body->childNodes as $child ) {
			if ( $child instanceof \DOMText && '' !== trim( $child->textContent ) ) {
				$section = $dom->createElement( 'section' );
				$section->setAttribute( 'data-amb-section', $this->generate_project_section_id() );
				$paragraph = $dom->createElement( 'p', trim( $child->textContent ) );
				$section->appendChild( $paragraph );
				$body->replaceChild( $section, $child );
				$direct_candidates[] = $section;
				continue;
			}

			if ( $child instanceof \DOMElement && in_array( strtolower( $child->tagName ), $section_tags, true ) ) {
				$direct_candidates[] = $child;
			}
		}

		if ( empty( $direct_candidates ) && $body->firstChild instanceof \DOMElement ) {
			$body->firstChild->setAttribute( 'data-amb-section', $this->generate_project_section_id() );
			return;
		}

		foreach ( $direct_candidates as $candidate ) {
			if ( $candidate instanceof \DOMElement && ! $candidate->hasAttribute( 'data-amb-section' ) ) {
				$candidate->setAttribute( 'data-amb-section', $this->generate_project_section_id() );
			}
		}
	}

	/**
	 * Convert a project file to a data URL when possible.
	 *
	 * @since  1.0.0
	 * @param  string $path    Project file path.
	 * @param  array  $project Project manifest.
	 * @return string
	 */
	private function project_file_to_data_url( $path, $project ) {
		$content = $this->get_project_file_content( $project, $path );

		if ( ! is_string( $content ) || '' === $content ) {
			return '';
		}

		$mime = $this->guess_project_file_mime( $path );

		return 'data:' . $mime . ';base64,' . base64_encode( $content );
	}

	/**
	 * Convert a project asset path to an import placeholder.
	 *
	 * Placeholders are swapped for uploaded WordPress asset URLs when the pages
	 * are actually created. This keeps analyze mode fast while letting create
	 * mode store real files in uploads.
	 *
	 * @since  1.0.0
	 * @param  string $path Project file path.
	 * @return string
	 */
	private function project_file_to_placeholder( $path ) {
		$path = $this->normalize_project_path( $path );

		if ( '' === $path ) {
			return '';
		}

		return 'amb-asset://' . rawurlencode( $path );
	}

	/**
	 * Determine whether a project file should be inlined instead of uploaded.
	 *
	 * @since  1.0.0
	 * @param  array $file Project file record.
	 * @return bool
	 */
	private function should_inline_project_file_reference( $file ) {
		$extension = isset( $file['extension'] ) ? strtolower( (string) $file['extension'] ) : '';

		// CSS is inlined directly into custom CSS field.
		// JavaScript files are uploaded as separate assets to enable browser caching.
		return in_array( $extension, array( 'html', 'htm', 'css', 'json', 'txt', 'xml' ), true );
	}

	/**
	 * Gather placeholder asset paths from prepared project pages.
	 *
	 * @since  1.0.0
	 * @param  array $prepared_pages Prepared page payloads.
	 * @return array
	 */
	private function collect_project_asset_paths_from_prepared_pages( $prepared_pages ) {
		$asset_paths = array();

		foreach ( $prepared_pages as $prepared_page ) {
			foreach (
				array(
					isset( $prepared_page['renderedHtml'] ) ? $prepared_page['renderedHtml'] : '',
					isset( $prepared_page['customCss'] ) ? $prepared_page['customCss'] : '',
					isset( $prepared_page['importedHeadMarkup'] ) ? $prepared_page['importedHeadMarkup'] : '',
					isset( $prepared_page['importedFooterMarkup'] ) ? $prepared_page['importedFooterMarkup'] : '',
				) as $content
			) {
				foreach ( $this->extract_project_asset_placeholders( $content ) as $path ) {
					$asset_paths[ $path ] = true;
				}
			}
		}

		return array_keys( $asset_paths );
	}

	/**
	 * Extract project asset placeholder paths from a content string.
	 *
	 * @since  1.0.0
	 * @param  string $content Content string.
	 * @return array
	 */
	private function extract_project_asset_placeholders( $content ) {
		$paths = array();

		if ( ! is_string( $content ) || '' === $content ) {
			return $paths;
		}

		if ( preg_match_all( '#amb-asset://([A-Za-z0-9%._\-]+)#', $content, $matches ) && ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $encoded_path ) {
				$path = $this->normalize_project_path( rawurldecode( (string) $encoded_path ) );

				if ( '' !== $path ) {
					$paths[ $path ] = true;
				}
			}
		}

		return array_keys( $paths );
	}

	/**
	 * Upload referenced project assets into the WordPress uploads directory.
	 *
	 * @since  1.0.0
	 * @param  array  $asset_paths Asset paths.
	 * @param  array  $project     Project manifest.
	 * @param  string $import_key  Unique import directory key.
	 * @return array|WP_Error
	 */
	private function upload_project_import_assets( $asset_paths, $project, $import_key ) {
		$asset_url_map = array();

		foreach ( $asset_paths as $asset_path ) {
			$asset_url = $this->write_project_asset_to_uploads( $asset_path, $project, $import_key );

			if ( '' === $asset_url ) {
				$asset_url = $this->project_file_to_data_url( $asset_path, $project );
			}

			if ( '' !== $asset_url ) {
				$asset_url_map[ $asset_path ] = $asset_url;
			}
		}

		return $asset_url_map;
	}

	/**
	 * Copy one project asset into uploads and return the public URL.
	 *
	 * @since  1.0.0
	 * @param  string $path       Project asset path.
	 * @param  array  $project    Project manifest.
	 * @param  string $import_key Import directory key.
	 * @return string
	 */
	private function write_project_asset_to_uploads( $path, $project, $import_key ) {
		$content = $this->get_project_file_content( $project, $path );

		if ( ! is_string( $content ) || '' === $content ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return '';
		}

		$relative_path = $this->build_project_import_upload_relative_path( $path );
		if ( '' === $relative_path ) {
			return '';
		}

		$base_dir = trailingslashit( $upload_dir['basedir'] ) . AMB_UPLOAD_DIR . '/imports/' . sanitize_file_name( $import_key );
		$base_url = trailingslashit( $upload_dir['baseurl'] ) . AMB_UPLOAD_DIR . '/imports/' . sanitize_file_name( $import_key );
		$target_path = trailingslashit( $base_dir ) . $relative_path;
		$target_dir = dirname( $target_path );

		if ( ! file_exists( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}

		if ( ! file_exists( $base_dir ) ) {
			wp_mkdir_p( $base_dir );
		}

		$existing_content = file_exists( $target_path )
			? file_get_contents( $target_path ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			: false;

		if ( ! file_exists( $target_path ) || $existing_content !== $content ) {
			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if ( $wp_filesystem ) {
				$written = $wp_filesystem->put_contents( $target_path, $content, FS_CHMOD_FILE );
			} else {
				$written = false !== file_put_contents( $target_path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}

			if ( ! $written ) {
				return '';
			}
		}

		return trailingslashit( $base_url ) . str_replace( '\\', '/', $relative_path );
	}

	/**
	 * Build a safe uploads-relative path for an imported project asset.
	 *
	 * @since  1.0.0
	 * @param  string $path Project asset path.
	 * @return string
	 */
	private function build_project_import_upload_relative_path( $path ) {
		$path     = $this->normalize_project_path( $path );
		$segments = array_values( array_filter( explode( '/', $path ) ) );

		if ( empty( $segments ) ) {
			return '';
		}

		$filename  = array_pop( $segments );
		$directory = array();

		foreach ( $segments as $segment ) {
			$safe_segment = sanitize_file_name( $segment );
			$directory[]  = '' !== $safe_segment ? $safe_segment : 'asset-dir';
		}

		$extension      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$base_name      = pathinfo( $filename, PATHINFO_FILENAME );
		$safe_base_name = sanitize_file_name( $base_name );
		$safe_filename  = '' !== $safe_base_name ? $safe_base_name : 'asset';
		$safe_filename .= '-' . substr( md5( $path ), 0, 8 );

		if ( '' !== $extension ) {
			$safe_filename .= '.' . $extension;
		}

		if ( ! empty( $directory ) ) {
			return implode( '/', $directory ) . '/' . $safe_filename;
		}

		return $safe_filename;
	}

	/**
	 * Replace asset placeholders in a prepared project page.
	 *
	 * @since  1.0.0
	 * @param  array $prepared_page Prepared page payload.
	 * @param  array $asset_url_map Project asset URL map.
	 * @return array
	 */
	private function replace_project_asset_placeholders_in_page( $prepared_page, $asset_url_map ) {
		foreach ( array( 'renderedHtml', 'customCss', 'importedHeadMarkup', 'importedFooterMarkup' ) as $key ) {
			if ( isset( $prepared_page[ $key ] ) && is_string( $prepared_page[ $key ] ) ) {
				$prepared_page[ $key ] = $this->replace_project_asset_placeholders(
					$prepared_page[ $key ],
					$asset_url_map
				);
			}
		}

		return $prepared_page;
	}

	/**
	 * Replace project asset placeholders in a string with uploaded URLs.
	 *
	 * @since  1.0.0
	 * @param  string $content       Content string.
	 * @param  array  $asset_url_map Asset URL map.
	 * @return string
	 */
	private function replace_project_asset_placeholders( $content, $asset_url_map ) {
		return preg_replace_callback(
			'#amb-asset://([A-Za-z0-9%._\-]+)#',
			function( $matches ) use ( $asset_url_map ) {
				$path = $this->normalize_project_path( rawurldecode( $matches[1] ) );

				return isset( $asset_url_map[ $path ] ) ? $asset_url_map[ $path ] : '#';
			},
			(string) $content
		);
	}

	/**
	 * Get project file content by normalized path.
	 *
	 * @since  1.0.0
	 * @param  array  $project Project manifest.
	 * @param  string $path    File path.
	 * @return string|null
	 */
	private function get_project_file_content( $project, $path ) {
		$path = $this->normalize_project_path( $path );
		$cache_key = md5( (string) ( isset( $project['zip_path'] ) ? $project['zip_path'] : '' ) . '|' . $path );

		if ( array_key_exists( $cache_key, $this->project_file_content_cache ) ) {
			return $this->project_file_content_cache[ $cache_key ];
		}

		if ( ! isset( $project['files'][ $path ] ) || ! is_array( $project['files'][ $path ] ) ) {
			return null;
		}

		$file_record = $project['files'][ $path ];

		if ( isset( $file_record['content'] ) && is_string( $file_record['content'] ) ) {
			$this->project_file_content_cache[ $cache_key ] = $file_record['content'];
			return $this->project_file_content_cache[ $cache_key ];
		}

		if ( empty( $project['zip_path'] ) || ! is_string( $project['zip_path'] ) ) {
			return null;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $project['zip_path'] ) ) {
			return null;
		}

		$content = false;

		if ( isset( $file_record['index'] ) ) {
			$content = $zip->getFromIndex( (int) $file_record['index'] );
		}

		if ( false === $content ) {
			$content = $zip->getFromName( $path );
		}

		$zip->close();

		$this->project_file_content_cache[ $cache_key ] = is_string( $content ) ? $content : null;

		return $this->project_file_content_cache[ $cache_key ];
	}

	/**
	 * Replace internal placeholder URLs with actual permalinks.
	 *
	 * @since  1.0.0
	 * @param  string $content       Content string.
	 * @param  array  $permalink_map Source file => permalink map.
	 * @return string
	 */
	private function replace_project_internal_placeholders( $content, $permalink_map ) {
		return preg_replace_callback(
			'#amb-internal://([^"\']+)#',
			function( $matches ) use ( $permalink_map ) {
				$path = rawurldecode( $matches[1] );
				return isset( $permalink_map[ $path ] ) ? $permalink_map[ $path ] : '#';
			},
			(string) $content
		);
	}

	/**
	 * Sanitize page settings for project imports.
	 *
	 * @since  1.0.0
	 * @param  array $settings Page settings.
	 * @return array
	 */
	private function sanitize_project_page_settings( $settings ) {
		return $this->sanitize_builder_page_settings_array( $settings );
	}

	/**
	 * Extract the inner HTML of an element.
	 *
	 * @since  1.0.0
	 * @param  DOMNode $node DOM node.
	 * @return string
	 */
	private function get_inner_html( $node ) {
		$html = '';

		if ( ! ( $node instanceof \DOMNode ) ) {
			return $html;
		}

		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}

		return $html;
	}

	/**
	 * Normalize project paths from ZIP entries and relative references.
	 *
	 * @since  1.0.0
	 * @param  string $path Raw path.
	 * @return string
	 */
	private function normalize_project_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '#/+#', '/', $path );
		$path = preg_replace( '#^\./#', '', $path );
		$path = preg_replace( '#\?.*$#', '', $path );
		$segments = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	/**
	 * Determine whether a project ZIP path should be excluded from import.
	 *
	 * Hidden VCS folders, macOS metadata, and dependency caches are not part of
	 * the website itself and can crowd out real HTML/CSS files in large archives.
	 *
	 * @since  1.0.0
	 * @param  string $path Normalized project path.
	 * @return bool
	 */
	private function should_skip_project_path( $path ) {
		$path = $this->normalize_project_path( $path );

		if ( '' === $path || str_starts_with( $path, '__MACOSX/' ) ) {
			return true;
		}

		$segments = explode( '/', $path );
		$ignored_directories = array(
			'.git',
			'.github',
			'.svn',
			'.hg',
			'node_modules',
		);

		foreach ( $segments as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			if ( in_array( $segment, $ignored_directories, true ) ) {
				return true;
			}

			if ( '.' === $segment[0] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect a common root folder inside a ZIP project.
	 *
	 * @since  1.0.0
	 * @param  array $paths Normalized project file paths.
	 * @return string
	 */
	private function detect_project_root_prefix( $paths ) {
		$first_segments = array();

		foreach ( $paths as $path ) {
			$path = $this->normalize_project_path( $path );
			if ( '' === $path || false === strpos( $path, '/' ) ) {
				return '';
			}

			$segments         = explode( '/', $path );
			$first_segments[] = $segments[0];
		}

		$unique_segments = array_values( array_unique( array_filter( $first_segments ) ) );

		return 1 === count( $unique_segments ) ? (string) $unique_segments[0] : '';
	}

	/**
	 * Build candidate project paths for a local reference.
	 *
	 * @since  1.0.0
	 * @param  string $value       Raw reference value.
	 * @param  string $source_file Current source file path.
	 * @param  array  $project     Project manifest.
	 * @return array
	 */
	private function get_project_reference_candidates( $value, $source_file, $project ) {
		$value          = (string) $value;
		$separator_pos  = strcspn( $value, '?#' );
		$value          = trim( substr( $value, 0, $separator_pos ) );
		$root_prefix    = isset( $project['root_prefix'] ) ? (string) $project['root_prefix'] : '';
		$base_directory = dirname( $source_file );
		$candidates     = array();

		if ( '' === $value ) {
			return $candidates;
		}

		if ( str_starts_with( $value, '/' ) ) {
			$trimmed = ltrim( $value, '/' );

			$candidates[] = $this->normalize_project_path( $trimmed );

			if ( '' !== $root_prefix ) {
				$candidates[] = $this->normalize_project_path( $root_prefix . '/' . $trimmed );
			}
		} else {
			$candidates[] = $this->normalize_project_path(
				'.' === $base_directory ? $value : $base_directory . '/' . $value
			);
			$candidates[] = $this->normalize_project_path( $value );

			if ( '' !== $root_prefix ) {
				$candidates[] = $this->normalize_project_path( $root_prefix . '/' . ltrim( $value, '/' ) );
			}
		}

		return array_values( array_unique( array_filter( $candidates ) ) );
	}

	/**
	 * Extract a compact attribute map from an imported HTML or BODY element.
	 *
	 * @since  1.0.0
	 * @param  DOMElement|null $element Source element.
	 * @return array
	 */
	private function extract_project_document_attributes( $element ) {
		if ( ! ( $element instanceof \DOMElement ) || ! $element->hasAttributes() ) {
			return array();
		}

		$attributes = array();

		foreach ( $element->attributes as $attribute ) {
			if ( ! ( $attribute instanceof \DOMAttr ) ) {
				continue;
			}

			$name  = strtolower( $attribute->name );
			$value = trim( (string) $attribute->value );

			if ( '' === $name || '' === $value ) {
				continue;
			}

			$attributes[ $name ] = $value;
		}

		return $attributes;
	}

	/**
	 * Guess a MIME type for project assets.
	 *
	 * @since  1.0.0
	 * @param  string $path File path.
	 * @return string
	 */
	private function guess_project_file_mime( $path ) {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$map = array(
			'png'   => 'image/png',
			'jpg'   => 'image/jpeg',
			'jpeg'  => 'image/jpeg',
			'gif'   => 'image/gif',
			'webp'  => 'image/webp',
			'svg'   => 'image/svg+xml',
			'avif'  => 'image/avif',
			'woff'  => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
			'mp4'   => 'video/mp4',
			'webm'  => 'video/webm',
			'mp3'   => 'audio/mpeg',
			'css'   => 'text/css',
			'js'    => 'text/javascript',
		);

		return isset( $map[ $extension ] ) ? $map[ $extension ] : 'application/octet-stream';
	}

	/**
	 * Generate a unique section ID for imported project content.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	private function generate_project_section_id() {
		return 'amb-import-' . wp_generate_uuid4();
	}

	/**
	 * Extract document title from raw HTML.
	 *
	 * @since  1.0.0
	 * @param  string $html Raw HTML.
	 * @return string
	 */
	private function extract_title_from_html_string( $html ) {
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/si', (string) $html, $matches ) ) {
			return sanitize_text_field( trim( wp_strip_all_tags( $matches[1] ) ) );
		}

		if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/si', (string) $html, $matches ) ) {
			return sanitize_text_field( trim( wp_strip_all_tags( $matches[1] ) ) );
		}

		return '';
	}

	/**
	 * Extract the first headline from HTML for AI analysis.
	 *
	 * @since  1.0.0
	 * @param  string $html Raw HTML.
	 * @return string
	 */
	private function extract_headline_from_html_string( $html ) {
		if ( preg_match( '/<(h1|h2)[^>]*>(.*?)<\/\1>/si', (string) $html, $matches ) ) {
			return sanitize_text_field( trim( wp_strip_all_tags( $matches[2] ) ) );
		}

		return '';
	}

	/**
	 * Build the system prompt for page generation.
	 *
	 * @since  1.0.0
	 * @return string System prompt.
	 */
	private function build_generation_system_prompt() {
		$prompt = 'You are an expert web page builder AI. Generate complete, production-ready HTML pages based on user instructions. ';
		$prompt .= 'Use modern, clean design with responsive layouts. ';
		$prompt .= 'Use semantic HTML5 elements. ';
		$prompt .= 'Every section should have a data-amb-section attribute for identification. ';
		$prompt .= 'Make the design polished and professional. ';

		// Style instructions — critical for persistence.
		$prompt .= "\n\nSTYLE RULES (IMPORTANT):\n";
		$prompt .= '- Place ALL CSS in a single <style> block at the very top of your HTML output. ';
		$prompt .= '- Use class names for styling (e.g., .hero-section, .feature-card). ';
		$prompt .= '- Include responsive @media queries inside the <style> block. ';
		$prompt .= '- For hover effects, transitions, and pseudo-elements, use the <style> block. ';
		$prompt .= '- Add Google Fonts @import at the top of your <style> block if you need custom fonts. ';
		$prompt .= '- Do NOT use inline style attributes on HTML elements. ';
		$prompt .= '- The <style> block will be automatically extracted and stored separately. ';

		$prompt .= "\n\nIMPORTANT: Return your response as a JSON object with two keys:\n";
		$prompt .= '- "title": A short, descriptive page title (3-8 words, suitable as a WordPress page title).';
		$prompt .= "\n";
		$prompt .= '- "html": The complete HTML content of the page (no doctype, no head/body tags — just the <style> block followed by the page content).';
		$prompt .= "\n\nExample format:\n";
		$prompt .= '{"title": "Modern SaaS Landing Page", "html": "<style>.hero{background:#f0f9ff;padding:80px 0;}.hero h1{font-size:3rem;}</style><section class=\"hero\" data-amb-section=\"hero\"><div class=\"container\"><h1>Welcome</h1></div></section>"}';
		$prompt .= "\n\nReturn ONLY the JSON object. No markdown fences, no explanations.";

		return $prompt;
	}

	/**
	 * Build the system prompt for page refinement.
	 *
	 * @since  1.0.0
	 * @return string System prompt.
	 */
	private function build_refinement_system_prompt() {
		return 'You are an expert web page builder AI. The user will provide existing HTML (with a <style> block containing CSS) and request modifications. '
			. 'Apply the requested changes while preserving the overall structure and style of the page. '
			. 'If a specific section is selected, modify only that section. '
			. 'Always return the complete <style> block at the top, followed by all the HTML content. '
			. 'If you modify styles, update them in the <style> block — do not use inline style attributes. '
			. 'Return the complete modified HTML (with <style> block). Return ONLY the HTML content, no explanations or markdown.';
	}

	/**
	 * Build the prompt used for migrating an existing WordPress page.
	 *
	 * @since  1.0.0
	 * @param  WP_Post $post Source page.
	 * @return string
	 */
	private function build_migration_prompt( $post ) {
		$rendered_content = apply_filters( 'the_content', $post->post_content );
		$rendered_content = wp_kses_post( $rendered_content );
		$plain_text       = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $rendered_content ) ) );

		$prompt  = 'Recreate the following existing WordPress page as a polished AM Builder page. ';
		$prompt .= 'Preserve the page intent, factual copy, headings, CTA meaning, and important links, but improve the layout, hierarchy, spacing, and visual design. ';
		$prompt .= 'Do not invent new business claims, product details, or testimonials that are not supported by the source content. ';
		$prompt .= 'If the source content is sparse, keep it concise instead of padding it with fake information. ';
		$prompt .= "\n\nSource page title:\n" . $post->post_title;
		$prompt .= "\n\nSource page rendered content:\n" . $rendered_content;

		if ( ! empty( $plain_text ) ) {
			$prompt .= "\n\nSource page text summary:\n" . $plain_text;
		}

		return $prompt;
	}

	/**
	 * Parse an AI page-generation response into title and HTML.
	 *
	 * @since  1.0.0
	 * @param  string $content Raw AI response content.
	 * @return array{title:string,html:string}
	 */
	private function parse_generated_page_response( $content ) {
		$title   = '';
		$html    = $content;
		$trimmed = trim( $content );

		if ( preg_match( '/^```(?:json)?\s*\n?(.*?)\n?```$/s', $trimmed, $fence_match ) ) {
			$trimmed = trim( $fence_match[1] );
		}

		if ( '{' === substr( $trimmed, 0, 1 ) ) {
			$parsed = json_decode( $trimmed, true );
			if ( is_array( $parsed ) ) {
				if ( isset( $parsed['title'] ) ) {
					$title = sanitize_text_field( $parsed['title'] );
				}

				if ( isset( $parsed['html'] ) ) {
					$html = $parsed['html'];
				}
			}
		}

		return array(
			'title' => $title,
			'html'  => $html,
		);
	}

	/**
	 * Call the AI API provider.
	 *
	 * @since  1.0.0
	 * @param  string $system_prompt System/context prompt.
	 * @param  string $user_prompt   User's message/prompt.
	 * @return array|WP_Error Response data or error.
	 */
	private function call_ai_api( $system_prompt, $user_prompt ) {
		$provider = AMB_Antimanual_Ai_Provider::get_provider();
		$model    = AMB_Antimanual_Ai_Provider::get_model( $provider );
		$api_key  = AMB_Antimanual_Ai_Provider::get_api_key( $provider );

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'ai_not_configured',
				AMB_Antimanual_Ai_Provider::get_status_message(),
				array( 'status' => 400 )
			);
		}

		switch ( $provider ) {
			case 'openai':
				return $this->call_openai( $api_key, $model, $system_prompt, $user_prompt );
			case 'gemini':
				return $this->call_gemini( $api_key, $model, $system_prompt, $user_prompt );
			default:
				return new \WP_Error(
					'invalid_provider',
					/* translators: %s: Provider name. */
					sprintf( __( 'Unknown AI provider: %s', 'antimanual-builder' ), $provider ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Call OpenAI API.
	 *
	 * @since  1.0.0
	 * @param  string $api_key       API key.
	 * @param  string $model         Model name.
	 * @param  string $system_prompt System prompt.
	 * @param  string $user_prompt   User prompt.
	 * @return array|WP_Error
	 */
	private function call_openai( $api_key, $model, $system_prompt, $user_prompt ) {
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout'   => 180,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'messages'    => array(
							array( 'role' => 'system', 'content' => $system_prompt ),
							array( 'role' => 'user', 'content' => $user_prompt ),
						),
						'max_tokens'  => 8000,
						'temperature' => 0.7,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			return new \WP_Error(
				'ai_error',
				isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'AI API error.', 'antimanual-builder' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'content' => isset( $body['choices'][0]['message']['content'] ) ? $body['choices'][0]['message']['content'] : '',
			'usage'   => isset( $body['usage'] ) ? $body['usage'] : array(),
		);
	}

	/**
	 * Call Anthropic (Claude) API.
	 *
	 * @since  1.0.0
	 * @param  string $api_key       API key.
	 * @param  string $model         Model name.
	 * @param  string $system_prompt System prompt.
	 * @param  string $user_prompt   User prompt.
	 * @return array|WP_Error
	 */
	private function call_anthropic( $api_key, $model, $system_prompt, $user_prompt ) {
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout'   => 180,
				'headers'   => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $model,
						'max_tokens' => 8000,
						'system'     => $system_prompt,
						'messages'   => array(
							array( 'role' => 'user', 'content' => $user_prompt ),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			return new \WP_Error(
				'ai_error',
				isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'AI API error.', 'antimanual-builder' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'content' => isset( $body['content'][0]['text'] ) ? $body['content'][0]['text'] : '',
			'usage'   => isset( $body['usage'] ) ? $body['usage'] : array(),
		);
	}

	/**
	 * Call Google Gemini API.
	 *
	 * @since  1.0.0
	 * @param  string $api_key       API key.
	 * @param  string $model         Model name.
	 * @param  string $system_prompt System prompt.
	 * @param  string $user_prompt   User prompt.
	 * @return array|WP_Error
	 */
	private function call_gemini( $api_key, $model, $system_prompt, $user_prompt ) {
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;

		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 180,
				'headers'   => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'system_instruction' => array(
							'parts' => array(
								array( 'text' => $system_prompt ),
							),
						),
						'contents'           => array(
							array(
								'parts' => array(
									array( 'text' => $user_prompt ),
								),
							),
						),
						'generationConfig'   => array(
							'maxOutputTokens' => 8000,
							'temperature'     => 0.7,
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			return new \WP_Error(
				'ai_error',
				isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'AI API error.', 'antimanual-builder' ),
				array( 'status' => 500 )
			);
		}

		$text = '';
		if ( isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$text = $body['candidates'][0]['content']['parts'][0]['text'];
		}

		return array(
			'content' => $text,
			'usage'   => isset( $body['usageMetadata'] ) ? $body['usageMetadata'] : array(),
		);
	}

	/**
	 * Parse HTML into a block structure.
	 *
	 * Basic HTML parser that converts HTML elements into
	 * the builder's block JSON format.
	 *
	 * @since  1.0.0
	 * @param  string $html HTML string.
	 * @return array Array of block definitions.
	 */
	private function parse_html_to_blocks( $html ) {
		// This is a simplified parser. A full implementation would use
		// DOMDocument for robust HTML parsing.
		$blocks = array();

		$blocks[] = array(
			'id'       => wp_generate_uuid4(),
			'type'     => 'html',
			'content'  => array(
				'html' => $html,
			),
			'styles'   => array(),
			'children' => array(),
		);

		return $blocks;
	}
}
