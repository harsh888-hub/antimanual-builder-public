<?php
/**
 * Components REST API controller.
 *
 * Handles CRUD for reusable components (saved sections, templates, etc.).
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_API_Components_Api
 *
 * REST API endpoints for the component library.
 *
 * @since 1.0.0
 */
class AMB_API_Components_Api {

	use AMB_API_Builder_Meta_Trait;

	/**
	 * Option key storing the list cache version.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const LIST_CACHE_VERSION_OPTION = 'amb_components_list_cache_version';

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
		register_rest_route(
			$this->namespace,
			'/components',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_components' ),
					'permission_callback' => array( $this, 'can_edit_pages' ),
					'args'                => array(
						'type'     => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'category' => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'per_page'  => array(
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
						'page'      => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'status'    => array(
							'default'           => 'any',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'search'    => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'orderby'   => array(
							'default'           => 'modified',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'order'     => array(
							'default'           => 'DESC',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'context'   => array(
							'default'           => 'list',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_component' ),
					'permission_callback' => array( $this, 'can_edit_pages' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/components/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_component' ),
					'permission_callback' => array( $this, 'can_edit_pages' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_component' ),
					'permission_callback' => array( $this, 'can_edit_pages' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_component' ),
					'permission_callback' => array( $this, 'can_edit_pages' ),
				),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	public function can_edit_pages() {
		return current_user_can( 'edit_pages' );
	}

	/**
	 * Get components list.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_components( $request ) {
		$cached_response = $this->get_cached_list_response( $request );
		if ( null !== $cached_response ) {
			return new \WP_REST_Response( $cached_response, 200 );
		}

		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$context  = $this->normalize_response_context( $request->get_param( 'context' ) );

		$args = array(
			'post_type'      => AMB_Post_Type_Builder_Component::POST_TYPE,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_status'    => $request->get_param( 'status' ),
			'orderby'        => $this->normalize_list_orderby( $request->get_param( 'orderby' ) ),
			'order'          => $this->normalize_list_order( $request->get_param( 'order' ) ),
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'cache_results'          => true,
		);

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$type = $request->get_param( 'type' );
		if ( ! empty( $type ) ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_amb_component_type',
					'value' => sanitize_text_field( $type ),
				),
			);
		}

		$category = $request->get_param( 'category' );
		if ( ! empty( $category ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'amb_component_cat',
					'field'    => 'slug',
					'terms'    => sanitize_text_field( $category ),
				),
			);
		}

		$query      = new \WP_Query( $args );
		$components = array();

		foreach ( $query->posts as $post ) {
			$components[] = $this->format_component( $post, $context );
		}

		$response = array(
			'items'      => $components,
			'total'      => (int) $query->found_posts,
			'totalPages' => (int) $query->max_num_pages,
		);

		$this->set_cached_list_response( $request, $response );

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Get a single component.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_component( $request ) {
		$post = get_post( $request['id'] );

		if ( ! $post || AMB_Post_Type_Builder_Component::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'not_found', __( 'Component not found.', 'antimanual-builder' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to view this component.', 'antimanual-builder' ), array( 'status' => 403 ) );
		}

		return new \WP_REST_Response( $this->format_component( $post, 'detail' ), 200 );
	}

	/**
	 * Create a new component.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_component( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$params = is_array( $params ) ? $params : array();

		if ( isset( $params['renderedHtml'] ) ) {
			$extracted              = $this->extract_styles_from_html( $params['renderedHtml'] );
			$params['renderedHtml'] = $extracted['html'];
			$existing_css           = isset( $params['customCss'] ) ? $params['customCss'] : '';
			$params['customCss']    = $this->merge_css( $existing_css, $extracted['css'] );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => AMB_Post_Type_Builder_Component::POST_TYPE,
				'post_title'  => isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : __( 'Untitled Component', 'antimanual-builder' ),
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( isset( $params['componentType'] ) ) {
			update_post_meta( $post_id, '_amb_component_type', sanitize_text_field( $params['componentType'] ) );
		}

		$this->save_builder_meta( $post_id, $params );

		if ( isset( $params['category'] ) ) {
			wp_set_object_terms( $post_id, sanitize_text_field( $params['category'] ), 'amb_component_cat' );
		}

		$this->bump_list_cache_version();

		$post = get_post( $post_id );
		return new \WP_REST_Response( $this->format_component( $post, 'detail' ), 201 );
	}

	/**
	 * Update a component.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_component( $request ) {
		$post = get_post( $request['id'] );

		if ( ! $post || AMB_Post_Type_Builder_Component::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'not_found', __( 'Component not found.', 'antimanual-builder' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to edit this component.', 'antimanual-builder' ), array( 'status' => 403 ) );
		}

		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$params = is_array( $params ) ? $params : array();

		if ( isset( $params['renderedHtml'] ) ) {
			$extracted              = $this->extract_styles_from_html( $params['renderedHtml'] );
			$params['renderedHtml'] = $extracted['html'];
			$existing_css           = isset( $params['customCss'] ) ? $params['customCss'] : get_post_meta( $post->ID, '_amb_custom_css', true );
			$params['customCss']    = $this->merge_css( $existing_css, $extracted['css'] );
		}

		if ( isset( $params['title'] ) ) {
			$result = wp_update_post(
				array(
					'ID'         => $post->ID,
					'post_title' => sanitize_text_field( $params['title'] ),
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( isset( $params['componentType'] ) ) {
			update_post_meta( $post->ID, '_amb_component_type', sanitize_text_field( $params['componentType'] ) );
		}

		if ( array_key_exists( 'category', $params ) ) {
			if ( '' === trim( (string) $params['category'] ) ) {
				wp_set_object_terms( $post->ID, array(), 'amb_component_cat' );
			} else {
				wp_set_object_terms( $post->ID, sanitize_text_field( $params['category'] ), 'amb_component_cat' );
			}
		}

		$this->save_builder_meta( $post->ID, $params );

		$this->bump_list_cache_version();

		$post = get_post( $post->ID );
		return new \WP_REST_Response( $this->format_component( $post, 'detail' ), 200 );
	}

	/**
	 * Delete a component.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_component( $request ) {
		$post = get_post( $request['id'] );

		if ( ! $post || AMB_Post_Type_Builder_Component::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'not_found', __( 'Component not found.', 'antimanual-builder' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to delete this component.', 'antimanual-builder' ), array( 'status' => 403 ) );
		}

		wp_delete_post( $post->ID, true );
		$this->bump_list_cache_version();
		return new \WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Format a component post into an API response array.
	 *
	 * @since  1.0.0
	 * @param  WP_Post $post    Post object.
	 * @param  string  $context Requested response context.
	 * @return array
	 */
	private function format_component( $post, $context = 'detail' ) {
		$edit_url = admin_url(
			'admin.php?page=amb-editor&entity_type=' . rawurlencode( AMB_Post_Type_Builder_Component::POST_TYPE ) . '&post_id=' . $post->ID
		);

		if ( 'list' === $context ) {
			return array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'status'    => $post->post_status,
				'date'      => $post->post_date_gmt,
				'modified'  => $post->post_modified_gmt,
				'editUrl'   => $edit_url,
				'permalink' => null,
			);
		}

