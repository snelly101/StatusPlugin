<?php
/**
 * Central orchestrator. Wires every subsystem (admin, public, REST API,
 * cron, monitoring, notifications) to its WordPress hooks.
 *
 * This is the only class that knows about every other major class in the
 * plugin - individual subsystems do not reach into each other directly,
 * they communicate through actions/filters (see the ssm_* hooks documented
 * throughout) so pieces can be replaced independently.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

use ServiceStatusManager\Admin\Admin;
use ServiceStatusManager\Publicweb\PublicController;
use ServiceStatusManager\Publicweb\Shortcodes;
use ServiceStatusManager\Api\RestApi;
use ServiceStatusManager\Api\WebhookApi;
use ServiceStatusManager\Api\WebhookDispatcher;
use ServiceStatusManager\Monitoring\MonitorRunner;
use ServiceStatusManager\Notifications\NotificationQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers every WordPress hook the plugin needs. Safe to call once,
	 * from the plugins_loaded action.
	 */
	public function run() {
		load_plugin_textdomain( SSM_TEXT_DOMAIN, false, dirname( SSM_PLUGIN_BASENAME ) . '/languages' );

		$this->maybe_upgrade();

		add_filter( 'cron_schedules', array( Cron::class, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval

		Privacy::register();
		NotificationManager::register();

		add_action( Cron::RUN_MONITOR_CHECKS, array( MonitorRunner::class, 'run_due_checks' ) );
		add_action( Cron::PROCESS_NOTIFICATIONS, array( NotificationQueue::class, 'process_batch' ) );
		add_action( Cron::AGGREGATE_HOURLY, array( UptimeAggregator::class, 'aggregate_hourly' ) );
		add_action( Cron::AGGREGATE_DAILY, array( UptimeAggregator::class, 'aggregate_daily' ) );
		add_action( Cron::CLEANUP_OLD_DATA, array( Cleanup::class, 'run' ) );
		add_action( Cron::MAINTENANCE_TRANSITIONS, array( MaintenanceManager::class, 'process_transitions' ) );

		if ( is_admin() ) {
			( new Admin() )->register();
		}

		( new PublicController() )->register();
		( new Shortcodes() )->register();

		add_action( 'rest_api_init', array( new RestApi(), 'register_routes' ) );
		add_action( 'rest_api_init', array( new WebhookApi(), 'register_routes' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'service-status', Cli::class );
		}

		add_action( 'ssm_incident_created', array( WebhookDispatcher::class, 'dispatch_incident_created' ) );
		add_action( 'ssm_incident_updated', array( WebhookDispatcher::class, 'dispatch_incident_updated' ), 10, 2 );
		add_action( 'ssm_incident_resolved', array( WebhookDispatcher::class, 'dispatch_incident_resolved' ) );
		add_action( 'ssm_service_status_changed', array( WebhookDispatcher::class, 'dispatch_service_status_changed' ), 10, 3 );
		add_action( 'ssm_monitor_status_changed', array( WebhookDispatcher::class, 'dispatch_monitor_status_changed' ), 10, 3 );
		add_action( 'ssm_maintenance_status_changed', array( WebhookDispatcher::class, 'dispatch_maintenance_status_changed' ), 10, 3 );
	}

	/**
	 * Compares the stored database version against the code version and
	 * runs dbDelta() again if they differ, so upgrades pick up schema
	 * changes automatically without a manual re-activation step.
	 */
	private function maybe_upgrade() {
		$installed = get_option( 'ssm_db_version', '' );

		if ( $installed !== SSM_DB_VERSION ) {
			Database::install();
		}
	}
}
