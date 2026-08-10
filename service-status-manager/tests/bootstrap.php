<?php
/**
 * PHPUnit bootstrap for the plugin's *unit* tests - these exercise pure
 * business logic (status roll-up, SSRF address validation, encryption)
 * in isolation, using minimal stand-in implementations of the handful of
 * WordPress functions that logic touches, rather than a full WordPress
 * install.
 *
 * This deliberately does NOT attempt to be a WordPress integration test
 * suite - testing database repositories (ServiceManager, IncidentManager,
 * SubscriberManager, etc.), REST routes, or cron behaviour requires the
 * real wordpress-develop PHPUnit test scaffold (WP_UnitTestCase, a test
 * database) via the standard `wp scaffold plugin-tests` / wp-env
 * workflow - see tests/README.md.
 *
 * @package ServiceStatusManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// --- Minimal WordPress function stand-ins -------------------------------
// Only what the pure-logic classes under test actually call.

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ) {}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, (array) $args );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['ssm_test_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['ssm_test_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) { return 'unit-test-fixed-salt-' . $scheme; }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'UTC'; }
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() { return new DateTimeZone( 'UTC' ); }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) { return 'Test Site'; }
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '' ) { return $GLOBALS['ssm_test_cache'][ $group . ':' . $key ] ?? false; }
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '', $expire = 0 ) { $GLOBALS['ssm_test_cache'][ $group . ':' . $key ] = $value; return true; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) { return $GLOBALS['ssm_test_transients'][ $key ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expire = 0 ) { $GLOBALS['ssm_test_transients'][ $key ] = $value; return true; }
}

/**
 * Test double for ssm_log() - the real one writes to a database table via
 * $wpdb, which is not available in this lightweight unit-test harness.
 */
if ( ! function_exists( 'ssm_log' ) ) {
	function ssm_log( $message, $level = 'info', $context = array() ) {
		$GLOBALS['ssm_test_logs'][] = array( $message, $level, $context );
	}
}

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/class-status-calculator.php';
require_once __DIR__ . '/../includes/class-encryption.php';
require_once __DIR__ . '/../monitoring/class-ssrf-guard.php';
