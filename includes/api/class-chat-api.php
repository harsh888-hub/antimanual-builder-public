<?php
/**
 * Streaming Chat REST API controller.
 *
 * Provides an SSE-based chat endpoint that streams build stages, assistant
 * commentary, and generated page output so the editor can respond with page
 * generation, page refinement, or conversational answers as needed.
 *
 * @package Antimanual_Builder
 * @since   1.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMB_API_Chat_Api
 *
 * Streaming chat support for OpenAI and Gemini.
 *
 * @since 1.1.0
 */
class AMB_API_Chat_Api {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private $namespace = 'amb/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		// No-op — initialization handled lazily.
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/ai/chat',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'chat_stream' ),
				'permission_callback' => array( $this, 'can_use_ai' ),
				'args'                => array(
					'messages' => array(
						'required' => true,
						'type'     => 'array',
					),
					'currentHtml' => array(
						'default' => '',
					),
					'currentCss' => array(
						'default' => '',
					),
					'selectedSection' => array(
						'default' => '',
					),
					'pageTitle' => array(
						'default' => '',
					),
					'pageSettings' => array(
						'default' => array(),
					),
					'provider' => array(
						'default' => '',
					),
					'model' => array(
						'default' => '',
					),
					'useKnowledgeBase' => array(
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @return bool|WP_Error
	 */
	public function can_use_ai() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to use AI features.', 'antimanual-builder' ),
				array( 'status' => 403 )
			);
		}

		$api_key = AMB_Antimanual_Ai_Provider::get_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'ai_not_configured',
				AMB_Antimanual_Ai_Provider::get_status_message(),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Get the API key for the specified provider.
	 *
	 * @param array  $settings AI settings.
	 * @param string $provider Provider name.
	 * @return string API key.
	 */
	private function get_api_key( $settings, $provider ) {
		unset( $settings );

		return AMB_Antimanual_Ai_Provider::get_api_key( $provider );
	}



	/**
	 * Build the system prompt for the chat.
	 *
	 * @param string $current_html Existing page HTML (if any).
	 * @param string $current_css  Existing page CSS (if any).
	 * @param string $page_title   Current page title.
	 * @param string $selected     Selected section HTML (if any).
	 * @param array  $page_settings Dynamic page settings (colors, style, etc).
	 * @return string System prompt.
	 */
	private function build_system_prompt( $current_html, $current_css, $page_title, $selected, $page_settings = array(), $knowledge_base_context = '' ) {
		$current_css   = $this->prepare_context_snippet( $current_css, 4000 );
		$current_html  = $this->prepare_context_snippet( $current_html, 12000 );
		$selected      = $this->prepare_context_snippet( $selected, 5000 );

		$prompt  = "You are the AI Assistant for Antimanual Builder (WordPress).\n";
		$prompt .= "You must answer the user's request conversationally. If the user asks to generate a new page, build something new, or modify/refine the current page, you MUST output the complete raw HTML code inside a single markdown code block starting with ```html and ending with ```.\n";
		$prompt .= "Rules: All CSS MUST be in a <style> block at the top of the HTML. Use class-based styling (no inline styles). Semantic HTML5 + data-amb-section attributes on each major section.\n\n";

		$prompt .= "## Step Markers (REQUIRED for page generation/refinement)\n";
		$prompt .= "Before the code block, you MUST output a numbered plan like:\n";
		$prompt .= "[STEP: Planning page structure]\n";
		$prompt .= "[STEP: Designing hero section with gradient background]\n";
		$prompt .= "[STEP: Building features grid with icon cards]\n";
		$prompt .= "[STEP: Adding responsive styles and media queries]\n";
		$prompt .= "[STEP: Final polish and accessibility]\n";
		$prompt .= "Then output the code block. Each [STEP: ...] line MUST be specific to the request (not generic). These steps tell the user what you're working on in real-time.\n\n";

		// --- Page Settings (user-configured preferences from the sidebar) ---
		$this->append_page_settings_prompt( $prompt, $page_settings );

		if ( ! empty( $current_html ) || ! empty( $current_css ) ) {
			$prompt .= "## Current Page Context\n";
			if ( ! empty( $page_title ) ) {
				$prompt .= "Title: " . $page_title . "\n";
			}
			if ( ! empty( $current_css ) ) {
				$prompt .= "CSS: " . $this->minify_content( $current_css ) . "\n";
			}
			if ( ! empty( $current_html ) ) {
				$prompt .= "HTML: " . $this->minify_content( $current_html ) . "\n";
			}
			if ( ! empty( $selected ) ) {
				$prompt .= "Selected section: " . $this->minify_content( $selected ) . "\n";
			}
		}

		if ( ! empty( $knowledge_base_context ) ) {
			$prompt .= "\n## Attached Knowledge Base Context\n";
			$prompt .= $knowledge_base_context . "\n";
			$prompt .= "Use this context when it is relevant to the user's request. Prefer it over making up brand details, product claims, technical specifics, or documentation facts.\n";
		}

		return $prompt;
	}

	/**
	 * Get the latest user-authored message content.
	 *
	 * @param array $messages Conversation messages.
	 * @return string
	 */
	private function get_latest_user_message( $messages ) {
		if ( ! is_array( $messages ) ) {
			return '';
		}

		for ( $index = count( $messages ) - 1; $index >= 0; $index-- ) {
			$message = $messages[ $index ];

			if (
				is_array( $message ) &&
				isset( $message['role'], $message['content'] ) &&
				'user' === $message['role'] &&
				is_string( $message['content'] )
			) {
				return $message['content'];
			}
		}

		return '';
	}

