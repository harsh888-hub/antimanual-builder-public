<?php
/**
 * Antimanual AI settings bridge.
 *
 * Provides a single place for AM Builder to read provider settings from the
 * parent Antimanual plugin.
 *
 * @package Antimanual_Builder
 * @since   1.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_Antimanual_Ai_Provider
 */
class AMB_Antimanual_Ai_Provider {

	/**
	 * Default OpenAI model.
	 *
	 * @var string
	 */
	const DEFAULT_OPENAI_MODEL = 'gpt-5-mini';

	/**
	 * Default Gemini model.
	 *
	 * @var string
	 */
	const DEFAULT_GEMINI_MODEL = 'gemini-3-flash-preview';

	/**
	 * Check whether Antimanual AI settings are available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return AMB_Antimanual_Knowledge_Base::is_active() && function_exists( 'atml_option' );
	}

	/**
	 * Get the active AI provider.
	 *
	 * @return string
	 */
	public static function get_provider() {
		if ( ! self::is_available() ) {
			return 'openai';
		}

		$provider = sanitize_key( (string) atml_option( 'last_active_provider', 'openai' ) );

		return 'gemini' === $provider ? 'gemini' : 'openai';
	}

	/**
	 * Get the configured model for a provider.
	 *
	 * @param string $provider Provider name.
	 * @return string
	 */
	public static function get_model( $provider = '' ) {
		$provider = self::normalize_provider( $provider ? $provider : self::get_provider() );

		if ( 'gemini' === $provider ) {
			if ( self::is_available() && function_exists( 'atml_get_gemini_configs' ) ) {
				$configs = atml_get_gemini_configs();
				if ( ! empty( $configs['response_model'] ) && is_string( $configs['response_model'] ) ) {
					return sanitize_text_field( $configs['response_model'] );
				}
			}

			if ( self::is_available() ) {
				$saved_model = atml_option( 'gemini_response_model', self::DEFAULT_GEMINI_MODEL );
				if ( ! empty( $saved_model ) && is_string( $saved_model ) ) {
					return sanitize_text_field( $saved_model );
				}
			}

			return self::DEFAULT_GEMINI_MODEL;
		}

		if ( self::is_available() && function_exists( 'atml_get_openai_configs' ) ) {
			$configs = atml_get_openai_configs();
			if ( ! empty( $configs['response_model'] ) && is_string( $configs['response_model'] ) ) {
				return sanitize_text_field( $configs['response_model'] );
			}
		}

		if ( function_exists( 'atml_get_default_openai_response_model' ) ) {
			return sanitize_text_field( (string) atml_get_default_openai_response_model() );
		}

		return self::DEFAULT_OPENAI_MODEL;
	}

	/**
	 * Get the API key for a provider.
	 *
	 * @param string $provider Provider name.
	 * @return string
	 */
	public static function get_api_key( $provider = '' ) {
		if ( ! self::is_available() ) {
			return '';
		}

		$provider = self::normalize_provider( $provider ? $provider : self::get_provider() );
		$option   = 'gemini' === $provider ? 'gemini_api_key' : 'openai_api_key';
		$api_key  = atml_option( $option, '' );

		return is_string( $api_key ) ? trim( $api_key ) : '';
	}

	/**
	 * Check whether a provider has an API key.
	 *
	 * @param string $provider Provider name.
	 * @return bool
	 */
	public static function has_api_key( $provider = '' ) {
		return '' !== self::get_api_key( $provider );
	}

	/**
	 * Get runtime settings keyed like the legacy builder settings array.
	 *
	 * @return array
	 */
	public static function get_runtime_settings() {
		return array(
			'provider'       => self::get_provider(),
			'openai_api_key' => self::get_api_key( 'openai' ),
			'gemini_api_key' => self::get_api_key( 'gemini' ),
			'openai_model'   => self::get_model( 'openai' ),
			'gemini_model'   => self::get_model( 'gemini' ),
		);
	}

	/**
	 * Build a safe payload for React admin/editor surfaces.
	 *
	 * @return array
	 */
	public static function get_settings_payload() {
		$provider   = self::get_provider();
		$openai_key = self::get_api_key( 'openai' );
		$gemini_key = self::get_api_key( 'gemini' );

		return array(
			'installed'     => AMB_Antimanual_Knowledge_Base::is_installed(),
			'active'        => AMB_Antimanual_Knowledge_Base::is_active(),
			'available'     => self::is_available(),
			'provider'      => $provider,
			'model'         => self::get_model( $provider ),
			'openaiModel'   => self::get_model( 'openai' ),
			'geminiModel'   => self::get_model( 'gemini' ),
			'openaiKey'     => '' !== $openai_key,
			'geminiKey'     => '' !== $gemini_key,
			'openaiPartial' => self::mask_api_key( $openai_key ),
			'geminiPartial' => self::mask_api_key( $gemini_key ),
			'hasApiKey'     => self::has_api_key(),
			'adminUrl'      => esc_url_raw( admin_url( 'admin.php?page=antimanual' ) ),
			'message'       => self::get_status_message(),
		);
	}

	/**
	 * Get the current dependency/configuration status message.
	 *
	 * @return string
	 */
	public static function get_status_message() {
		if ( ! AMB_Antimanual_Knowledge_Base::is_installed() ) {
			return __( 'Install and activate Antimanual to configure AI for AM Builder.', 'antimanual-builder' );
		}

		if ( ! AMB_Antimanual_Knowledge_Base::is_active() ) {
			return __( 'Activate Antimanual to configure AI for AM Builder.', 'antimanual-builder' );
		}

		if ( ! self::has_api_key() ) {
			return __( 'Configure an AI provider in Antimanual to use AM Builder AI features.', 'antimanual-builder' );
		}

		return __( 'AI provider settings are managed in the Antimanual plugin.', 'antimanual-builder' );
	}

	/**
	 * Normalize provider names.
	 *
	 * @param string $provider Provider name.
	 * @return string
	 */
	private static function normalize_provider( $provider ) {
		return 'gemini' === sanitize_key( (string) $provider ) ? 'gemini' : 'openai';
	}

	/**
	 * Mask an API key for safe display.
	 *
	 * @param string $api_key API key.
	 * @return string
	 */
	private static function mask_api_key( $api_key ) {
		$api_key = is_string( $api_key ) ? trim( $api_key ) : '';

		if ( '' === $api_key ) {
			return '';
		}

		if ( strlen( $api_key ) <= 4 ) {
			return str_repeat( '•', strlen( $api_key ) );
		}

		return str_repeat( '•', strlen( $api_key ) - 4 ) . substr( $api_key, -4 );
	}
}