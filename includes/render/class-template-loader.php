<?php
/**
 * Template loader.
 *
 * Overrides WordPress template hierarchy to load builder pages
 * with their custom templates, headers, and footers.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_Render_Template_Loader
 *
 * Handles frontend template loading for builder pages.
 *
 * @since 1.0.0
 */
class AMB_Render_Template_Loader {

	/**
	 * Constructor. Registers template loading hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_filter( 'template_include', array( $this, 'load_page_template' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Load custom template for builder pages.
	 *
	 * @since  1.0.0
	 * @param  string $template Default template path.
	 * @return string Modified template path.
	 */
	public function load_page_template( $template ) {
		global $post;

		if ( ! $post ) {
			return $template;
		}

		if ( ! $this->is_builder_enabled_post( $post ) ) {
			return $template;
		}

		// Use the builder's canvas template (full-width, no theme chrome).
		$custom_template = AMB_PLUGIN_DIR . 'templates/frontend/page.php';

		if ( file_exists( $custom_template ) ) {
			return $custom_template;
		}

		return $template;
	}

	/**
	 * Enqueue frontend assets for builder pages.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function enqueue_frontend_assets() {
		global $post;

		if ( ! $post ) {
			return;
		}

		if ( ! $this->is_builder_enabled_post( $post ) ) {
			return;
		}

		$this->maybe_generate_page_assets( $post->ID );
		$is_imported_page = $this->is_imported_project_page( $post->ID );

		if ( ! $is_imported_page ) {
			// Base frontend CSS is useful for native builder pages, but imported
			// HTML projects already ship their own document-level styling.
			wp_enqueue_style(
				'amb-frontend',
				AMB_PLUGIN_URL . 'assets/css/frontend.css',
				array(),
				AMB_VERSION
			);
		}

		// Page-specific CSS from uploads.
		$page_css = $this->get_asset_url( $post->ID, 'css' );
		if ( $page_css ) {
			wp_enqueue_style(
				'amb-page-' . $post->ID,
				$page_css['url'],
				$is_imported_page ? array() : array( 'amb-frontend' ),
				$page_css['version']
			);
		}

		// Page-specific JS from uploads.
		$page_js = $this->get_asset_url( $post->ID, 'js' );
		if ( $page_js ) {
			wp_enqueue_script(
				'amb-page-' . $post->ID,
				$page_js['url'],
				array(),
				$page_js['version'],
				true
			);
		}

	}

	/**
	 * Get asset URL and version from database.
	 *
	 * @since  1.0.0
	 * @param  int    $post_id Post ID.
	 * @param  string $type    Asset type (css or js).
	 * @return array|false Asset info or false.
	 */
	private function get_asset_url( $post_id, $type ) {
		if ( 'css' === $type ) {
			foreach ( $this->get_generated_css_candidates( $post_id ) as $asset ) {
				if ( file_exists( $asset['path'] ) ) {
					$file_mtime = filemtime( $asset['path'] );

					return array(
						'url'     => $asset['url'],
						'version' => false !== $file_mtime ? (string) $file_mtime : (string) current_time( 'timestamp' ),
					);
				}
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'amb_assets';

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT file_url, version FROM {$table} WHERE post_id = %d AND asset_type = %s",
				$post_id,
				$type
			),
			ARRAY_A
		);

		if ( ! $result ) {
			return false;
		}

		return array(
			'url'     => $result['file_url'],
			'version' => $result['version'],
		);
	}

	/**
	 * Generate page assets on demand when builder data exists but the CSS file is missing.
	 *
	 * @since  1.0.0
	 * @param  int $post_id Post ID.
	 * @return void
	 */
	private function maybe_generate_page_assets( $post_id ) {
		if ( empty( $post_id ) ) {
			return;
		}

		$css_path = $this->get_generated_css_path( $post_id );
		if ( file_exists( $css_path ) ) {
			return;
		}

		$blocks_json    = get_post_meta( $post_id, '_amb_blocks', true );
		$custom_css     = get_post_meta( $post_id, '_amb_custom_css', true );
		$rendered_html  = get_post_meta( $post_id, '_amb_rendered_html', true );
		$has_inline_css = is_string( $rendered_html ) && preg_match( '/<style[^>]*>.*?<\/style>/si', $rendered_html );

		if ( empty( $blocks_json ) && empty( $custom_css ) && ! $has_inline_css ) {
			return;
		}

		do_action( 'amb_page_saved', $post_id, get_post( $post_id ) );
	}

	/**
	 * Get the deterministic generated CSS path for a page.
	 *
	 * @since  1.0.0
	 * @param  int $post_id Post ID.
	 * @return string
	 */
	private function get_generated_css_path( $post_id ) {
		$candidates = $this->get_generated_css_candidates( $post_id );

		return $candidates[0]['path'];
	}

	/**
	 * Determine whether a page was imported from an external HTML project.
	 *
	 * Imported pages should render with their own CSS without the builder's
	 * base frontend stylesheet overriding document-level defaults.
	 *
	 * @since  1.0.0
	 * @param  int $post_id Post ID.
	 * @return bool
	 */
	private function is_imported_project_page( $post_id ) {
		$page_settings = json_decode( (string) get_post_meta( $post_id, '_amb_page_settings', true ), true );

		if ( ! is_array( $page_settings ) ) {
			return false;
		}

		foreach ( array( 'importedHeadMarkup', 'importedFooterMarkup', 'importedHtmlAttributes', 'importedBodyAttributes' ) as $setting_key ) {
			if ( ! empty( $page_settings[ $setting_key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether the given post should render with the builder template.
	 *
	 * @since  1.0.0
	 * @param  WP_Post|null $post Post object.
	 * @return bool
	 */
	private function is_builder_enabled_post( $post ) {
		if ( ! ( $post instanceof \WP_Post ) || ! is_singular() ) {
			return false;
		}

		if ( AMB_Page_Integration::POST_TYPE !== $post->post_type ) {
			return false;
		}

		$meta_keys = array(
			'_amb_rendered_html',
			'_amb_blocks',
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
	 * Get candidate CSS asset locations, preferring the documented upload path.
	 *
	 * @since 1.0.0
	 * @param int $post_id Post ID.
	 * @return array<int,array{path:string,url:string}>
	 */
	private function get_generated_css_candidates( $post_id ) {
		$upload_dir = wp_upload_dir();

		return array(
			array(
				'path' => trailingslashit( $upload_dir['basedir'] ) . AMB_UPLOAD_DIR . '/css/post-' . $post_id . '.css',
				'url'  => trailingslashit( $upload_dir['baseurl'] ) . AMB_UPLOAD_DIR . '/css/post-' . $post_id . '.css',
			),
			array(
				'path' => trailingslashit( $upload_dir['basedir'] ) . 'am-builder/css/post-' . $post_id . '.css',
				'url'  => trailingslashit( $upload_dir['baseurl'] ) . 'am-builder/css/post-' . $post_id . '.css',
			),
		);
	}

}
