<?php
/**
 * Page renderer.
 *
 * Converts block JSON into rendered HTML for frontend display.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_Render_Renderer
 *
 * Renders builder pages from block JSON to HTML.
 *
 * @since 1.0.0
 */
class AMB_Render_Renderer {

	/**
	 * Render a page from its block JSON.
	 *
	 * @since  1.0.0
	 * @param  int $post_id Post ID.
	 * @return string Rendered HTML.
	 */
	public function render_page( $post_id ) {
		$blocks_json = get_post_meta( $post_id, '_amb_blocks', true );

		if ( empty( $blocks_json ) ) {
			// Fall back to rendered HTML if no blocks.
			$rendered = get_post_meta( $post_id, '_amb_rendered_html', true );
			return $rendered ? $rendered : '';
		}

		$blocks = json_decode( $blocks_json, true );

		if ( ! is_array( $blocks ) ) {
			return '';
		}

		$html = '';
		foreach ( $blocks as $block ) {
			$html .= $this->render_block( $block );
		}

		return $html;
	}

	/**
	 * Render a single block.
	 *
	 * @since  1.0.0
	 * @param  array $block Block definition.
	 * @return string Rendered HTML.
	 */
	public function render_block( $block ) {
		if ( ! isset( $block['type'] ) ) {
			return '';
		}

		$type = $block['type'];

		/**
		 * Filter block rendering.
		 *
		 * Allows custom block renderers to override the default rendering.
		 *
		 * @since 1.0.0
		 * @param string|null $html    Pre-rendered HTML (null = use default).
		 * @param array       $block   Block definition.
		 * @param self        $renderer Renderer instance.
		 */
		$custom_html = apply_filters( 'amb_render_block', null, $block, $this );
		if ( null !== $custom_html ) {
			return $custom_html;
		}

		$content  = isset( $block['content'] ) ? $block['content'] : array();
		$styles   = isset( $block['styles'] ) ? $block['styles'] : array();
		$children = isset( $block['children'] ) ? $block['children'] : array();
		$id       = isset( $block['id'] ) ? $block['id'] : '';

		$style_attr = $this->build_style_string( $styles );
		$class_attr = 'amb-block amb-block--' . $type;
		$data_attr  = $id ? ' data-amb-id="' . esc_attr( $id ) . '"' : '';

		if ( ! empty( $block['className'] ) ) {
			$class_names = preg_split( '/\s+/', (string) $block['className'] );
			$class_names = array_filter( array_map( 'sanitize_html_class', $class_names ) );

			if ( ! empty( $class_names ) ) {
				$class_attr .= ' ' . implode( ' ', $class_names );
			}
		}

		switch ( $type ) {
			case 'section':
				return $this->render_section( $block, $class_attr, $style_attr, $children, $data_attr );

			case 'row':
				return $this->render_row( $class_attr, $style_attr, $children, $data_attr );

			case 'column':
				return $this->render_column( $block, $class_attr, $style_attr, $children, $data_attr );

			case 'container':
				return $this->render_container( $class_attr, $style_attr, $children, $data_attr );

			case 'heading':
				return $this->render_heading( $content, $class_attr, $style_attr, $data_attr );

			case 'paragraph':
				return $this->render_paragraph( $content, $class_attr, $style_attr, $data_attr );

			case 'image':
				return $this->render_image( $content, $class_attr, $style_attr, $data_attr );

			case 'button':
				return $this->render_button( $content, $class_attr, $style_attr, $data_attr );

			case 'spacer':
				return $this->render_spacer( $content, $class_attr, $style_attr, $data_attr );

			case 'divider':
				return $this->render_divider( $class_attr, $style_attr, $data_attr );

			case 'video':
				return $this->render_video( $content, $class_attr, $style_attr, $data_attr );

			case 'html':
				return $this->render_html_block( $content, $class_attr, $style_attr, $data_attr );

			case 'list':
				return $this->render_list( $content, $class_attr, $style_attr, $data_attr );

			default:
				return '';
		}
	}

	/**
	 * Render a section block.
	 *
	 * @since  1.0.0
	 * @param  array  $block      Block definition.
	 * @param  string $class_attr CSS classes.
	 * @param  string $style_attr Inline styles.
	 * @param  array  $children   Child blocks.
	 * @return string HTML output.
	 */
	private function render_section( $block, $class_attr, $style_attr, $children, $data_attr ) {
		$html  = '<section class="' . esc_attr( $class_attr ) . '"' . $data_attr . $style_attr . ' data-amb-section="' . esc_attr( $block['id'] ) . '">';
		$html .= '<div class="amb-section__inner">';

		foreach ( $children as $child ) {
			$html .= $this->render_block( $child );
		}

		$html .= '</div></section>';
		return $html;
	}