	/**
	 * Append structured page settings instructions to the system prompt.
	 *
	 * Translates the raw page settings (tone, sections, brand colors,
	 * design style, font preset) into clear natural-language instructions
	 * that the AI can act on.
	 *
	 * @param string $prompt        System prompt (passed by reference).
	 * @param array  $page_settings Page settings from the sidebar.
	 * @return void
	 */
	private function append_page_settings_prompt( &$prompt, $page_settings ) {
		if ( empty( $page_settings ) || ! is_array( $page_settings ) ) {
			return;
		}

		$has_settings = false;

		// Content tone.
		$tone = ! empty( $page_settings['tone'] ) ? sanitize_text_field( $page_settings['tone'] ) : '';
		if ( $tone && 'professional' !== $tone ) {
			if ( ! $has_settings ) {
				$prompt .= "## User Preferences\n";
				$has_settings = true;
			}
			$prompt .= "- **Content Tone:** Write all text in a " . $tone . " tone.\n";
		}


		// Brand colors.
		$brand_colors = ! empty( $page_settings['brandColors'] ) && is_array( $page_settings['brandColors'] )
			? $page_settings['brandColors']
			: array();
		$has_brand_colors = ! empty( $brand_colors['primary'] ) || ! empty( $brand_colors['secondary'] ) || ! empty( $brand_colors['accent'] ) || ! empty( $brand_colors['background'] );
		if ( $has_brand_colors ) {
			if ( ! $has_settings ) {
				$prompt .= "## User Preferences\n";
				$has_settings = true;
			}
			$prompt .= "- **Brand Colors:** Use these colors throughout the design:";
			if ( ! empty( $brand_colors['primary'] ) ) {
				$prompt .= ' Primary=' . sanitize_text_field( $brand_colors['primary'] );
			}
			if ( ! empty( $brand_colors['secondary'] ) ) {
				$prompt .= ' Secondary=' . sanitize_text_field( $brand_colors['secondary'] );
			}
			if ( ! empty( $brand_colors['background'] ) ) {
				$prompt .= ' Background=' . sanitize_text_field( $brand_colors['background'] );
			}
			if ( ! empty( $brand_colors['accent'] ) ) {
				$prompt .= ' Accent=' . sanitize_text_field( $brand_colors['accent'] );
			}
			$prompt .= "\n";
		}

		// Design system preset.
		$design_style = ! empty( $page_settings['designStyle'] ) ? sanitize_text_field( $page_settings['designStyle'] ) : '';
		if ( $design_style ) {
			if ( ! $has_settings ) {
				$prompt .= "## User Preferences\n";
				$has_settings = true;
			}
			$style_descriptions = array(
				'neo-brutalism'     => 'chunky borders, offset shadows, punchy color blocks, oversized type, bold geometry, high contrast',
				'neumorphism'       => 'soft surfaces, subtle depth via inner/outer shadows, rounded cards, muted palette, tactile controls',
				'glassmorphism'     => 'frosted glass panels, layered translucency, background blur, airy gradients, glowing borders',
				'editorial-minimal' => 'structured typography, restrained color, generous whitespace, calm rhythm, serif accents, refined layout',
				'retro'             => 'nostalgic 90s/Y2K aesthetic, pixel-adjacent borders, retro color palettes (purple, orange, teal), blocky decorative text, checkered or star motifs, chunky shadows with warm offset colors',
				'flat-design'       => 'clean flat surfaces with no gradients or shadows, bold solid fills, simple geometric shapes, strong typography, minimal decoration, vibrant but limited color palette',
			);
			$style_desc = isset( $style_descriptions[ $design_style ] ) ? $style_descriptions[ $design_style ] : $design_style;
			$prompt .= "- **Design System:** " . ucfirst( $design_style ) . " — " . $style_desc . ".\n";
		}

		// Border radius / corner style.
		$border_radius = ! empty( $page_settings['borderRadius'] ) ? sanitize_text_field( $page_settings['borderRadius'] ) : '';
		if ( $border_radius ) {
			if ( ! $has_settings ) {
				$prompt .= "## User Preferences\n";
				$has_settings = true;
			}
			$radius_map = array(
				'sharp'   => 'Use sharp corners (0–2px border-radius) on buttons, cards, inputs, and sections',
				'rounded' => 'Use moderate rounded corners (8–12px border-radius) on buttons, cards, inputs, and sections',
				'pill'    => 'Use pill-shaped/fully-rounded corners (24–999px border-radius) on buttons, cards, inputs, and sections',
			);
			$radius_desc = isset( $radius_map[ $border_radius ] ) ? $radius_map[ $border_radius ] : $border_radius;
			$prompt .= "- **Corner Style:** " . $radius_desc . ".\n";
		}

		// Layout density / spacing.
		$spacing = ! empty( $page_settings['spacing'] ) ? sanitize_text_field( $page_settings['spacing'] ) : '';
		if ( $spacing ) {
			if ( ! $has_settings ) {
				$prompt .= "## User Preferences\n";
				$has_settings = true;
			}
			$spacing_map = array(
				'compact'  => 'Use compact spacing — tight padding (12–16px), small margins, dense information layout',
				'balanced' => 'Use balanced spacing — comfortable padding (24–32px), standard margins, clean breathing room',
				'airy'     => 'Use airy/generous spacing — large padding (48–80px), wide margins, luxury whitespace feel',
			);
			$spacing_desc = isset( $spacing_map[ $spacing ] ) ? $spacing_map[ $spacing ] : $spacing;
			$prompt .= "- **Layout Density:** " . $spacing_desc . ".\n";
		}

		// Individual heading/body font overrides (take priority over preset).
		$heading_font = ! empty( $page_settings['headingFont'] ) ? sanitize_text_field( $page_settings['headingFont'] ) : '';
		$body_font    = ! empty( $page_settings['bodyFont'] ) ? sanitize_text_field( $page_settings['bodyFont'] ) : '';

		if ( $heading_font || $body_font ) {
			if ( ! $has_settings ) {
				$prompt .= "## User Preferences\n";
				$has_settings = true;
			}
			$parts = array();
			if ( $heading_font ) {
				$parts[] = $heading_font . ' for headings';
			}
			if ( $body_font ) {
				$parts[] = $body_font . ' for body text';
			}
			$prompt .= "- **Typography:** Use " . implode( ', ', $parts ) . ". Import via Google Fonts @import in the <style> block.\n";
		} elseif ( ! empty( $page_settings['fontPreset'] ) ) {
			// Font pairing preset (fallback when no individual fonts set).
			$font_preset = sanitize_text_field( $page_settings['fontPreset'] );
			if ( ! $has_settings ) {
				$prompt .= "## User Preferences\n";
				$has_settings = true;
			}
			$font_map = array(
				'poppins-inter'     => 'Poppins for headings, Inter for body text',
				'playfair-lato'     => 'Playfair Display for headings, Lato for body text',
				'outfit-dm'         => 'Outfit for headings, DM Sans for body text',
				'space-mono'        => 'Space Mono for headings, Work Sans for body text',
				'raleway-opensans'  => 'Raleway for headings, Open Sans for body text',
			);
			$font_desc = isset( $font_map[ $font_preset ] ) ? $font_map[ $font_preset ] : $font_preset;
			$prompt .= "- **Typography:** Use " . $font_desc . ". Import via Google Fonts @import in the <style> block.\n";
		}

		if ( $has_settings ) {
			$prompt .= "\n";
		}
	}

