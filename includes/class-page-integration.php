<?php
/**
 * Native page integration.
 *
 * Registers builder meta on WordPress pages and keeps generated
 * AM Builder assets in sync when those pages are saved.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_Page_Integration
 *
 * Hooks AM Builder into the core `page` post type.
 *
 * @since 1.0.0
 */
class AMB_Page_Integration {

	/**
	 * Supported post type slug.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const POST_TYPE = 'page';

	/**
	 * Constructor. Registers hooks for page integration.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );
	}

	/**
	 * Register page meta fields for REST API access.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_meta() {
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

		register_post_meta(
			self::POST_TYPE,
			'_amb_rendered_html',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => function ( $html ) {
					return current_user_can( 'unfiltered_html' ) ? $html : wp_kses_post( $html );
				},
				'auth_callback'     => function () {
					return current_user_can( 'edit_pages' );
				},
			)
		);

		register_post_meta(
			self::POST_TYPE,
			'_amb_custom_css',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_textarea_field',
				'auth_callback'     => function () {
					return current_user_can( 'edit_pages' );
				},
			)
		);

		register_post_meta(
			self::POST_TYPE,
			'_amb_page_settings',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => function ( $value ) {
					// Page settings are JSON-encoded objects that may contain trusted
					// import markup (e.g. <script> tags). Sanitization is handled by
					// sanitize_builder_page_settings_meta_value() in the REST API layer,
					// so we only strip slashes here rather than using sanitize_text_field
					// which would strip HTML tags and break the stored JSON.
					return is_string( $value ) ? $value : '';
				},
				'auth_callback'     => function () {
					return current_user_can( 'edit_pages' );
				},
			)
		);

		register_post_meta(
			self::POST_TYPE,
			'_amb_builder_enabled',
			array(
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => function () {
					return current_user_can( 'edit_pages' );
				},
			)
		);
	}

	/**
	 * Handle page saves.
	 *
	 * @since  1.0.0
	 * @param  int     $post_id Post ID.
	 * @param  WP_Post $post    Post object.
	 * @return void
	 */
	public function save_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		do_action( 'amb_page_saved', $post_id, $post );
	}

	/**
	 * Determine whether a page is already managed by AM Builder.
	 *
	 * @since  1.0.0
	 * @param  int|WP_Post|null $post Post object or ID.
	 * @return bool
	 */
	public static function is_builder_page( $post ) {
		$post = self::resolve_post( $post );

		if ( ! $post ) {
			return false;
		}

		$enabled = get_post_meta( $post->ID, '_amb_builder_enabled', true );
		if ( ! empty( $enabled ) ) {
			return true;
		}

		$meta_keys = array(
			'_amb_blocks',
			'_amb_rendered_html',
			'_amb_custom_css',
		);

		foreach ( $meta_keys as $meta_key ) {
			$value = get_post_meta( $post->ID, $meta_key, true );

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether a page can be migrated into AM Builder.
	 *
	 * @since  1.0.0
	 * @param  int|WP_Post|null $post Post object or ID.
	 * @return bool
	 */
	public static function has_migratable_content( $post ) {
		$post = self::resolve_post( $post );

		if ( ! $post || self::is_builder_page( $post ) ) {
			return false;
		}

		if ( in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) {
			return false;
		}

		$content = preg_replace( '/<!--[\s\S]*?-->/', '', (string) $post->post_content );
		$content = trim( wp_strip_all_tags( $content ) );

		return '' !== $content;
	}

	/**
	 * Resolve and validate a native page post.
	 *
	 * @since  1.0.0
	 * @param  int|WP_Post|null $post Post object or ID.
	 * @return WP_Post|null
	 */
	private static function resolve_post( $post ) {
		if ( is_numeric( $post ) ) {
			$post = get_post( (int) $post );
		}

		if ( ! ( $post instanceof \WP_Post ) || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return $post;
	}
}
