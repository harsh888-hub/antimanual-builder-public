<?php
/**
 * Builder Meta API Trait.
 *
 * Provides shared methods for validating and saving common builder meta data
 * (like blocks and rendered HTML) across different post types.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait AMB_API_Builder_Meta_Trait
 *
 * @since 1.0.0
 */
trait AMB_API_Builder_Meta_Trait {

	/**
	 * Sanitize builder HTML based on the current user's capabilities.
	 *
	 * @since 1.0.0
	 * @param mixed $html Raw HTML.
	 * @return string
	 */
	protected function sanitize_builder_html_value( $html ) {
		$html = is_string( $html ) ? $html : '';

		return current_user_can( 'unfiltered_html' )
			? $html
			: wp_kses_post( $html );
	}

	/**
	 * Sanitize builder CSS.
	 *
	 * @since 1.0.0
	 * @param mixed $css Raw CSS.
	 * @return string
	 */
	protected function sanitize_builder_css_value( $css ) {
		return trim( wp_strip_all_tags( is_string( $css ) ? $css : '' ) );
	}

	/**
	 * Sanitize markup fragments stored in page settings.
	 *
	 * @since 1.0.0
	 * @param mixed $markup Raw markup.
	 * @return string
	 */
	protected function sanitize_builder_markup_value( $markup ) {
		$markup = is_string( $markup ) ? $markup : '';

		return current_user_can( 'unfiltered_html' )
			? $markup
			: wp_kses_post( $markup );
	}

	/**
	 * Sanitize a map of HTML attributes.
	 *
	 * @since 1.0.0
	 * @param mixed $attributes Raw attribute map.
	 * @return array
	 */
	protected function sanitize_builder_attribute_values( $attributes ) {
		if ( ! is_array( $attributes ) ) {
			return array();
		}

		$sanitized_attributes = array();

		foreach ( $attributes as $name => $attribute_value ) {
			$name = sanitize_key( (string) $name );

			if ( '' === $name || ! is_scalar( $attribute_value ) ) {
				continue;
			}

			$sanitized_attributes[ $name ] = sanitize_text_field( (string) $attribute_value );
		}

		return $sanitized_attributes;
	}

	/**
	 * Sanitize a page settings array while preserving trusted import markup.
	 *
	 * @since 1.0.0
	 * @param mixed $settings Raw page settings.
	 * @return array
	 */
	protected function sanitize_builder_page_settings_array( $settings ) {
		if ( ! is_array( $settings ) ) {
			return array();
		}

		foreach ( $settings as $key => $value ) {
			switch ( $key ) {
				case 'importedHeadMarkup':
				case 'importedFooterMarkup':
					$settings[ $key ] = $this->sanitize_builder_markup_value( $value );
					break;

				case 'importedHtmlAttributes':
				case 'importedBodyAttributes':
					$settings[ $key ] = $this->sanitize_builder_attribute_values( $value );
					break;

				default:
					if ( is_array( $value ) ) {
						$settings[ $key ] = $this->sanitize_builder_page_settings_array( $value );
					} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
						$settings[ $key ] = $value;
					} elseif ( is_scalar( $value ) ) {
						$settings[ $key ] = sanitize_text_field( (string) $value );
					} else {
						unset( $settings[ $key ] );
					}
			}
		}

		return $settings;
	}

	/**
	 * Sanitize page settings for post meta storage.
	 *
	 * @since 1.0.0
	 * @param mixed $value Raw page settings value.
	 * @return mixed
	 */
	protected function sanitize_builder_page_settings_meta_value( $value ) {
		$decoded  = $value;
		$was_json = false;

		if ( is_string( $value ) ) {
			$decoded_value = json_decode( $value, true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded_value ) ) {
				$decoded  = $decoded_value;
				$was_json = true;
			}
		}

		if ( ! is_array( $decoded ) ) {
			return is_string( $value ) ? sanitize_text_field( $value ) : $value;
		}

		$decoded = $this->sanitize_builder_page_settings_array( $decoded );

		return $was_json ? wp_json_encode( $decoded ) : $decoded;
	}

	/**
	 * Normalize a post status value for builder page writes.
	 *
	 * @since 1.0.0
	 * @param mixed  $status  Requested post status.
	 * @param string $default Default status.
	 * @return string
	 */
	protected function normalize_builder_post_status( $status, $default = 'draft' ) {
		$allowed_statuses = array( 'draft', 'publish', 'private', 'pending' );
		$status           = sanitize_key( (string) $status );

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			return $default;
		}

		if ( 'publish' === $status && ! current_user_can( 'publish_pages' ) ) {
			return 'draft';
		}

		if ( 'private' === $status && ! current_user_can( 'publish_pages' ) ) {
			return 'draft';
		}

		return $status;
	}

	/**
	 * Save common builder meta data (blocks, HTML, custom CSS) from request parameters.
	 *
	 * @since 1.0.0
	 * @param int   $post_id The ID of the post to update.
	 * @param array $params  The request parameters.
	 * @return void
	 */
	protected function save_builder_meta( $post_id, $params ) {
		update_post_meta( $post_id, '_amb_builder_enabled', 1 );

		// Save blocks.
		if ( isset( $params['blocks'] ) ) {
			$blocks = is_array( $params['blocks'] ) ? wp_json_encode( $params['blocks'] ) : sanitize_text_field( $params['blocks'] );
			update_post_meta( $post_id, '_amb_blocks', wp_slash( $blocks ) );
		}

		// Save rendered HTML with proper sanitization based on capabilities.
		if ( isset( $params['renderedHtml'] ) ) {
			$html = $this->sanitize_builder_html_value( $params['renderedHtml'] );
			update_post_meta( $post_id, '_amb_rendered_html', $html );
		}

		// Save custom CSS if present (common in pages).
		if ( isset( $params['customCss'] ) ) {
			$css = $this->sanitize_builder_css_value( $params['customCss'] );
			update_post_meta( $post_id, '_amb_custom_css', $css );
		}
	}
}
