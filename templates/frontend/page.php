<?php
/**
 * Frontend page template.
 *
 * Renders builder pages in a clean, full-width layout
 * without theme header/footer chrome.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id       = get_the_ID();
$rendered_html = get_post_meta( $post_id, '_amb_rendered_html', true );
$custom_css    = get_post_meta( $post_id, '_amb_custom_css', true );
$page_settings = json_decode( (string) get_post_meta( $post_id, '_amb_page_settings', true ), true );
$imported_head_markup = is_array( $page_settings ) && ! empty( $page_settings['importedHeadMarkup'] )
	? (string) $page_settings['importedHeadMarkup']
	: '';
$imported_footer_markup = is_array( $page_settings ) && ! empty( $page_settings['importedFooterMarkup'] )
	? (string) $page_settings['importedFooterMarkup']
	: '';
$imported_html_attributes = is_array( $page_settings ) && ! empty( $page_settings['importedHtmlAttributes'] ) && is_array( $page_settings['importedHtmlAttributes'] )
	? $page_settings['importedHtmlAttributes']
	: array();
$imported_body_attributes = is_array( $page_settings ) && ! empty( $page_settings['importedBodyAttributes'] ) && is_array( $page_settings['importedBodyAttributes'] )
	? $page_settings['importedBodyAttributes']
	: array();

$render_attribute_string = static function( $attributes, $excluded_names = array() ) {
	if ( ! is_array( $attributes ) ) {
		return '';
	}

	$attribute_pairs = array();

	foreach ( $attributes as $name => $value ) {
		$name = sanitize_key( (string) $name );

		if ( '' === $name || in_array( $name, $excluded_names, true ) || ! is_string( $value ) || '' === trim( $value ) ) {
			continue;
		}

		$attribute_pairs[] = sprintf(
			'%s="%s"',
			esc_attr( $name ),
			esc_attr( $value )
		);
	}

	return implode( ' ', $attribute_pairs );
};

$imported_body_classes = array();
if ( ! empty( $imported_body_attributes['class'] ) && is_string( $imported_body_attributes['class'] ) ) {
	$imported_body_classes = preg_split( '/\s+/', trim( $imported_body_attributes['class'] ) );
	$imported_body_classes = array_values( array_filter( array_map( 'sanitize_html_class', $imported_body_classes ) ) );
}

$html_attribute_string = $render_attribute_string( $imported_html_attributes, array( 'lang', 'dir' ) );
$body_attribute_string = $render_attribute_string( $imported_body_attributes, array( 'class' ) );

// Backward compat: extract any <style> blocks still embedded in rendered HTML.
if ( ! empty( $rendered_html ) ) {
	if ( preg_match( '/<style[^>]*>.*?<\/style>/si', $rendered_html ) ) {
		$rendered_html = preg_replace( '/<style[^>]*>.*?<\/style>/si', '', $rendered_html );
	}
	$rendered_html = trim( $rendered_html );
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute string is assembled from sanitized names and escaped values. ?><?php echo $html_attribute_string ? ' ' . $html_attribute_string : ''; ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php wp_title( '|', true, 'right' ); ?><?php bloginfo( 'name' ); ?></title>
	<?php
	if ( ! empty( $imported_head_markup ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Import markup is sanitized on save for non-unfiltered users.
		echo $imported_head_markup;
	}
	?>
	<?php wp_head(); ?>
	<?php if ( ! empty( $custom_css ) ) : ?>
		<style id="amb-inline-custom-css">
			<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Custom CSS is sanitized on save. ?>
			<?php echo $custom_css; ?>
		</style>
	<?php endif; ?>
</head>
<body <?php body_class( array_merge( array( 'amb-page', 'amb-frontend' ), $imported_body_classes ) ); ?><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute string is assembled from sanitized names and escaped values. ?><?php echo $body_attribute_string ? ' ' . $body_attribute_string : ''; ?>>
	<?php wp_body_open(); ?>

	<main class="amb-main" id="amb-content">
		<?php
		if ( ! empty( $rendered_html ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is sanitized on save.
			echo $rendered_html;
		} else {
			// Fall back to block rendering.
			$plugin = AMB_Antimanual_Builder::get_instance();
			if ( $plugin->get_renderer() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $plugin->get_renderer()->render_page( $post_id );
			}
		}
		?>
	</main>

	<?php
	if ( ! empty( $imported_footer_markup ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Import markup is sanitized on save for non-unfiltered users.
		echo $imported_footer_markup;
	}
	?>

	<?php wp_footer(); ?>
</body>
</html>