		return array(
			'id'            => $post->ID,
			'title'         => $post->post_title,
			'status'        => $post->post_status,
			'editUrl'       => $edit_url,
			'componentType' => get_post_meta( $post->ID, '_amb_component_type', true ),
			'blocks'        => get_post_meta( $post->ID, '_amb_blocks', true ),
			'renderedHtml'  => get_post_meta( $post->ID, '_amb_rendered_html', true ),
			'customCss'     => get_post_meta( $post->ID, '_amb_custom_css', true ),
			'thumbnail'     => get_the_post_thumbnail_url( $post->ID, 'medium' ),
			'date'          => $post->post_date,
			'modified'      => $post->post_modified,
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

		return 'amb_components_list_' . $version . '_' . md5(
			wp_json_encode(
				array(
					'type'     => (string) $request->get_param( 'type' ),
					'category' => (string) $request->get_param( 'category' ),
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
	 * Extract inline <style> blocks from an HTML fragment.
	 *
	 * @since 1.0.0
	 * @param string $html Raw component HTML.
	 * @return array{html:string,css:string}
	 */
	private function extract_styles_from_html( $html ) {
		$css = '';

		if ( empty( $html ) || ! is_string( $html ) ) {
			return array(
				'html' => '',
				'css'  => '',
			);
		}

		if ( preg_match_all( '/<style[^>]*>(.*?)<\/style>/si', $html, $matches ) ) {
			foreach ( $matches[1] as $style_content ) {
				$css .= trim( $style_content ) . "\n";
			}

			$html = preg_replace( '/<style[^>]*>.*?<\/style>/si', '', $html );
		}

		return array(
			'html' => trim( (string) $html ),
			'css'  => trim( $css ),
		);
	}

	/**
	 * Merge extracted CSS with any existing component CSS.
	 *
	 * @since 1.0.0
	 * @param mixed $existing_css Existing CSS string.
	 * @param mixed $extracted_css CSS extracted from HTML.
	 * @return string
	 */
	private function merge_css( $existing_css, $extracted_css ) {
		$existing_css  = is_string( $existing_css ) ? trim( $existing_css ) : '';
		$extracted_css = is_string( $extracted_css ) ? trim( $extracted_css ) : '';

		if ( '' === $existing_css ) {
			return $extracted_css;
		}

		if ( '' === $extracted_css ) {
			return $existing_css;
		}

		return $existing_css . "\n\n" . $extracted_css;
	}
}
