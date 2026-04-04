<?php
/**
 * Builder Component custom post type.
 *
 * Registers the `amb_component` post type for storing
 * reusable building blocks (sections, templates, widgets).
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_Post_Type_Builder_Component
 *
 * Registers and manages the builder component post type.
 *
 * @since 1.0.0
 */
class AMB_Post_Type_Builder_Component {

	/**
	 * Post type slug.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const POST_TYPE = 'amb_component';

	/**
	 * Constructor. Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'init', array( $this, 'register_taxonomy' ) );
	}

	/**
	 * Register the custom post type.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register() {
		$labels = array(
			'name'               => _x( 'Components', 'Post type general name', 'antimanual-builder' ),
			'singular_name'      => _x( 'Component', 'Post type singular name', 'antimanual-builder' ),
			'menu_name'          => _x( 'Components', 'Admin menu text', 'antimanual-builder' ),
			'add_new'            => __( 'Add New', 'antimanual-builder' ),
			'add_new_item'       => __( 'Add New Component', 'antimanual-builder' ),
			'edit_item'          => __( 'Edit Component', 'antimanual-builder' ),
			'new_item'           => __( 'New Component', 'antimanual-builder' ),
			'view_item'          => __( 'View Component', 'antimanual-builder' ),
			'search_items'       => __( 'Search Components', 'antimanual-builder' ),
			'not_found'          => __( 'No components found', 'antimanual-builder' ),
			'not_found_in_trash' => __( 'No components found in Trash', 'antimanual-builder' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_rest'        => true,
			'rest_base'           => 'amb-components',
			'query_var'           => false,
			'capability_type'     => 'page',
			'has_archive'         => false,
			'hierarchical'        => false,
			'supports'            => array( 'title', 'author', 'thumbnail', 'revisions', 'custom-fields' ),
			'exclude_from_search' => true,
		);

		register_post_type( self::POST_TYPE, $args );

		// Register meta fields.
		$this->register_meta();
	}

	/**
	 * Register component category taxonomy.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_taxonomy() {
		$labels = array(
			'name'          => _x( 'Component Categories', 'Taxonomy general name', 'antimanual-builder' ),
			'singular_name' => _x( 'Component Category', 'Taxonomy singular name', 'antimanual-builder' ),
		);

		register_taxonomy(
			'amb_component_cat',
			self::POST_TYPE,
			array(
				'labels'            => $labels,
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => false,
				'show_in_rest'      => true,
				'show_admin_column' => false,
			)
		);
	}

	/**
	 * Register post meta fields.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function register_meta() {
		// Block data as JSON.
		register_post_meta(
			self::POST_TYPE,
			'_amb_blocks',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => function () {
					return current_user_can( 'edit_pages' );
				},
			)
		);

		// Rendered HTML preview.
		register_post_meta(
			self::POST_TYPE,
			'_amb_rendered_html',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => function( $html ) {
					return current_user_can( 'unfiltered_html' ) ? $html : wp_kses_post( $html );
				},
				'auth_callback'     => function () {
					return current_user_can( 'edit_pages' );
				},
			)
		);

		// Component type (section, row, widget, template).
		register_post_meta(
			self::POST_TYPE,
			'_amb_component_type',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => function () {
					return current_user_can( 'edit_pages' );
				},
			)
		);
	}
}
