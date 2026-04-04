<?php
/**
 * Asset generator.
 *
 * Generates page-specific CSS and JS files and stores them
 * in the uploads directory with versioned filenames.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_Render_Asset_Generator
 *
 * Handles generation, storage, and versioning of page-specific
 * CSS and JS assets in the uploads directory.
 *
 * @since 1.0.0
 */
class AMB_Render_Asset_Generator {

	/**
	 * Constructor. Hooks into page save events.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'amb_page_saved', array( $this, 'generate_assets' ), 10, 2 );
	}

	/**
	 * Generate CSS and JS assets for a specific page.
	 *
	 * @since  1.0.0
	 * @param  int     $post_id Post ID.
	 * @param  WP_Post $post    Post object.
	 * @return void
	 */
	public function generate_assets( $post_id, $post = null ) {
		$this->generate_css( $post_id );
		$this->generate_js( $post_id );
	}

	/**
	 * Generate page-specific CSS file.
	 *
	 * @since  1.0.0
	 * @param  int $post_id Post ID.
	 * @return bool True on success.
	 */
	public function generate_css( $post_id ) {
		$blocks_json = get_post_meta( $post_id, '_amb_blocks', true );
		$custom_css  = get_post_meta( $post_id, '_amb_custom_css', true );
		$rendered_html = get_post_meta( $post_id, '_amb_rendered_html', true );

		$css = '';

		// Generate CSS from blocks.
		if ( ! empty( $blocks_json ) ) {
			$blocks = json_decode( $blocks_json, true );
			if ( is_array( $blocks ) ) {
				$css .= $this->blocks_to_css( $blocks );
			}
		}

		$legacy_inline_css = $this->extract_inline_css( $rendered_html );
		if ( ! empty( $legacy_inline_css ) ) {
			$css .= "\n" . $legacy_inline_css;
		}

		// Append custom CSS.
		if ( ! empty( $custom_css ) ) {
			$css .= "\n/* Custom CSS */\n" . $custom_css;
		}

		if ( empty( $css ) ) {
			$this->delete_tracked_asset( $post_id, 'css' );
			return false;
		}

		// Minify CSS.
		$css = $this->minify_css( $css );

		if ( '' === $css ) {
			$this->delete_tracked_asset( $post_id, 'css' );
			return false;
		}

		// Write file.
		$result = $this->write_asset_file( $post_id, 'css', $css );
		return (bool) $result;
	}

	/**
	 * Generate page-specific JS file.
	 *
	 * @since  1.0.0
	 * @param  int $post_id Post ID.
	 * @return bool True on success.
	 */
	public function generate_js( $post_id ) {
		$blocks_json = get_post_meta( $post_id, '_amb_blocks', true );

		if ( empty( $blocks_json ) ) {
			$this->delete_tracked_asset( $post_id, 'js' );
			return false;
		}

		$blocks = json_decode( $blocks_json, true );
		if ( ! is_array( $blocks ) ) {
			return false;
		}

		$js = $this->blocks_to_js( $blocks );

		if ( empty( $js ) ) {
			$this->delete_tracked_asset( $post_id, 'js' );
			return false;
		}

		$result = $this->write_asset_file( $post_id, 'js', $js );
		return (bool) $result;
	}

	/**
	 * Convert blocks to CSS.
	 *
	 * @since  1.0.0
	 * @param  array $blocks Block definitions.
	 * @return string Generated CSS.
	 */
	private function blocks_to_css( $blocks ) {
		$css = '';

		foreach ( $blocks as $block ) {
			if ( ! isset( $block['id'] ) || ! isset( $block['styles'] ) ) {
				continue;
			}

			$styles = $block['styles'];
			if ( ! empty( $styles ) ) {
				$css .= $this->block_styles_to_css( $block['id'], $styles );
			}

			// Process responsive styles.
			if ( isset( $block['responsiveStyles'] ) && is_array( $block['responsiveStyles'] ) ) {
				foreach ( $block['responsiveStyles'] as $breakpoint => $responsive_styles ) {
					$media_query = $this->get_media_query( $breakpoint );
					if ( $media_query && ! empty( $responsive_styles ) ) {
						$css .= $media_query . ' { ' . $this->block_styles_to_css( $block['id'], $responsive_styles ) . ' }';
					}
				}
			}

			// Process children recursively.
			if ( isset( $block['children'] ) && is_array( $block['children'] ) ) {
				$css .= $this->blocks_to_css( $block['children'] );
			}
		}

		return $css;
	}

