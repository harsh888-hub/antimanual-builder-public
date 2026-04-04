<?php
/**
 * Plugin Name: Antimanual Builder
 * Plugin URI:  https://wordpress.org/plugins/antimanual-builder
 * Description: An AI-powered visual page builder for WordPress. Build complete websites through conversational AI or traditional drag-and-drop editing.
 * Version:     0.1.0
 * Author:      Spider Themes
 * Author URI:  https://spider-themes.net
 * Text Domain: antimanual-builder
 * Domain Path: /languages
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Antimanual_Builder
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version constant.
 */
define( 'AMB_VERSION', '1.0.0' );

/**
 * Plugin file path constant.
 */
define( 'AMB_PLUGIN_FILE', __FILE__ );

/**
 * Plugin directory path constant.
 */
define( 'AMB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL constant.
 */
define( 'AMB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename constant.
 */
define( 'AMB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Uploads directory name constant.
 */
define( 'AMB_UPLOAD_DIR', 'antimanual-builder' );

/**
 * Minimum PHP version required.
 */
define( 'AMB_MIN_PHP', '7.4' );

/**
 * Minimum WordPress version required.
 */
define( 'AMB_MIN_WP', '6.0' );

/**
 * Check PHP version compatibility before loading the plugin.
 */
if ( version_compare( PHP_VERSION, AMB_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', 'amb_php_version_notice' );
	return;
}

/**
 * Display admin notice for incompatible PHP version.
 *
 * @return void
 */
function amb_php_version_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: 1: Required PHP version, 2: Current PHP version. */
				esc_html__( 'Antimanual Builder requires PHP %1$s or higher. You are running PHP %2$s. Please upgrade PHP to use this plugin.', 'antimanual-builder' ),
				esc_html( AMB_MIN_PHP ),
				esc_html( PHP_VERSION )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Autoload plugin classes.
 *
 * Maps class names to file paths using the WordPress convention:
 * Class_Name → class-class-name.php
 *
 * @param string $class_name The fully qualified class name.
 * @return void
 */
function amb_autoloader( $class_name ) {
	// Only autoload classes with the AMB prefix.
	if ( 0 !== strpos( $class_name, 'AMB_' ) ) {
		return;
	}

	// Map class prefixes to directories.
	$prefix_map = array(
		'AMB_API_'         => 'includes/api/',
		'AMB_Post_Type_'   => 'includes/post-types/',
		'AMB_Render_'      => 'includes/render/',
		'AMB_Admin_'       => 'includes/admin/',
		'AMB_'             => 'includes/',
	);

	$file = '';
	foreach ( $prefix_map as $prefix => $dir ) {
		if ( 0 === strpos( $class_name, $prefix ) ) {
			$relative_class = substr( $class_name, strlen( $prefix ) );
			$file_name      = 'class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';
			$file           = AMB_PLUGIN_DIR . $dir . $file_name;
			break;
		}
	}

	if ( $file && file_exists( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'amb_autoloader' );

/**
 * Run activation hooks.
 *
 * @return void
 */
function amb_activate() {
	AMB_Activator::activate();
}
register_activation_hook( __FILE__, 'amb_activate' );

/**
 * Run deactivation hooks.
 *
 * @return void
 */
function amb_deactivate() {
	AMB_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'amb_deactivate' );

/**
 * Initialize the plugin.
 *
 * @return AMB_Antimanual_Builder The singleton instance of the plugin.
 */
function amb_init() {
	return AMB_Antimanual_Builder::get_instance();
}
add_action( 'plugins_loaded', 'amb_init' );