	/**
	 * Minify content (HTML or CSS) by stripping whitespace and comments.
	 *
	 * @param string $content Raw content.
	 * @return string Minified content.
	 */
	private function minify_content( $content ) {
		if ( empty( $content ) ) {
			return '';
		}

		// Remove comments.
		$content = preg_replace( '/\/\*.*?\*\//s', '', $content );
		$content = preg_replace( '/<!--.*?-->/s', '', $content );

		// Compress whitespace.
		$content = preg_replace( '/\s+/S', ' ', $content );

		return trim( $content );
	}

	/**
	 * Minify and trim large prompt context blocks.
	 *
	 * @param string $content    Raw content.
	 * @param int    $max_length Maximum number of characters to keep.
	 * @return string
	 */
	private function prepare_context_snippet( $content, $max_length ) {
		$content = $this->minify_content( $content );

		if ( empty( $content ) || strlen( $content ) <= $max_length ) {
			return $content;
		}

		return substr( $content, 0, $max_length ) . ' ... [truncated for speed]';
	}

	/**
	 * Keep only the most recent conversation turns for provider requests.
	 *
	 * @param array $messages Conversation history.
	 * @param int   $limit    Max messages to keep.
	 * @return array
	 */
	private function get_recent_messages( $messages, $limit = 8 ) {
		if ( ! is_array( $messages ) ) {
			return array();
		}

		return array_slice( $messages, 0 - absint( $limit ) );
	}

	/**
	 * Emit a simple keepalive heartbeat while waiting on the AI provider.
	 *
	 * This sends a lightweight SSE status event so the browser connection
	 * does not time out during long AI generations. No fake commentary.
	 *
	 * @param array $state Streaming state.
	 * @param bool  $force Whether to force an immediate heartbeat.
	 * @return void
	 */
	private function emit_keepalive_heartbeat( &$state, $force = false ) {
		$now = microtime( true );

		if ( ! $force && ( $now - $state['last_heartbeat_at'] ) < 8.0 ) {
			return;
		}

		$this->send_sse_event(
			'status',
			array(
				'step'    => 'calling_ai',
				'message' => 'Waiting for AI response...',
			)
		);

		$state['last_heartbeat_at'] = $now;
	}

	/**
	 * Process a Gemini streamed SSE event.
	 *
	 * @param string $event          SSE event text.
	 * @param array  $result         Aggregated result.
	 * @param array  $tool_call_map  Aggregated tool calls keyed by name.
	 * @param array  $state          Streaming state.
	 * @return void
	 */
	private function process_gemini_stream_event( $event, &$result, &$tool_call_map, &$state ) {
		$lines      = preg_split( '/\r?\n/', $event );
		$data_lines = array();

		foreach ( $lines as $line ) {
			if ( 0 === strpos( $line, 'data:' ) ) {
				$data_lines[] = ltrim( substr( $line, 5 ) );
			}
		}

		$payload = trim( implode( "\n", $data_lines ) );

		// If no data: lines were found, the response body may be plain JSON
		// (e.g. a 400/401/403 error from Gemini). Try to parse the raw event.
		if ( empty( $payload ) ) {
			$raw = trim( $event );
			if ( ! empty( $raw ) ) {
				$raw_decoded = json_decode( $raw, true );
				if ( is_array( $raw_decoded ) && isset( $raw_decoded['error']['message'] ) ) {
					$state['api_error_message'] = $raw_decoded['error']['message'];
				}
			}
			return;
		}

		if ( '[DONE]' === $payload ) {
			return;
		}

		$decoded = json_decode( $payload, true );

		if ( ! is_array( $decoded ) ) {
			return;
		}

		if ( isset( $decoded['error']['message'] ) ) {
			$state['api_error_message'] = $decoded['error']['message'];
			return;
		}

		if ( empty( $state['saw_first_chunk'] ) ) {
			$state['saw_first_chunk'] = true;
			$this->send_sse_event(
				'status',
				array(
					'step'    => 'calling_ai',
					'message' => 'AI is generating...',
				)
			);
		}

		$parts = isset( $decoded['candidates'][0]['content']['parts'] ) && is_array( $decoded['candidates'][0]['content']['parts'] )
			? $decoded['candidates'][0]['content']['parts']
			: array();

		foreach ( $parts as $part ) {
			if ( isset( $part['text'] ) ) {
				$chunk = $part['text'];
				$result['content'] .= $chunk;
				$this->send_sse_event( 'token', array( 'content' => $chunk ) );

				// Detect [STEP: ...] markers in the accumulated content.
				$this->detect_and_emit_steps( $result['content'], $state );
			}
		}
	}




	/**
	 * Send a progress step update.
	 *
	 * @param string $step_id Step identifier.
	 * @param string $status  Step status (pending, active, done).
	 * @param string $label   Optional label override.
	 * @param string $message Optional UI status message.
	 * @return void
	 */
	private function send_progress_update( $step_id, $status, $label = '', $message = '' ) {
		$this->send_sse_event(
			'progress_update',
			array(
				'stepId'    => sanitize_text_field( $step_id ),
				'status'    => sanitize_text_field( $status ),
				'label'     => sanitize_text_field( $label ),
				'message'   => sanitize_text_field( $message ),
				'timestamp' => round( microtime( true ) * 1000 ),
			)
		);
	}

