<?php
/**
 * Plugin Name:       Service Status Manager
 * Plugin URI:        https://github.com/snelly101/StatusPlugin
 * Description:       A complete public status page system: services, monitors, incidents, scheduled maintenance, subscriber notifications (email, SMS, Microsoft Teams) and a REST API.
 * Version:           1.15.3
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            SnelsonServer
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       service-status-manager
 * Domain Path:       /languages
 * Network:           false
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Core plugin constants. Every other file in the plugin relies on these.
define( 'SSM_VERSION', '1.15.3' );
define( 'SSM_DB_VERSION', '1.3.0' );
define( 'SSM_PLUGIN_FILE', __FILE__ );
define( 'SSM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SSM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'SSM_TEXT_DOMAIN', 'service-status-manager' );

/**
 * PSR-4-ish autoloader for the ServiceStatusManager namespace.
 *
 * Class names map to files using WordPress file naming conventions
 * (class-my-class.php, interface-my-interface.php) inside the folder
 * that corresponds to the sub-namespace.
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		// A handful of files intentionally don't follow the short-name ->
		// kebab-case convention below (mainly because "Public" is a
		// reserved word in PHP and cannot be used as a class name), so
		// they are mapped explicitly rather than guessed.
		$exceptions = array(
			__NAMESPACE__ . '\\Publicweb\\PublicController' => 'public/class-public.php',
		);
		if ( isset( $exceptions[ $class ] ) ) {
			require_once SSM_PLUGIN_DIR . $exceptions[ $class ];
			return;
		}

		$relative = substr( $class, strlen( __NAMESPACE__ . '\\' ) );
		$parts    = explode( '\\', $relative );
		$short    = array_pop( $parts );

		$map = array(
			'Admin'         => 'admin',
			'Api'           => 'api',
			'Monitoring'    => 'monitoring',
			'Notifications' => 'notifications',
			'Publicweb'     => 'public',
		);

		$dir = SSM_PLUGIN_DIR . 'includes/';
		if ( ! empty( $parts ) ) {
			$top = $parts[0];
			if ( isset( $map[ $top ] ) ) {
				$dir = SSM_PLUGIN_DIR . $map[ $top ] . '/';
			}
		}

		$kebab = function ( $name ) {
			$name = preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $name );
			return strtolower( str_replace( '_', '-', $name ) );
		};

		$class_base = $kebab( $short );

		// Interface/trait files are named after the short class name with
		// its "Interface"/"Trait" suffix dropped (interface-monitor-
		// provider.php for MonitorProviderInterface, not interface-
		// monitor-provider-interface.php).
		$interface_base = preg_match( '/Interface$/', $short ) ? $kebab( substr( $short, 0, -9 ) ) : $class_base;
		$trait_base      = preg_match( '/Trait$/', $short ) ? $kebab( substr( $short, 0, -5 ) ) : $class_base;

		$candidates = array(
			$dir . 'class-' . $class_base . '.php',
			$dir . 'interface-' . $interface_base . '.php',
			$dir . 'trait-' . $trait_base . '.php',
		);

		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				require_once $candidate;
				return;
			}
		}
	}
);

require_once SSM_PLUGIN_DIR . 'includes/helpers.php';

/**
 * Runs on plugin activation. Creates/updates database tables and schedules cron.
 */
function activate_plugin() {
	require_once SSM_PLUGIN_DIR . 'includes/class-activator.php';
	Activator::activate();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate_plugin' );

/**
 * Runs on plugin deactivation. Unschedules cron but never deletes data.
 */
function deactivate_plugin() {
	require_once SSM_PLUGIN_DIR . 'includes/class-deactivator.php';
	Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate_plugin' );

/**
 * Boots the plugin once all plugins are loaded so pluggable functions and
 * translations are available.
 */
function boot_plugin() {
	Plugin::instance()->run();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot_plugin' );