	/**
	 * Render a row block.
	 *
	 * @since  1.0.0
	 * @param  array  $block      Block definition.
	 * @param  string $class_attr CSS classes.
	 * @param  string $style_attr Inline styles.
	 * @param  array  $children   Child blocks.
	 * @return string HTML output.
	 */
	private function render_row( $class_attr, $style_attr, $children, $data_attr ) {
		$html = '<div class="' . esc_attr( $class_attr ) . '"' . $data_attr . $style_attr . '>';

		foreach ( $children as $child ) {
			$html .= $this->render_block( $child );
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Render a column block.
	 *
	 * @since  1.0.0
	 * @param  array  $block      Block definition.
	 * @param  string $class_attr CSS classes.
	 * @param  string $style_attr Inline styles.
	 * @param  array  $children   Child blocks.
	 * @return string HTML output.
	 */
	private function render_column( $block, $class_attr, $style_attr, $children, $data_attr ) {
		$width = isset( $block['content']['width'] ) ? $block['content']['width'] : '';
		$style_attr = $this->append_style_declarations( $style_attr, $width ? array( 'width' => $width ) : array() );

		$html = '<div class="' . esc_attr( $class_attr ) . '"' . $data_attr . $style_attr . '>';

		foreach ( $children as $child ) {
			$html .= $this->render_block( $child );
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Render a container block.
	 *
	 * @since  1.0.0
	 * @param  array  $block      Block definition.
	 * @param  string $class_attr CSS classes.
	 * @param  string $style_attr Inline styles.
	 * @param  array  $children   Child blocks.
	 * @return string HTML output.
	 */
	private function render_container( $class_attr, $style_attr, $children, $data_attr ) {
		$html = '<div class="' . esc_attr( $class_attr ) . '"' . $data_attr . $style_attr . '>';

		foreach ( $children as $child ) {
			$html .= $this->render_block( $child );
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Render a heading block.
	 *
	 * @since  1.0.0
	 * @param  array  $content    Block content.
	 * @param  string $class_attr CSS classes.
	 * @param  string $style_attr Inline styles.
	 * @return string HTML output.
	 */
	private function render_heading( $content, $class_attr, $style_attr, $data_attr ) {
		$tag  = isset( $content['tag'] ) ? $content['tag'] : 'h2';
		$text = isset( $content['text'] ) ? $content['text'] : '';

		$allowed_tags = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );
		if ( ! in_array( $tag, $allowed_tags, true ) ) {
			$tag = 'h2';
		}

		return '<' . $tag . ' class="' . esc_attr( $class_attr ) . '"' . $data_attr . $style_attr . '>' . wp_kses_post( $text ) . '</' . $tag . '>';
	}

	/**
	 * Render a paragraph block.
	 *
	 * @since  1.0.0
	 * @param  array  $content    Block content.
	 * @param  string $class_attr CSS classes.
	 * @param  string $style_attr Inline styles.
	 * @return string HTML output.
	 */
	private function render_paragraph( $content, $class_attr, $style_attr, $data_attr ) {
		$text = isset( $content['text'] ) ? $content['text'] : '';
		return '<p class="' . esc_attr( $class_attr ) . '"' . $data_attr . $style_attr . '>' . wp_kses_post( $text ) . '</p>';
	}

	/**
	 * Render an image block.
	 *
	 * @since  1.0.0
	 * @param  array  $content    Block content.
	 * @param  string $class_attr CSS classes.
	 * @param  string $style_attr Inline styles.
	 * @return string HTML output.
	 */
	private function render_image( $content, $class_attr, $style_attr, $data_attr ) {
		$src = isset( $content['src'] ) ? $content['src'] : '';
		$alt = isset( $content['alt'] ) ? $content['alt'] : '';

		$html = '<figure class="' . esc_attr( $class_attr ) . '"' . $data_attr . $style_attr . '>';
		$html .= '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" />';

		if ( ! empty( $content['caption'] ) ) {
			$html .= '<figcaption>' . wp_kses_post( $content['caption'] ) . '</figcaption>';
		}

		$html .= '</figure>';
		return $html;
	}

	/**
	 * Render a button block.
	 *
	 * @since  1.0.0
	 * @param  array  $content    Block content.
	 * @param  string $class_attr CSS classes.
	 * @param  string $style_attr Inline styles.
	 * @return string HTML output.
	 */
	private function render_button( $content, $class_attr, $style_attr, $data_attr ) {
		$text   = isset( $content['text'] ) ? $content['text'] : __( 'Click Here', 'antimanual-builder' );
		$url    = isset( $content['url'] ) ? $content['url'] : '#';
		$target = isset( $content['newTab'] ) && $content['newTab'] ? ' target="_blank" rel="noopener noreferrer"' : '';

		return '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class_attr ) . '"' . $data_attr . $style_attr . $target . '>' . esc_html( $text ) . '</a>';
	}

	/**
	 * Render a spacer block.
	 *
	 * @since  1.0.0
	 * @param  array  $content    Block content.
	 * @param  string $class_attr CSS classes.
	 * @param  string $style_attr Inline styles.
	 * @return string HTML output.
	 */
	private function render_spacer( $content, $class_attr, $style_attr, $data_attr ) {
		$height = isset( $content['height'] ) ? $content['height'] : '40px';
		$style_attr = $this->append_style_declarations( $style_attr, array( 'height' => $height ) );
		return '<div class="' . esc_attr( $class_attr ) . '"' . $data_attr . $style_attr . ' aria-hidden="true"></div>';
	}

	/**
	 * Render a divider block.
	 *
	 * @since  1.0.0
	 * @param  string $class_attr CSS classes.
	 * @param  string $style_attr Inline styles.
	 * @return string HTML output.
	 */
	private function render_divider( $class_attr, $style_attr, $data_attr ) {
		return '<hr class="' . esc_attr( $class_attr ) . '"' . $data_attr . $style_attr . ' />';
	}

	/**
	 * Render a video block.
	 *
	 * @since  1.0.0
	 * @param  array  $content    Block content.
	 * @param  string $class_attr CSS classes.
	 * @param  string $style_attr Inline styles.
	 * @return string HTML output.
	 */
	private function render_video( $content, $class_attr, $style_attr, $data_attr ) {
		$src  = isset( $content['src'] ) ? $content['src'] : '';
		$type = isset( $content['videoType'] ) ? $content['videoType'] : 'embed';

		$html = '<div class="' . esc_attr( $class_attr ) . '"' . $data_attr . $style_attr . '>';

		if ( 'embed' === $type && $src ) {
			$html .= wp_oembed_get( $src );
		} elseif ( $src ) {
			$html .= '<video src="' . esc_url( $src ) . '" controls></video>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Render a raw HTML block.
	 *
	 * @since  1.0.0
	 * @param  array $content Block content.
	 * @return string HTML output.
	 */
	private function render_html_block( $content, $class_attr, $style_attr, $data_attr ) {
		$html = isset( $content['html'] ) ? (string) $content['html'] : '';

		if ( '' === trim( $html ) ) {
			return '';
		}

		return '<div class="' . esc_attr( $class_attr ) . '"' . $data_attr . $style_attr . '>' . $html . '</div>';
	}

	/**
	 * Render a list block.
	 *
	 * @since  1.0.0
	 * @param  array  $content    Block content.
	 * @param  string $class_attr CSS classes.
	 * @param  string $style_attr Inline styles.
	 * @return string HTML output.
	 */
	private function render_list( $content, $class_attr, $style_attr, $data_attr ) {
		$tag   = isset( $content['ordered'] ) && $content['ordered'] ? 'ol' : 'ul';
		$items = isset( $content['items'] ) ? $content['items'] : array();

		$html = '<' . $tag . ' class="' . esc_attr( $class_attr ) . '"' . $data_attr . $style_attr . '>';

		foreach ( $items as $item ) {
			$html .= '<li>' . wp_kses_post( $item ) . '</li>';
		}

		$html .= '</' . $tag . '>';
		return $html;
	}

	/**
	 * Build inline style string from a styles array.
	 *
	 * @since  1.0.0
	 * @param  array $styles Style properties.
	 * @return string CSS inline style attribute, or empty string.
	 */
	private function build_style_string( $styles ) {
		if ( empty( $styles ) ) {
			return '';
		}

		$parts = array();
		foreach ( $styles as $prop => $value ) {
			if ( ! empty( $value ) ) {
				$css_prop = $this->camel_to_kebab( $prop );
				$parts[]  = $css_prop . ':' . $value;
			}
		}

		return ! empty( $parts ) ? ' style="' . esc_attr( implode( ';', $parts ) ) . '"' : '';
	}

	/**
	 * Append additional inline CSS declarations to an existing style attribute.
	 *
	 * @since 1.0.0
	 * @param string $style_attr Existing style attribute string.
	 * @param array  $styles     Additional styles to merge in.
	 * @return string
	 */
	private function append_style_declarations( $style_attr, $styles ) {
		$extra_attr = $this->build_style_string( $styles );

		if ( '' === $style_attr ) {
			return $extra_attr;
		}

		if ( '' === $extra_attr ) {
			return $style_attr;
		}

		$current = preg_replace( '/^ style="|"$/' , '', $style_attr );
		$extra   = preg_replace( '/^ style="|"$/' , '', $extra_attr );

		return ' style="' . esc_attr( trim( $current . ';' . $extra, ';' ) ) . '"';
	}

	/**
	 * Convert camelCase to kebab-case.
	 *
	 * @since  1.0.0
	 * @param  string $string camelCase string.
	 * @return string kebab-case string.
	 */
	private function camel_to_kebab( $string ) {
		return strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $string ?? '' ) );
	}
}