	/**
	 * Handle SSE streaming chat request.
	 *
	 * Uses SSE for client updates while the backend coordinates provider
	 * calls and tool/function execution. Gemini is streamed incrementally
	 * so the UI keeps receiving heartbeat updates instead of appearing
	 * stuck during long generations.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return void Outputs SSE stream directly.
	 */
	public function chat_stream( $request ) {
		$messages         = $request->get_param( 'messages' );
		$current_html     = $request->get_param( 'currentHtml' ) ?: '';
		$current_css      = $request->get_param( 'currentCss' ) ?: '';
		$selected_section = $request->get_param( 'selectedSection' ) ?: '';
		$page_title       = $request->get_param( 'pageTitle' ) ?: '';
		$page_settings    = $request->get_param( 'pageSettings' ) ?: array();
		$use_kb_context   = rest_sanitize_boolean( $request->get_param( 'useKnowledgeBase' ) );
		$settings = AMB_Antimanual_Ai_Provider::get_runtime_settings();
		$provider = isset( $settings['provider'] ) ? $settings['provider'] : 'openai';
		$api_key  = $this->get_api_key( $settings, $provider );

		if ( empty( $api_key ) ) {
			$this->send_error_response( AMB_Antimanual_Ai_Provider::get_status_message() );
			return;
		}

		$knowledge_base_context = '';
		if ( $use_kb_context ) {
			$kb_query = trim(
				implode(
					"\n",
					array_filter(
						array(
							$this->get_latest_user_message( $messages ),
							$page_title,
							wp_strip_all_tags( (string) $selected_section ),
						)
					)
				)
			);
			$knowledge_base_context = AMB_Antimanual_Knowledge_Base::build_context_for_query( $kb_query );
		}

		// Build system prompt with page context.
		$system_prompt = $this->build_system_prompt(
			$current_html,
			$current_css,
			$page_title,
			$selected_section,
			$page_settings,
			$knowledge_base_context
		);

		// Determine if the user has existing page content.
		$has_existing = ! empty( $current_html ) || ! empty( $current_css );

		// Track the start time for elapsed reporting.
		$start_time = microtime( true );

		// Set up SSE headers.
		header( 'Content-Type: text/event-stream; charset=UTF-8' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		// Disable output buffering.
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		// --- Initial status ---
		$provider_label = 'openai' === $provider ? 'OpenAI' : ucfirst( $provider );
		$this->send_sse_event( 'status', array( 'step' => 'calling_ai', 'message' => 'Connecting to ' . $provider_label . '...' ) );

		$result = null;
		switch ( $provider ) {
			case 'openai':
				$result = $this->call_openai_with_tools( $api_key, $settings, $system_prompt, $messages, $has_existing );
				break;
			case 'gemini':
				$result = $this->call_gemini_with_tools( $api_key, $settings, $system_prompt, $messages, $has_existing );
				break;
			default:
				$this->send_sse_event( 'error', array( 'message' => 'Unknown AI provider: ' . $provider ) );
		}

		// Process the result.
		if ( $result && ! is_wp_error( $result ) ) {
			if ( ! empty( $result['content'] ) ) {
				$content = $result['content'];

				if ( preg_match( '/```(?:html)?\s*(<[\s\S]*?)\s*```/is', $content, $matches ) || preg_match( '/```\s*(<[\s\S]*?)\s*$/is', $content, $matches ) ) {
					$html = trim( $matches[1] );
					$tool_name = $has_existing ? 'refine_page' : 'generate_page';

					// Announce tool if not already announced during streaming.
					if ( empty( $result['tool_announced'] ) ) {
						$this->send_sse_event(
							'tool_call',
							array(
								'tool'    => $tool_name,
								'step'    => 'building',
								'message' => 'refine_page' === $tool_name
									? 'Refining your page...'
									: 'Building your page...',
								'steps'   => array(),
							)
						);
					}

					// Mark last AI step as done.
					$step_count = 0;
					if ( preg_match_all( '/\[STEP:\s*(.+?)\]/', $content, $_step_matches ) ) {
						$step_count = count( $_step_matches[1] );
					}
					if ( $step_count > 0 ) {
						$last_step_id = 'ai_step_' . ( $step_count - 1 );
						$this->send_progress_update( $last_step_id, 'done' );
					}

					$this->execute_tool( $tool_name, array(
						'html'    => $html,
						'title'   => 'Generated Page',
						'summary' => 'Page successfully created/updated.',
					) );

					// Narrate structural details of the generated HTML.
					$this->send_html_analysis_commentary( $html );

					$elapsed = round( microtime( true ) - $start_time, 1 );
					$this->send_operation_summary( $tool_name, array( 'html' => $html, 'title' => 'Generated Page', 'summary' => 'Page successfully created/updated.' ), $elapsed );

					// Send a clean assistant message (no code, no step markers).
					$clean_msg = $this->clean_assistant_message( $content );
					$this->send_sse_event( 'assistant_message', array( 'content' => $clean_msg, 'toolName' => $tool_name ) );
				} else {
					// No code block — conversational response. Clean step markers only.
					$clean_msg = $this->clean_assistant_message( $content );
					$this->send_sse_event( 'assistant_message', array( 'content' => $clean_msg, 'toolName' => 'answer_question' ) );
				}

			} else {
				$this->send_sse_event( 'assistant_message', array(
					'content' => 'I received your message but I\'m not sure how to respond. Could you describe what you\'d like me to do?',
				) );
			}
		} elseif ( is_wp_error( $result ) ) {
			$this->send_commentary( 'error', 'Encountered an error: ' . $result->get_error_message() );
			$this->send_sse_event( 'error', array( 'message' => $result->get_error_message() ) );
		}

		$this->send_sse_event( 'done', array() );
		echo "data: [DONE]\n\n";
		flush();

		exit;
	}



	/**
	 * Process an OpenAI streamed SSE event.
	 *
	 * Accumulates content and tool-call argument deltas into the result
	 * array, emitting commentary when the first chunk or tool call arrives.
	 *
	 * @since  1.1.0
	 * @param  string $event          SSE event text.
	 * @param  array  $result         Aggregated result.
	 * @param  array  $tool_call_map  Aggregated tool calls keyed by index.
	 * @param  array  $state          Streaming state.
	 * @return void
	 */
	private function process_openai_stream_event( $event, &$result, &$tool_call_map, &$state ) {
		$lines      = preg_split( '/\r?\n/', $event );
		$data_lines = array();

		foreach ( $lines as $line ) {
			if ( 0 === strpos( $line, 'data:' ) ) {
				$data_lines[] = ltrim( substr( $line, 5 ) );
			}
		}

		$payload = trim( implode( "\n", $data_lines ) );

		if ( empty( $payload ) || '[DONE]' === $payload ) {
			return;
		}

		$decoded = json_decode( $payload, true );

		if ( ! is_array( $decoded ) ) {
			return;
		}

		if ( isset( $decoded['error']['message'] ) ) {
			$state['api_error_message'] = $decoded['error']['message'];
			return;
		}

		if ( empty( $state['saw_first_chunk'] ) ) {
			$state['saw_first_chunk'] = true;
			$this->send_sse_event(
				'status',
				array(
					'step'    => 'calling_ai',
					'message' => 'AI is generating...',
				)
			);
		}

		$delta = isset( $decoded['choices'][0]['delta'] ) ? $decoded['choices'][0]['delta'] : array();

		// Accumulate content tokens.
		if ( isset( $delta['content'] ) ) {
			$chunk = $delta['content'];
			$result['content'] .= $chunk;
			$this->send_sse_event( 'token', array( 'content' => $chunk ) );

			// Detect [STEP: ...] markers in the accumulated content.
			$this->detect_and_emit_steps( $result['content'], $state );
		}
	}

	/**
	 * Call OpenAI API via cURL streaming.
	 *
	 * Uses OpenAI's streaming mode so SSE heartbeat events are emitted
	 * during the wait, preventing the UI from appearing stuck.
	 *
	 * @since  1.1.0
	 * @param  string $api_key  API key.
	 * @param  array  $settings AI settings.
	 * @param  string $system   System prompt.
	 * @param  array  $messages Conversation messages.
	 * @return array|WP_Error Result with 'content' and/or 'tool_calls'.
	 */
	private function call_openai_with_tools( $api_key, $settings, $system, $messages, $has_existing = false ) {
		$model = isset( $settings['openai_model'] ) ? $settings['openai_model'] : 'gpt-5-mini';

		// Build messages array.
		$api_messages = array(
			array( 'role' => 'system', 'content' => $system ),
		);

		$recent = $this->get_recent_messages( $messages, 10 );
		foreach ( $recent as $msg ) {
			$role = isset( $msg['role'] ) ? $msg['role'] : 'user';
			if ( in_array( $role, array( 'user', 'assistant' ), true ) ) {
				$api_messages[] = array(
					'role'    => $role,
					'content' => isset( $msg['content'] ) ? $msg['content'] : '',
				);
			}
		}

		$body = array(
			'model'       => $model,
			'messages'    => $api_messages,
			'max_tokens'  => 8000,
			'temperature' => 0.7,
			'stream'      => true,
		);

		// Prefer cURL streaming so we can emit heartbeats during the wait.
		if ( function_exists( 'curl_init' ) ) {
			return $this->call_openai_with_tools_streaming( $api_key, $body, $has_existing );
		}

		return $this->call_openai_with_tools_fallback( $api_key, $body );
	}

	/**
	 * Call OpenAI's streaming endpoint and emit live heartbeats.
	 *
	 * @since  1.1.0
	 * @param  string $api_key API key.
	 * @param  array  $body    Request body (stream: true already set).
	 * @return array|WP_Error
	 */
	private function call_openai_with_tools_streaming( $api_key, $body, $has_existing = false ) {
		$url           = 'https://api.openai.com/v1/chat/completions';
		$result        = array(
			'content'    => '',
			'tool_calls' => array(),
		);
		$tool_call_map = array();
		$state         = array(
			'started_at'          => microtime( true ),
			'last_heartbeat_at'   => 0,
			'saw_first_chunk'     => false,
			'announced_tool_call' => false,
			'api_error_message'   => '',
			'buffer'              => '',
			'ai_step_count'       => 0,
			'has_existing'        => $has_existing,
		);

		$this->emit_keepalive_heartbeat( $state, true );

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_errno,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_close -- cURL is required here for true streaming SSE callbacks, which the WP HTTP API does not expose.
		$handle = curl_init( $url );

		curl_setopt( $handle, CURLOPT_POST, true );
		curl_setopt(
			$handle,
			CURLOPT_HTTPHEADER,
			array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $api_key,
			)
		);
		curl_setopt( $handle, CURLOPT_POSTFIELDS, wp_json_encode( $body ) );
		curl_setopt( $handle, CURLOPT_RETURNTRANSFER, false );
		curl_setopt( $handle, CURLOPT_HEADER, false );
		curl_setopt( $handle, CURLOPT_TIMEOUT, 120 );
		curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 2 );
		curl_setopt( $handle, CURLOPT_BUFFERSIZE, 1024 );
		curl_setopt( $handle, CURLOPT_NOPROGRESS, false );

