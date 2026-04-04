<?php
/**
 * Pages REST API controller.
 *
 * Handles CRUD operations for builder pages via REST API.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_API_Pages_Api
 *
 * REST API endpoints for builder page management.
 *
 * @since 1.0.0
 */
class AMB_API_Pages_Api {

	use AMB_API_Builder_Meta_Trait;

	/**
	 * Native post type used by the builder pages endpoint.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const POST_TYPE = 'page';

	/**
	 * Option key storing the list cache version.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const LIST_CACHE_VERSION_OPTION = 'amb_pages_list_cache_version';

	/**
	 * Lifetime for cached list responses in seconds.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const LIST_CACHE_TTL = 60;

	/**
	 * REST namespace.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	private $namespace = 'amb/v1';

	/**
	 * Register routes.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_routes() {
		// List all pages.
		register_rest_route(
			$this->namespace,
			'/pages',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_pages' ),
					'permission_callback' => array( $this, 'can_edit_pages' ),
					'args'                => array(
						'per_page' => array(
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'status'   => array(
							'default'           => 'any',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'search'   => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'orderby'  => array(
							'default'           => 'modified',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'order'    => array(
							'default'           => 'DESC',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'context'  => array(
							'default'           => 'list',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_page' ),
					'permission_callback' => array( $this, 'can_edit_pages' ),
				),
			)
		);

		// Single page operations.
		register_rest_route(
			$this->namespace,
			'/pages/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_page' ),
					'permission_callback' => array( $this, 'can_edit_pages' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_page' ),
					'permission_callback' => array( $this, 'can_edit_pages' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_page' ),
					'permission_callback' => array( $this, 'can_edit_pages' ),
				),
			)
		);

		// Duplicate a page.
		register_rest_route(
			$this->namespace,
			'/pages/(?P<id>\d+)/duplicate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate_page' ),
				'permission_callback' => array( $this, 'can_edit_pages' ),
			)
		);
	}

	/**
	 * Permission check: can the current user edit pages?
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	public function can_edit_pages() {
		return current_user_can( 'edit_pages' );
	}

	/**
	 * Get a list of builder pages.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_pages( $request ) {
		$cached_response = $this->get_cached_list_response( $request );
		if ( null !== $cached_response ) {
			return new \WP_REST_Response( $cached_response, 200 );
		}

		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$context  = $this->normalize_response_context( $request->get_param( 'context' ) );

		$args = array(
			'post_type'      => self::POST_TYPE,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_status'    => $request->get_param( 'status' ),
			'orderby'        => $this->normalize_list_orderby( $request->get_param( 'orderby' ) ),
			'order'          => $this->normalize_list_order( $request->get_param( 'order' ) ),
			'meta_key'       => '_amb_builder_enabled',
			'meta_value'     => '1',
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'cache_results'          => true,
		);

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$query = new \WP_Query( $args );
		$pages = array();

		foreach ( $query->posts as $post ) {
			$pages[] = $this->format_page( $post, $context );
		}

		$response = array(
			'pages'      => $pages,
			'total'      => (int) $query->found_posts,
			'totalPages' => (int) $query->max_num_pages,
		);

		$this->set_cached_list_response( $request, $response );

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Get a single builder page.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_page( $request ) {
		$post = get_post( $request['id'] );

		$supported_types = array( self::POST_TYPE );

		if ( ! $post || ! in_array( $post->post_type, $supported_types, true ) ) {
			return new \WP_Error(
				'not_found',
				__( 'Page not found.', 'antimanual-builder' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to edit this page.', 'antimanual-builder' ),
				array( 'status' => 403 )
			);
		}

		return new \WP_REST_Response( $this->format_page( $post ), 200 );
	}

	/**
	 * Create a new builder page.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_page( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$params = is_array( $params ) ? $params : array();

		// Extract <style> blocks from rendered HTML and merge into customCss.
		if ( isset( $params['renderedHtml'] ) ) {
			$extracted                = $this->extract_styles_from_html( $params['renderedHtml'] );
			$params['renderedHtml']   = $extracted['html'];
			$existing_css             = isset( $params['customCss'] ) ? $params['customCss'] : '';
			$params['customCss']      = $this->merge_css( $existing_css, $extracted['css'] );
		}

		$params['renderedHtml'] = isset( $params['renderedHtml'] )
			? $this->sanitize_builder_html_value( $params['renderedHtml'] )
			: '';
		$params['customCss']    = isset( $params['customCss'] )
			? $this->sanitize_builder_css_value( $params['customCss'] )
			: '';

		$post_data = array(
			'post_type'    => self::POST_TYPE,
			'post_title'   => isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : __( 'Untitled Page', 'antimanual-builder' ),
			'post_status'  => $this->normalize_builder_post_status( isset( $params['status'] ) ? $params['status'] : 'draft' ),
			'post_author'  => get_current_user_id(),
			'post_content' => $params['renderedHtml'],
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Save meta data.
		$this->save_page_meta( $post_id, $params );

		// Generate versioned CSS/JS assets immediately for new pages.
		do_action( 'amb_page_saved', $post_id, get_post( $post_id ) );
		$this->bump_list_cache_version();

		$post = get_post( $post_id );
		return new \WP_REST_Response( $this->format_page( $post ), 201 );
	}

	/**
	 * Update an existing builder page.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_page( $request ) {
		$post = get_post( $request['id'] );

		$supported_types = array( self::POST_TYPE );

		if ( ! $post || ! in_array( $post->post_type, $supported_types, true ) ) {
			return new \WP_Error(
				'not_found',
				__( 'Page not found.', 'antimanual-builder' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to edit this page.', 'antimanual-builder' ),
				array( 'status' => 403 )
			);
		}

		$params    = $request->get_json_params();
		$params    = is_array( $params ) ? $params : $request->get_params();
		$params    = is_array( $params ) ? $params : array();

		// Extract <style> blocks from rendered HTML and merge into customCss.
		if ( isset( $params['renderedHtml'] ) ) {
			$extracted              = $this->extract_styles_from_html( $params['renderedHtml'] );
			$params['renderedHtml'] = $extracted['html'];
			$existing_css           = isset( $params['customCss'] ) ? $params['customCss'] : '';
			$params['customCss']    = $this->merge_css( $existing_css, $extracted['css'] );
		}

		if ( isset( $params['renderedHtml'] ) ) {
			$params['renderedHtml'] = $this->sanitize_builder_html_value( $params['renderedHtml'] );
		}

		if ( isset( $params['customCss'] ) ) {
			$params['customCss'] = $this->sanitize_builder_css_value( $params['customCss'] );
		}

		$post_data = array( 'ID' => $post->ID );

		if ( isset( $params['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $params['title'] );
		}

		if ( isset( $params['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $params['excerpt'] );
		}

		if ( isset( $params['status'] ) ) {
			$post_data['post_status'] = $this->normalize_builder_post_status( $params['status'], $post->post_status );
		}

		if ( isset( $params['renderedHtml'] ) ) {
			$post_data['post_content'] = $params['renderedHtml'];
		}

		$page_template = $this->resolve_page_template_for_save( $post, $params );
		if ( null !== $page_template ) {
			$post_data['page_template'] = $page_template;
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Save meta data.
		$this->save_page_meta( $post->ID, $params );

		// Trigger asset regeneration.
		do_action( 'amb_page_saved', $post->ID, get_post( $post->ID ) );
		$this->bump_list_cache_version();

		$post = get_post( $post->ID );
		return new \WP_REST_Response( $this->format_page( $post ), 200 );
	}

	/**
	 * Delete a builder page.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_page( $request ) {
		$post = get_post( $request['id'] );

		$supported_types = array( self::POST_TYPE );

		if ( ! $post || ! in_array( $post->post_type, $supported_types, true ) ) {
			return new \WP_Error(
				'not_found',
				__( 'Page not found.', 'antimanual-builder' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to delete this page.', 'antimanual-builder' ),
				array( 'status' => 403 )
			);
		}

		$result = wp_trash_post( $post->ID );

		if ( ! $result ) {
			return new \WP_Error(
				'delete_failed',
				__( 'Failed to delete page.', 'antimanual-builder' ),
				array( 'status' => 500 )
			);
		}

		$this->bump_list_cache_version();

		return new \WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Duplicate a builder page.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate_page( $request ) {
		$source = get_post( $request['id'] );

		$supported_types = array( self::POST_TYPE );

		if ( ! $source || ! in_array( $source->post_type, $supported_types, true ) ) {
			return new \WP_Error(
				'not_found',
				__( 'Source page not found.', 'antimanual-builder' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'read_post', $source->ID ) ) {
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to duplicate this page.', 'antimanual-builder' ),
				array( 'status' => 403 )
			);
		}

		$new_post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				/* translators: %s: Original page title. */
				'post_title'  => sprintf( __( '%s (Copy)', 'antimanual-builder' ), $source->post_title ),
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		// Copy all meta.
		$meta_keys = array( '_amb_builder_enabled', '_amb_blocks', '_amb_rendered_html', '_amb_custom_css', '_amb_page_settings' );
		foreach ( $meta_keys as $key ) {
			$value = get_post_meta( $source->ID, $key, true );
			if ( $value ) {
				update_post_meta( $new_post_id, $key, $value );
			}
		}

