<?php
/**
 * Runs once when the plugin is activated.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	/**
	 * Creates database tables, registers capabilities/roles, seeds default
	 * options and schedules cron events.
	 */
	public static function activate() {
		Database::install();
		Capabilities::install();
		self::seed_default_status_page();
		self::seed_cron_secret();
		Cron::schedule_events();

		if ( false === get_option( 'ssm_uninstall_delete_data', false ) ) {
			add_option( 'ssm_uninstall_delete_data', false, '', false );
		}

		update_option( 'ssm_activated_at', ssm_now(), false );

		flush_rewrite_rules();
	}

	/**
	 * Creates a default public status page record on first activation only.
	 */
	private static function seed_default_status_page() {
		global $wpdb;

		$table   = ssm_table( 'status_pages' );
		$existing = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $existing ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'name'                  => __( 'Service Status', 'service-status-manager' ),
				'slug'                  => 'main',
				'visibility'            => 'public',
				'page_title'            => __( 'Service Status', 'service-status-manager' ),
				'intro_text'            => __( 'Current status of our services and systems.', 'service-status-manager' ),
				'timezone'              => wp_timezone_string(),
				'date_format'           => get_option( 'date_format' ),
				'default_history_range' => 90,
				'created_at'            => ssm_now(),
				'updated_at'            => ssm_now(),
			)
		);
	}

	/**
	 * Generates the shared secret used to authenticate the server-side cron
	 * endpoint, if one does not already exist.
	 */
	private static function seed_cron_secret() {
		$settings = ssm_get_settings();

		if ( empty( $settings['cron_secret'] ) ) {
			ssm_update_settings( array( 'cron_secret' => ssm_generate_token( 32 ) ) );
		}
	}
}