		$write_callback = function ( $curl_handle, $chunk ) use ( &$result, &$tool_call_map, &$state ) {
			$state['buffer'] .= str_replace( "\r\n", "\n", $chunk );

			while ( false !== strpos( $state['buffer'], "\n\n" ) ) {
				$delimiter       = strpos( $state['buffer'], "\n\n" );
				$event           = substr( $state['buffer'], 0, $delimiter );
				$state['buffer'] = substr( $state['buffer'], $delimiter + 2 );
				$this->process_openai_stream_event( $event, $result, $tool_call_map, $state );
			}

			return strlen( $chunk );
		};

		curl_setopt( $handle, CURLOPT_WRITEFUNCTION, $write_callback );

		$progress_callback = function () use ( &$state ) {
			$this->emit_keepalive_heartbeat( $state );
			return 0;
		};

		if ( defined( 'CURLOPT_XFERINFOFUNCTION' ) ) {
			curl_setopt( $handle, CURLOPT_XFERINFOFUNCTION, $progress_callback );
		} else {
			curl_setopt( $handle, CURLOPT_PROGRESSFUNCTION, $progress_callback );
		}

		$success   = curl_exec( $handle );
		$http_code = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
		$error_no  = curl_errno( $handle );
		$error_msg = curl_error( $handle );
		curl_close( $handle );
		// phpcs:enable

		// Process any remaining buffer.
		if ( ! empty( trim( $state['buffer'] ) ) ) {
			$this->process_openai_stream_event( $state['buffer'], $result, $tool_call_map, $state );
		}

		if ( ! empty( $state['api_error_message'] ) ) {
			return new \WP_Error( 'ai_error', $state['api_error_message'], array( 'status' => 500 ) );
		}

		if ( false === $success || 0 !== $error_no ) {
			$message = ! empty( $error_msg ) ? $error_msg : 'OpenAI request failed.';
			if ( 28 === $error_no ) {
				$message = 'OpenAI timed out. Try again or use a faster model.';
			}

			return new \WP_Error( 'ai_error', $message, array( 'status' => 500 ) );
		}

