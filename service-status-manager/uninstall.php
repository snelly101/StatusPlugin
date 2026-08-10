<?php
/**
 * Fires when the plugin is deleted through the WordPress admin.
 *
 * By default plugin data is retained even after deletion, because
 * uninstalling a plugin is a routine housekeeping action and should never
 * silently destroy incident history, subscriber records, or configuration.
 * Data is only removed if an administrator has explicitly opted in via
 * Settings > Tools > "Delete all plugin data on uninstall".
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/class-database.php';
require_once __DIR__ . '/includes/class-capabilities.php';
require_once __DIR__ . '/includes/class-cron.php';

// Always safe to do regardless of the data-retention choice: stop any
// scheduled work so nothing keeps firing against a removed plugin.
Cron::unschedule_events();

$delete_data = get_option( 'ssm_uninstall_delete_data', false );

if ( ! $delete_data ) {
	return;
}

Database::drop_all_tables();
Capabilities::remove();

$options = array(
	'ssm_settings',
	'ssm_db_version',
	'ssm_activated_at',
	'ssm_uninstall_delete_data',
	'ssm_timezone',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