		$this->bump_list_cache_version();

		$post = get_post( $new_post_id );
		return new \WP_REST_Response( $this->format_page( $post ), 201 );
	}

	/**
	 * Save page meta fields.
	 *
	 * @since  1.0.0
	 * @param  int   $post_id Post ID.
	 * @param  array $params  Request parameters.
	 * @return void
	 */
	private function save_page_meta( $post_id, $params ) {
		// Save generic builder meta (blocks, renderedHtml, customCss).
		$this->save_builder_meta( $post_id, $params );

		$meta_map = array(
			'pageSettings' => '_amb_page_settings',
		);

		foreach ( $meta_map as $param_key => $meta_key ) {
			if ( isset( $params[ $param_key ] ) ) {
				$value = $params[ $param_key ];

				if ( 'pageSettings' === $param_key ) {
					$value = $this->sanitize_page_settings_value( $value );
				}

				// JSON-encode arrays/objects.
				if ( is_array( $value ) || is_object( $value ) ) {
					$value = wp_json_encode( $value );
				}

				// wp_slash prevents update_post_meta's internal wp_unslash from corrupting
				// JSON strings that contain escaped double-quotes (e.g. HTML attributes).
				update_post_meta( $post_id, $meta_key, is_string( $value ) ? wp_slash( $value ) : $value );
			}
		}
	}

	/**
	 * Sanitize page settings while preserving import asset markup for trusted users.
	 *
	 * @since  1.0.0
	 * @param  mixed $value Raw page settings value.
	 * @return mixed
	 */
	private function sanitize_page_settings_value( $value ) {
		return $this->sanitize_builder_page_settings_meta_value( $value );
	}

	/**
	 * Resolve the page template that should be used for a save operation.
	 *
	 * Existing native pages can hold a template slug that is no longer registered
	 * by the active theme. Explicitly normalizing it prevents wp_update_post()
	 * from rejecting otherwise valid content updates with `invalid_page_template`.
	 *
	 * @since  1.0.0
	 * @param  WP_Post $post   Post being saved.
	 * @param  array   $params Request parameters.
	 * @return string|null Template slug or null when templates do not apply.
	 */
	private function resolve_page_template_for_save( $post, $params ) {
		if ( ! ( $post instanceof \WP_Post ) || ! $this->post_type_supports_page_templates( $post->post_type ) ) {
			return null;
		}

		$template = isset( $params['pageTemplate'] )
			? sanitize_text_field( $params['pageTemplate'] )
			: get_page_template_slug( $post->ID );

		if ( empty( $template ) || 'default' === $template ) {
			return 'default';
		}

		if ( $this->is_valid_page_template( $template, $post ) ) {
			return $template;
		}

		return 'default';
	}

	/**
	 * Determine whether a post type can use theme page templates.
	 *
	 * @since  1.0.0
	 * @param  string $post_type Post type slug.
	 * @return bool
	 */
	private function post_type_supports_page_templates( $post_type ) {
		return 'page' === $post_type || post_type_supports( $post_type, 'page-attributes' );
	}

	/**
	 * Check whether the provided page template slug is currently registered.
	 *
	 * @since  1.0.0
	 * @param  string  $template Template slug.
	 * @param  WP_Post $post     Post being saved.
	 * @return bool
	 */
	private function is_valid_page_template( $template, $post ) {
		$templates = wp_get_theme()->get_page_templates( $post, $post->post_type );

		return isset( $templates[ $template ] ) || in_array( $template, $templates, true );
	}

	/**
	 * Format a page post into an API response array.
	 *
	 * @since  1.0.0
	 * @param  WP_Post $post Post object.
	 * @return array Formatted page data.
	 */
	private function format_page( $post, $context = 'detail' ) {
		if ( 'list' === $context ) {
			return array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'status'    => $post->post_status,
				'date'      => $post->post_date_gmt,
				'modified'  => $post->post_modified_gmt,
				'permalink' => get_permalink( $post->ID ),
				'editUrl'   => admin_url( 'admin.php?page=amb-editor&post_id=' . $post->ID ),
			);
		}

		return array(
			'id'              => $post->ID,
			'title'           => $post->post_title,
			'slug'            => $post->post_name,
			'status'          => $post->post_status,
			'excerpt'         => $post->post_excerpt,
			'author'          => (int) $post->post_author,
			'authorName'      => get_the_author_meta( 'display_name', $post->post_author ),
			'date'            => $post->post_date,
			'modified'        => $post->post_modified,
			'permalink'       => get_permalink( $post->ID ),
			'editUrl'         => admin_url( 'admin.php?page=amb-editor&post_id=' . $post->ID ),
			'blocks'          => get_post_meta( $post->ID, '_amb_blocks', true ),
			'renderedHtml'    => get_post_meta( $post->ID, '_amb_rendered_html', true ),
			'customCss'       => get_post_meta( $post->ID, '_amb_custom_css', true ),
			'pageSettings'    => get_post_meta( $post->ID, '_amb_page_settings', true ),
			'thumbnail'       => get_the_post_thumbnail_url( $post->ID, 'medium' ),
		);
	}

	/**
	 * Normalize the list response context.
	 *
	 * @since  1.0.0
	 * @param  string $context Requested response context.
	 * @return string
	 */
	private function normalize_response_context( $context ) {
		return 'detail' === $context ? 'detail' : 'list';
	}

	/**
	 * Normalize list orderby values for WP_Query.
	 *
	 * @since  1.0.0
	 * @param  string $orderby Requested orderby value.
	 * @return string
	 */
	private function normalize_list_orderby( $orderby ) {
		$orderby = sanitize_key( (string) $orderby );

		if ( 'title' === $orderby ) {
			return 'title';
		}

		if ( 'date' === $orderby ) {
			return 'date';
		}

		return 'modified';
	}

	/**
	 * Normalize list sort direction.
	 *
	 * @since  1.0.0
	 * @param  string $order Requested order direction.
	 * @return string
	 */
	private function normalize_list_order( $order ) {
		return 'ASC' === strtoupper( (string) $order ) ? 'ASC' : 'DESC';
	}

	/**
	 * Build the transient key for a cached list response.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return string
	 */
	private function get_list_cache_key( $request ) {
		$version = (int) get_option( self::LIST_CACHE_VERSION_OPTION, 1 );

		return 'amb_pages_list_' . $version . '_' . md5(
			wp_json_encode(
				array(
					'per_page' => (int) $request->get_param( 'per_page' ),
					'page'     => (int) $request->get_param( 'page' ),
					'status'   => (string) $request->get_param( 'status' ),
					'search'   => (string) $request->get_param( 'search' ),
					'orderby'  => $this->normalize_list_orderby( $request->get_param( 'orderby' ) ),
					'order'    => $this->normalize_list_order( $request->get_param( 'order' ) ),
					'context'  => $this->normalize_response_context( $request->get_param( 'context' ) ),
				)
			)
		);
	}

	/**
	 * Fetch a cached list response when available.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return array|null
	 */
	private function get_cached_list_response( $request ) {
		$cached = get_transient( $this->get_list_cache_key( $request ) );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Cache a list response for repeated admin requests.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request  Request object.
	 * @param  array           $response Response payload.
	 * @return void
	 */
	private function set_cached_list_response( $request, $response ) {
		set_transient( $this->get_list_cache_key( $request ), $response, self::LIST_CACHE_TTL );
	}

	/**
	 * Invalidate cached list responses after writes.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function bump_list_cache_version() {
		update_option( self::LIST_CACHE_VERSION_OPTION, time(), false );
	}

	/**
	 * Extract <style> blocks from HTML content.
	 *
	 * Separates embedded CSS from the HTML body so styles
	 * can be stored in _amb_custom_css and written to the generated page CSS asset.
	 * Note: We intentionally do NOT strip <script> tags so that AI-generated
	 * CDN scripts (e.g. FontAwesome) persist cleanly.
	 *
	 * @since  1.0.0
	 * @param  string $html HTML content that may contain <style> tags.
	 * @return array { 'html' => string, 'css' => string }
	 */
	private function extract_styles_from_html( $html ) {
		$css = '';

		if ( empty( $html ) ) {
			return array(
				'html' => '',
				'css'  => '',
			);
		}

		// Match all <style> blocks (with optional attributes).
		if ( preg_match_all( '/<style[^>]*>(.*?)<\/style>/si', $html, $matches ) ) {
			foreach ( $matches[1] as $style_content ) {
				$css .= trim( $style_content ) . "\n";
			}
			// Remove <style> blocks from the HTML.
			$html = preg_replace( '/<style[^>]*>.*?<\/style>/si', '', $html );
			$html = trim( $html );
		}

		return array(
			'html' => $html,
			'css'  => trim( $css ),
		);
	}

	/**
	 * Merge existing CSS with newly extracted CSS.
	 *
	 * Avoids duplicating CSS if the same styles are already present.
	 *
	 * @since  1.0.0
	 * @param  string $existing Existing custom CSS.
	 * @param  string $new      Newly extracted CSS.
	 * @return string Merged CSS.
	 */
	private function merge_css( $existing, $new ) {
		if ( empty( $new ) ) {
			return $existing;
		}
		if ( empty( $existing ) ) {
			return $new;
		}
		// Replace existing AI-generated CSS (between markers) with new CSS.
		$marker_start = '/* --- AI Generated Styles --- */';
		$marker_end   = '/* --- End AI Generated Styles --- */';

		if ( strpos( $existing, $marker_start ) !== false ) {
			$pattern  = '/' . preg_quote( $marker_start, '/' ) . '.*?' . preg_quote( $marker_end, '/' ) . '/s';
			$existing = preg_replace( $pattern, '', $existing );
			$existing = trim( $existing );
		}

		$merged = $marker_start . "\n" . $new . "\n" . $marker_end;
		if ( ! empty( $existing ) ) {
			$merged = $existing . "\n\n" . $merged;
		}

		return $merged;
	}
}