	/**
	 * Convert block styles to CSS selector + properties.
	 *
	 * @since  1.0.0
	 * @param  string $block_id Block ID.
	 * @param  array  $styles   Style properties.
	 * @return string CSS rule.
	 */
	private function block_styles_to_css( $block_id, $styles ) {
		$props = array();
		foreach ( $styles as $prop => $value ) {
			if ( ! empty( $value ) ) {
				$css_prop = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $prop ?? '' ) );
				$props[]  = $css_prop . ': ' . $value;
			}
		}

		if ( empty( $props ) ) {
			return '';
		}

		return '[data-amb-id="' . $block_id . '"] { ' . implode( '; ', $props ) . '; } ';
	}

	/**
	 * Get media query for a breakpoint.
	 *
	 * @since  1.0.0
	 * @param  string $breakpoint Breakpoint name.
	 * @return string Media query string.
	 */
	private function get_media_query( $breakpoint ) {
		$breakpoints = array(
			'tablet'   => '@media (max-width: 1024px)',
			'mobile-l' => '@media (max-width: 768px)',
			'mobile-s' => '@media (max-width: 480px)',
		);

		return isset( $breakpoints[ $breakpoint ] ) ? $breakpoints[ $breakpoint ] : '';
	}

	/**
	 * Convert blocks to JS (for interactive blocks).
	 *
	 * @since  1.0.0
	 * @param  array $blocks Block definitions.
	 * @return string Generated JS.
	 */
	private function blocks_to_js( $blocks ) {
		$js          = '';
		$has_tabs     = false;
		$has_accordion = false;
		$has_slider   = false;
		$has_counter  = false;

		foreach ( $blocks as $block ) {
			if ( ! isset( $block['type'] ) ) {
				continue;
			}

			switch ( $block['type'] ) {
				case 'tabs':
					$has_tabs = true;
					break;
				case 'accordion':
					$has_accordion = true;
					break;
				case 'slider':
					$has_slider = true;
					break;
				case 'counter':
					$has_counter = true;
					break;
			}

			// Check children.
			if ( isset( $block['children'] ) && is_array( $block['children'] ) ) {
				$child_js = $this->blocks_to_js( $block['children'] );
				if ( ! empty( $child_js ) ) {
					$js .= $child_js;
				}
			}
		}

		// Add initialization scripts.
		if ( $has_tabs ) {
			$js .= $this->get_tabs_js();
		}

		if ( $has_accordion ) {
			$js .= $this->get_accordion_js();
		}

		if ( $has_counter ) {
			$js .= $this->get_counter_js();
		}

		return $js;
	}

	/**
	 * Get tabs initialization JS.
	 *
	 * @since  1.0.0
	 * @return string JS code.
	 */
	private function get_tabs_js() {
		return "document.querySelectorAll('.amb-block--tabs').forEach(function(t){var n=t.querySelectorAll('.amb-tab-btn'),p=t.querySelectorAll('.amb-tab-panel');n.forEach(function(b,i){b.addEventListener('click',function(){n.forEach(function(x){x.classList.remove('active')});p.forEach(function(x){x.classList.remove('active')});b.classList.add('active');p[i]&&p[i].classList.add('active')})})});\n";
	}

	/**
	 * Get accordion initialization JS.
	 *
	 * @since  1.0.0
	 * @return string JS code.
	 */
	private function get_accordion_js() {
		return "document.querySelectorAll('.amb-block--accordion .amb-accordion-header').forEach(function(h){h.addEventListener('click',function(){var item=h.parentElement;item.classList.toggle('active')})});\n";
	}

	/**
	 * Get counter animation JS.
	 *
	 * @since  1.0.0
	 * @return string JS code.
	 */
	private function get_counter_js() {
		return "document.querySelectorAll('.amb-block--counter').forEach(function(c){var t=parseInt(c.dataset.target)||0,d=parseInt(c.dataset.duration)||2000,s=0,inc=t/(d/16);var timer=setInterval(function(){s+=inc;if(s>=t){s=t;clearInterval(timer)}c.querySelector('.amb-counter-value').textContent=Math.floor(s)},16)});\n";
	}

	/**
	 * Minify CSS.
	 *
	 * @since  1.0.0
	 * @param  string $css CSS string.
	 * @return string Minified CSS.
	 */
	private function minify_css( $css ) {
		// Remove comments.
		$css = preg_replace( '!/\*.*?\*/!s', '', $css );

		// Remove whitespace.
		$css = preg_replace( '/\s+/', ' ', $css );
		$css = str_replace( array( ' {', '{ ' ), '{', $css );
		$css = str_replace( array( ' }', '} ' ), '}', $css );
		$css = str_replace( '; ', ';', $css );
		$css = str_replace( ': ', ':', $css );

		return trim( $css );
	}

	/**
	 * Extract inline CSS from rendered HTML.
	 *
	 * @since  1.0.0
	 * @param  string $html Rendered HTML content.
	 * @return string Extracted CSS.
	 */
	private function extract_inline_css( $html ) {
		if ( empty( $html ) || ! is_string( $html ) ) {
			return '';
		}

		$css = '';

		if ( preg_match_all( '/<style[^>]*>(.*?)<\/style>/si', $html, $matches ) ) {
			foreach ( $matches[1] as $style_content ) {
				$css .= trim( $style_content ) . "\n";
			}
		}

		return trim( $css );
	}

	/**
	 * Write an asset file to the uploads directory.
	 *
	 * @since  1.0.0
	 * @param  int    $post_id    Post ID.
	 * @param  string $type       Asset type (css or js).
	 * @param  string $content    File content.
	 * @return array|false File info array or false on failure.
	 */
	private function write_asset_file( $post_id, $type, $content ) {
		$upload_dir = wp_upload_dir();

		if ( 'css' === $type ) {
			$base_dir = trailingslashit( $upload_dir['basedir'] ) . AMB_UPLOAD_DIR . '/css';
			$base_url = trailingslashit( $upload_dir['baseurl'] ) . AMB_UPLOAD_DIR . '/css';
			$filename = 'post-' . $post_id . '.css';
			$version  = null;
		} else {
			$base_dir = trailingslashit( $upload_dir['basedir'] ) . AMB_UPLOAD_DIR . '/' . $type;
			$base_url = trailingslashit( $upload_dir['baseurl'] ) . AMB_UPLOAD_DIR . '/' . $type;
			$version  = substr( md5( $content ), 0, 8 );
			$filename = 'page-' . $post_id . '-' . $version . '.' . $type;
		}

		if ( ! file_exists( $base_dir ) ) {
			wp_mkdir_p( $base_dir );
		}

		$filepath = trailingslashit( $base_dir ) . $filename;
		$fileurl  = trailingslashit( $base_url ) . $filename;

		// Remove old versions and legacy CSS files.
		$old_files = $this->get_asset_cleanup_paths( $post_id, $type );
		foreach ( $old_files as $old_file ) {
			if ( $old_file !== $filepath && file_exists( $old_file ) ) {
				wp_delete_file( $old_file );
			}
		}

		// Write content to file.
		$existing_content = file_exists( $filepath )
			? file_get_contents( $filepath ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			: false;

		if ( ! file_exists( $filepath ) || $existing_content !== $content ) {
			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if ( $wp_filesystem ) {
				$wp_filesystem->put_contents( $filepath, $content, FS_CHMOD_FILE );
			} else {
				file_put_contents( $filepath, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}

		if ( 'css' === $type ) {
			$file_mtime = file_exists( $filepath ) ? filemtime( $filepath ) : false;
			$version    = false !== $file_mtime ? (string) $file_mtime : (string) current_time( 'timestamp' );
		}

		// Track asset in database.
		$this->track_asset( $post_id, $type, $filepath, $fileurl, $version );

		return array(
			'path'    => $filepath,
			'url'     => $fileurl,
			'version' => $version,
		);
	}

	/**
	 * Get cleanup paths for an asset type.
	 *
	 * @since  1.0.0
	 * @param  int    $post_id Post ID.
	 * @param  string $type    Asset type.
	 * @return array Files eligible for cleanup.
	 */
	private function get_asset_cleanup_paths( $post_id, $type ) {
		$upload_dir = wp_upload_dir();
		$paths      = array();

		if ( 'css' === $type ) {
			$paths[] = trailingslashit( $upload_dir['basedir'] ) . AMB_UPLOAD_DIR . '/css/post-' . $post_id . '.css';
			$paths[] = trailingslashit( $upload_dir['basedir'] ) . 'am-builder/css/post-' . $post_id . '.css';
			$paths[] = trailingslashit( $upload_dir['basedir'] ) . 'elementor/css/post-' . $post_id . '.css';
			$legacy  = glob( trailingslashit( $upload_dir['basedir'] ) . AMB_UPLOAD_DIR . '/css/page-' . $post_id . '-*.css' );
		} else {
			$legacy = glob( trailingslashit( $upload_dir['basedir'] ) . AMB_UPLOAD_DIR . '/' . $type . '/page-' . $post_id . '-*.' . $type );
		}

		if ( $legacy ) {
			$paths = array_merge( $paths, $legacy );
		}

		return array_values( array_unique( array_filter( $paths ) ) );
	}

	/**
	 * Delete tracked files and database records for an asset.
	 *
	 * @since  1.0.0
	 * @param  int    $post_id Post ID.
	 * @param  string $type    Asset type.
	 * @return void
	 */
	private function delete_tracked_asset( $post_id, $type ) {
		foreach ( $this->get_asset_cleanup_paths( $post_id, $type ) as $file_path ) {
			if ( file_exists( $file_path ) ) {
				wp_delete_file( $file_path );
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'amb_assets';

		$wpdb->delete(
			$table,
			array(
				'post_id'    => $post_id,
				'asset_type' => $type,
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Track asset in the database.
	 *
	 * @since  1.0.0
	 * @param  int    $post_id  Post ID.
	 * @param  string $type     Asset type.
	 * @param  string $filepath File path.
	 * @param  string $fileurl  File URL.
	 * @param  string $version  Version hash.
	 * @return void
	 */
	private function track_asset( $post_id, $type, $filepath, $fileurl, $version ) {
		global $wpdb;
		$table = $wpdb->prefix . 'amb_assets';

		// Check if record exists.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE post_id = %d AND asset_type = %s",
				$post_id,
				$type
			)
		);

		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'file_path' => $filepath,
					'file_url'  => $fileurl,
					'version'   => $version,
				),
				array(
					'post_id'    => $post_id,
					'asset_type' => $type,
				),
				array( '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'post_id'    => $post_id,
					'asset_type' => $type,
					'file_path'  => $filepath,
					'file_url'   => $fileurl,
					'version'    => $version,
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
		}
	}

}