		if ( $http_code >= 400 ) {
			return new \WP_Error( 'ai_error', 'OpenAI returned HTTP ' . $http_code . '.', array( 'status' => $http_code ) );
		}

		// Convert accumulated tool call map to final format.
		foreach ( $tool_call_map as $tc ) {
			$fn_args = json_decode( $tc['args_str'], true );
			if ( ! is_array( $fn_args ) ) {
				$fn_args = array();
			}

			$result['tool_calls'][] = array(
				'name' => $tc['name'],
				'args' => $fn_args,
			);
		}

		$result['tool_announced'] = ! empty( $state['announced_tool_call'] );

		return $result;
	}

	/**
	 * Fallback OpenAI call when cURL streaming is not available.
	 *
	 * Uses wp_remote_post without streaming. Less responsive but functional.
	 *
	 * @since  1.1.0
	 * @param  string $api_key API key.
	 * @param  array  $body    Request body.
	 * @return array|WP_Error
	 */
	private function call_openai_with_tools_fallback( $api_key, $body ) {
		// Disable streaming for the fallback.
		$body['stream'] = false;

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout'   => 180,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$resp_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $resp_body['error'] ) ) {
			return new \WP_Error(
				'ai_error',
				isset( $resp_body['error']['message'] ) ? $resp_body['error']['message'] : 'AI API error.',
				array( 'status' => 500 )
			);
		}

		$result = array(
			'content'    => '',
			'tool_calls' => array(),
		);

		if ( isset( $resp_body['choices'][0]['message'] ) ) {
			$msg = $resp_body['choices'][0]['message'];

			if ( ! empty( $msg['content'] ) ) {
				$result['content'] = $msg['content'];
			}

			if ( ! empty( $msg['tool_calls'] ) ) {
				foreach ( $msg['tool_calls'] as $tc ) {
					$fn_name     = isset( $tc['function']['name'] ) ? $tc['function']['name'] : '';
					$fn_args_str = isset( $tc['function']['arguments'] ) ? $tc['function']['arguments'] : '{}';
					$fn_args     = json_decode( $fn_args_str, true );

					if ( ! is_array( $fn_args ) ) {
						$fn_args = array();
					}

					$result['tool_calls'][] = array(
						'name' => $fn_name,
						'args' => $fn_args,
					);
				}
			}
		}

		return $result;
	}

	/**
	 * Call Gemini API with streamed generation or a non-streaming fallback.
	 *
	 * @param string $api_key  API key.
	 * @param array  $settings AI settings.
	 * @param string $system   System prompt.
	 * @param array  $messages Conversation messages.
	 * @return array|WP_Error Result with 'content' and/or 'tool_calls'.
	 */
	private function call_gemini_with_tools( $api_key, $settings, $system, $messages, $has_existing = false ) {
		$model = isset( $settings['gemini_model'] ) ? $settings['gemini_model'] : 'gemini-3-flash-preview';

		// Build contents array.
		$contents = array();
		$recent   = $this->get_recent_messages( $messages, 8 );

		foreach ( $recent as $msg ) {
			$role = isset( $msg['role'] ) ? $msg['role'] : 'user';
			if ( 'assistant' === $role ) {
				$role = 'model';
			}
			if ( in_array( $role, array( 'user', 'model' ), true ) ) {
				$contents[] = array(
					'role'  => $role,
					'parts' => array( array( 'text' => isset( $msg['content'] ) ? $msg['content'] : '' ) ),
				);
			}
		}

		$body = array(
			'system_instruction' => array(
				'parts' => array( array( 'text' => $system ) ),
			),
			'contents'         => $contents,
			'generationConfig' => array(
				'maxOutputTokens' => 8000,
				'temperature'     => 0.5,
			),
		);

		if ( function_exists( 'curl_init' ) ) {
			return $this->call_gemini_with_tools_streaming( $api_key, $model, $body, $has_existing );
		}

		return $this->call_gemini_with_tools_fallback( $api_key, $model, $body );
	}

	/**
	 * Call Gemini's SSE endpoint so the builder can emit live progress updates.
	 *
	 * @param string $api_key API key.
	 * @param string $model   Model name.
	 * @param array  $body    Request body.
	 * @return array|WP_Error
	 */
	private function call_gemini_with_tools_streaming( $api_key, $model, $body, $has_existing = false ) {
		$url           = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':streamGenerateContent?alt=sse&key=' . $api_key;
		$result        = array(
			'content'    => '',
			'tool_calls' => array(),
		);
		$tool_call_map = array();
		$state         = array(
			'started_at'          => microtime( true ),
			'last_heartbeat_at'   => 0,
			'saw_first_chunk'     => false,
			'announced_tool_call' => false,
			'api_error_message'   => '',
			'buffer'              => '',
			'ai_step_count'       => 0,
			'has_existing'        => $has_existing,
		);

		$this->emit_keepalive_heartbeat( $state, true );

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_errno,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_close -- cURL is required here for true streaming SSE callbacks, which the WP HTTP API does not expose.
		$handle = curl_init( $url );

		curl_setopt( $handle, CURLOPT_POST, true );
		curl_setopt( $handle, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json' ) );
		curl_setopt( $handle, CURLOPT_POSTFIELDS, wp_json_encode( $body ) );
		curl_setopt( $handle, CURLOPT_RETURNTRANSFER, false );
		curl_setopt( $handle, CURLOPT_HEADER, false );
		curl_setopt( $handle, CURLOPT_TIMEOUT, 120 );
		curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 2 );
		curl_setopt( $handle, CURLOPT_BUFFERSIZE, 1024 );
		curl_setopt( $handle, CURLOPT_NOPROGRESS, false );

		$write_callback = function ( $curl_handle, $chunk ) use ( &$result, &$tool_call_map, &$state ) {
			$state['buffer'] .= str_replace( "\r\n", "\n", $chunk );

			while ( false !== strpos( $state['buffer'], "\n\n" ) ) {
				$delimiter = strpos( $state['buffer'], "\n\n" );
				$event     = substr( $state['buffer'], 0, $delimiter );
				$state['buffer'] = substr( $state['buffer'], $delimiter + 2 );
				$this->process_gemini_stream_event( $event, $result, $tool_call_map, $state );
			}

			return strlen( $chunk );
		};

		curl_setopt( $handle, CURLOPT_WRITEFUNCTION, $write_callback );

		$progress_callback = function () use ( &$state ) {
			$this->emit_keepalive_heartbeat( $state );
			return 0;
		};

		if ( defined( 'CURLOPT_XFERINFOFUNCTION' ) ) {
			curl_setopt( $handle, CURLOPT_XFERINFOFUNCTION, $progress_callback );
		} else {
			curl_setopt( $handle, CURLOPT_PROGRESSFUNCTION, $progress_callback );
		}

		$success   = curl_exec( $handle );
		$http_code = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
		$error_no  = curl_errno( $handle );
		$error_msg = curl_error( $handle );
		curl_close( $handle );
		// phpcs:enable

		if ( ! empty( trim( $state['buffer'] ) ) ) {
			$this->process_gemini_stream_event( $state['buffer'], $result, $tool_call_map, $state );
		}

		if ( ! empty( $state['api_error_message'] ) ) {
			return new \WP_Error( 'ai_error', $state['api_error_message'], array( 'status' => 500 ) );
		}

		if ( false === $success || 0 !== $error_no ) {
			$message = ! empty( $error_msg ) ? $error_msg : 'Gemini request failed.';
			if ( 28 === $error_no ) {
				$message = 'Gemini timed out before returning a usable response. The request size has been reduced, but if this persists, switch to a Flash or Flash-Lite model.';
			}

			return new \WP_Error( 'ai_error', $message, array( 'status' => 500 ) );
		}

		if ( $http_code >= 400 ) {
			return new \WP_Error( 'ai_error', 'Gemini returned HTTP ' . $http_code . '.', array( 'status' => $http_code ) );
		}

		$result['tool_calls']     = array_values( $tool_call_map );
		$result['tool_announced'] = ! empty( $state['announced_tool_call'] );

		return $result;
	}

	/**
	 * Fallback Gemini call when cURL streaming is not available.
	 *
	 * @param string $api_key API key.
	 * @param string $model   Model name.
	 * @param array  $body    Request body.
	 * @return array|WP_Error
	 */
	private function call_gemini_with_tools_fallback( $api_key, $model, $body ) {

		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;

		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 120,
				'headers'   => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$resp_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $resp_body['error'] ) ) {
			return new \WP_Error(
				'ai_error',
				isset( $resp_body['error']['message'] ) ? $resp_body['error']['message'] : 'AI API error.',
				array( 'status' => 500 )
			);
		}

		$result = array(
			'content'    => '',
			'tool_calls' => array(),
		);

		if ( isset( $resp_body['candidates'][0]['content']['parts'] ) ) {
			foreach ( $resp_body['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$result['content'] .= $part['text'];
				}
				if ( isset( $part['functionCall'] ) ) {
					$result['tool_calls'][] = array(
						'name' => $part['functionCall']['name'],
						'args' => isset( $part['functionCall']['args'] ) ? $part['functionCall']['args'] : array(),
					);
				}
			}
		}

		return $result;
	}



	/**
	 * Execute a tool call and send the result to the client.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $args      Tool arguments.
	 */
	private function execute_tool( $tool_name, $args ) {
		switch ( $tool_name ) {
			case 'generate_page':
				$this->send_sse_event( 'status', array( 'step' => 'building', 'message' => 'Building your page...' ) );

				$html  = isset( $args['html'] ) ? $args['html'] : '';
				$title = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : '';

				$this->send_sse_event( 'page_generated', array(
					'html'    => $html,
					'title'   => $title,
					'message' => 'Page generated successfully!',
				) );
				break;

			case 'refine_page':
				$this->send_sse_event( 'status', array( 'step' => 'applying', 'message' => 'Applying changes...' ) );

				$html    = isset( $args['html'] ) ? $args['html'] : '';
				$summary = isset( $args['summary'] ) ? $args['summary'] : 'Page updated.';

				$this->send_sse_event( 'page_refined', array(
					'html'    => $html,
					'message' => $summary,
				) );
				break;

			case 'answer_question':
				$response = isset( $args['response'] ) ? $args['response'] : '';

				$this->send_sse_event( 'assistant_message', array(
					'content' => $response,
				) );
				break;

			default:
				$this->send_sse_event( 'error', array(
					'message' => 'Unknown tool: ' . $tool_name,
				) );
		}
	}

	/**
	 * Detect [STEP: ...] markers in accumulated content and emit progress events.
	 *
	 * Called after each streamed chunk to detect new step markers from the AI
	 * and emit them as progress_update SSE events in real-time.
	 *
	 * @param string $content Accumulated response content so far.
	 * @param array  $state   Streaming state (passed by reference).
	 * @return void
	 */
	private function detect_and_emit_steps( $content, &$state ) {
		preg_match_all( '/\[STEP:\s*(.+?)\]/', $content, $matches );

		$found_count = count( $matches[1] );

		if ( $found_count <= $state['ai_step_count'] ) {
			return;
		}

		// On first step detection, announce the tool to the frontend so the
		// progress panel activates with the correct tool-specific header.
		if ( 0 === $state['ai_step_count'] && empty( $state['announced_tool_call'] ) ) {
			$tool_name = ! empty( $state['has_existing'] ) ? 'refine_page' : 'generate_page';
			$this->send_sse_event(
				'tool_call',
				array(
					'tool'    => $tool_name,
					'step'    => 'building',
					'message' => 'refine_page' === $tool_name
						? 'Refining your page...'
						: 'Building your page...',
					'steps'   => array(),
				)
			);
			$state['announced_tool_call'] = true;
		}

		// Emit progress for new steps only.
		for ( $i = $state['ai_step_count']; $i < $found_count; $i++ ) {
			$step_label = trim( $matches[1][ $i ] );
			$step_id    = 'ai_step_' . $i;

			// Mark previous step as done.
			if ( $i > 0 ) {
				$prev_id = 'ai_step_' . ( $i - 1 );
				$this->send_progress_update( $prev_id, 'done' );
			}

			// Mark current step as active.
			$this->send_progress_update( $step_id, 'active', $step_label, $step_label . '...' );
		}

		$state['ai_step_count'] = $found_count;
	}

	/**
	 * Clean assistant message content for chat display.
	 *
	 * Strips [STEP: ...] markers, code blocks (```html...```), and
	 * excessive whitespace so the chat shows a clean, readable summary.
	 *
	 * @param string $content Raw AI response content.
	 * @return string Cleaned message suitable for chat display.
	 */
	private function clean_assistant_message( $content ) {
		if ( empty( $content ) ) {
			return '';
		}

		// Remove [STEP: ...] markers.
		$cleaned = preg_replace( '/\[STEP:\s*.+?\]\s*/s', '', $content );

		// Remove html code blocks (```html ... ``` or unclosed ```html ... EOF).
		$cleaned = preg_replace( '/```(?:html|css)?\s*<[\s\S]*?```/is', '', $cleaned );
		$cleaned = preg_replace( '/```(?:html|css)?\s*<[\s\S]*$/is', '', $cleaned );

		// Remove any remaining code fences.
		$cleaned = preg_replace( '/```[\s\S]*?```/s', '', $cleaned );

		// Collapse excessive whitespace / blank lines.
		$cleaned = preg_replace( '/\n{3,}/', "\n\n", $cleaned );
		$cleaned = trim( $cleaned );

		// If everything was stripped, return a fallback.
		if ( empty( $cleaned ) ) {
			return 'Done! The page has been updated — check the preview.';
		}

		return $cleaned;
	}

	/**
	 * Send a commentary SSE event.
	 *
	 * Commentary events narrate what the AI is doing in real-time,
	 * making the build process transparent and engaging.
	 *
	 * @param string $phase   The current phase identifier.
	 * @param string $message Human-readable commentary message.
	 */
	private function send_commentary( $phase, $message ) {
		$this->send_sse_event( 'commentary', array(
			'phase'   => sanitize_text_field( $phase ),
			'message' => sanitize_text_field( $message ),
		) );
	}

	/**
	 * Analyze generated HTML and send commentary about its structure.
	 *
	 * Counts sections, detects styles, fonts, and notable features
	 * to narrate the build process.
	 *
	 * @param string $html The generated HTML.
	 */
	private function send_html_analysis_commentary( $html ) {
		if ( empty( $html ) ) {
			return;
		}

		// Count sections.
		$section_count = preg_match_all( '/data-amb-section/i', $html );
		if ( $section_count > 0 ) {
			$this->send_commentary( 'structure', 'Generating ' . $section_count . ' section' . ( $section_count > 1 ? 's' : '' ) . ' for the page layout.' );
		}

		// Check for style block.
		if ( preg_match( '/<style/i', $html ) ) {
			$this->send_commentary( 'styling', 'Writing custom CSS styles and responsive rules.' );
		}

		// Check for Google Fonts.
		if ( preg_match( '/@import.*fonts\.googleapis/i', $html ) ) {
			$this->send_commentary( 'fonts', 'Loading custom Google Fonts for typography.' );
		}

		// Check for responsive queries.
		if ( preg_match( '/@media/i', $html ) ) {
			$this->send_commentary( 'responsive', 'Adding responsive breakpoints for mobile and tablet.' );
		}
	}

	/**
	 * Send a final operation summary after tool execution.
	 *
	 * Provides a walkthrough of what was accomplished, including
	 * section count, styling details, and elapsed time.
	 *
	 * @param string $tool_name Tool that was executed.
	 * @param array  $args      Tool arguments.
	 * @param float  $elapsed   Elapsed time in seconds.
	 */
	private function send_operation_summary( $tool_name, $args, $elapsed ) {
		$summary_parts = array();

		switch ( $tool_name ) {
			case 'generate_page':
				$html  = ! empty( $args['html'] ) ? $args['html'] : '';
				$title = ! empty( $args['title'] ) ? $args['title'] : 'Untitled';

				$summary_parts[] = '✅ Page "' . $title . '" generated';

				$section_count = preg_match_all( '/data-amb-section/i', $html );
				if ( $section_count > 0 ) {
					$summary_parts[] = $section_count . ' section' . ( $section_count > 1 ? 's' : '' ) . ' created';
				}

				if ( preg_match( '/<style/i', $html ) ) {
					$summary_parts[] = 'custom CSS included';
				}

				if ( preg_match( '/@media/i', $html ) ) {
					$summary_parts[] = 'responsive design ready';
				}

				$summary_parts[] = 'completed in ' . $elapsed . 's';

				$this->send_sse_event( 'operation_summary', array(
					'operation' => 'generate',
					'title'     => $title,
					'summary'   => implode( ' · ', $summary_parts ),
					'details'   => $summary_parts,
					'elapsed'   => $elapsed,
				) );
				break;

			case 'refine_page':
				$change_summary = ! empty( $args['summary'] ) ? $args['summary'] : 'Page updated';

				$summary_parts[] = '✏️ ' . $change_summary;
				$summary_parts[] = 'completed in ' . $elapsed . 's';

				$this->send_sse_event( 'operation_summary', array(
					'operation' => 'refine',
					'summary'   => implode( ' · ', $summary_parts ),
					'details'   => $summary_parts,
					'elapsed'   => $elapsed,
				) );
				break;

			case 'answer_question':
				$this->send_sse_event( 'operation_summary', array(
					'operation' => 'answer',
					'summary'   => '💬 Answered your question in ' . $elapsed . 's',
					'details'   => array( 'Question answered', 'completed in ' . $elapsed . 's' ),
					'elapsed'   => $elapsed,
				) );
				break;
		}
	}

	/**
	 * Send an SSE event.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Event data.
	 */
	private function send_sse_event( $event, $data ) {
		$event_name = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $event );
		echo 'event: ' . esc_html( $event_name ) . "\n";
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";
		if ( ob_get_level() > 0 ) {
			ob_flush();
		}
		flush();
	}

	/**
	 * Send an error response (non-streaming fallback).
	 *
	 * @param string $message Error message.
	 */
	private function send_error_response( $message ) {
		header( 'Content-Type: text/event-stream; charset=UTF-8' );
		header( 'Cache-Control: no-cache' );
		echo "event: error\n";
		echo 'data: ' . wp_json_encode( array( 'message' => $message ) ) . "\n\n";
		echo "event: done\n";
		echo "data: []\n\n";
		echo "data: [DONE]\n\n";
		flush();
		exit;
	}
}
