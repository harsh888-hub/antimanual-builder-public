<?php
/**
 * Inertia.js adapter for WordPress.
 *
 * Provides Inertia.js protocol support within
 * the WordPress admin, handling both initial full-page
 * loads and subsequent XHR-based Inertia visits.
 *
 * @package Antimanual_Builder
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_Admin_Inertia
 *
 * Lightweight Inertia.js server-side adapter for WordPress.
 *
 * @since 1.0.0
 */
class AMB_Admin_Inertia {

	/**
	 * Shared props available to all pages.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	private static $shared = array();

	/**
	 * Asset version for cache busting.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	private static $version = '';

	/**
	 * Share props globally with all Inertia responses.
	 *
	 * @since  1.0.0
	 * @param  string|array $key   Key or associative array of key/value pairs.
	 * @param  mixed        $value Value (ignored when $key is array).
	 * @return void
	 */
	public static function share( $key, $value = null ) {
		if ( is_array( $key ) ) {
			self::$shared = array_merge( self::$shared, $key );
		} else {
			self::$shared[ $key ] = $value;
		}
	}

	/**
	 * Get all shared props.
	 *
	 * @since  1.0.0
	 * @return array
	 */
	public static function get_shared() {
		return self::$shared;
	}

	/**
	 * Set the asset version string.
	 *
	 * @since  1.0.0
	 * @param  string $version Version string.
	 * @return void
	 */
	public static function version( $version ) {
		self::$version = $version;
	}

	/**
	 * Check if the current request is an Inertia request.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	public static function is_inertia_request() {
		return isset( $_SERVER['HTTP_X_INERTIA'] ) && $_SERVER['HTTP_X_INERTIA'] === 'true';
	}

	/**
	 * Render an Inertia response.
	 *
	 * On initial page loads, outputs a div with a data-page attribute
	 * containing the serialized page object. On Inertia XHR visits,
	 * returns a JSON response conforming to the Inertia protocol.
	 *
	 * @since  1.0.0
	 * @param  string $component Page component name.
	 * @param  array  $props     Props to pass to the component.
	 * @return void
	 */
	public static function render( $component, $props = array() ) {
		$page = array(
			'component' => $component,
			'props'     => array_merge( self::$shared, $props ),
			'url'       => self::get_current_url(),
			'version'   => self::$version,
		);

		if ( self::is_inertia_request() ) {
			self::send_json_response( $page );
			return;
		}

		// Initial full-page load: render root element with data-page attribute.
		echo '<div id="app" data-page="' . esc_attr( wp_json_encode( $page ) ) . '"></div>';
	}

	/**
	 * Send a JSON Inertia response and terminate.
	 *
	 * @since  1.0.0
	 * @param  array $page The Inertia page object.
	 * @return void
	 */
	private static function send_json_response( $page ) {
		// Set Inertia response headers.
		header( 'Content-Type: application/json' );
		header( 'X-Inertia: true' );
		header( 'Vary: X-Inertia' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_json_encode( $page );

		// Stop WordPress from rendering anything else.
		exit;
	}

	/**
	 * Get the current request URL (relative path + query string).
	 *
	 * @since  1.0.0
	 * @return string
	 */
	private static function get_current_url() {
		$url = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return $url;
	}

	/**
	 * Redirect with Inertia support.
	 *
	 * For Inertia requests, sends a 409 Conflict response to force
	 * a full page reload when the asset version has changed.
	 *
	 * @since  1.0.0
	 * @param  string $url URL to redirect to.
	 * @return void
	 */
	public static function location( $url ) {
		$url = esc_url_raw( $url );

		if ( self::is_inertia_request() ) {
			header( 'X-Inertia-Location: ' . $url, true, 409 );
			exit;
		}
		wp_safe_redirect( $url );
		exit;
	}
}
