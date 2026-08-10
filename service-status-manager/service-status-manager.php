<?php
/**
 * Plugin Name:       Service Status Manager
 * Plugin URI:        https://github.com/snelly101/StatusPlugin
 * Description:       A complete public status page system: services, monitors, incidents, scheduled maintenance, subscriber notifications (email, SMS, Microsoft Teams) and a REST API.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Service Status Manager Contributors
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
define( 'SSM_VERSION', '1.0.0' );
define( 'SSM_DB_VERSION', '1.0.0' );
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

		$file_base = strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $short ) );
		$file_base = str_replace( '_', '-', $file_base );

		$is_interface = 0 === strpos( $short, 'I' ) && isset( $short[1] ) && strtoupper( $short[1] ) === $short[1];

		$candidates = array(
			$dir . 'class-' . $file_base . '.php',
			$dir . 'interface-' . preg_replace( '/^i-/', '', $file_base ) . '.php',
			$dir . 'trait-' . $file_base . '.php',
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
