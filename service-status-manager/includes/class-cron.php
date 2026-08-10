<?php
/**
 * Registers WordPress cron schedules and events used by the plugin.
 *
 * WP-Cron only fires on incoming site traffic, which makes it an unreliable
 * scheduler for low-traffic sites and for check frequencies under a minute.
 * Every hook registered here can also be triggered by a real system cron
 * job hitting the secured REST endpoint (see Api\CronEndpoint) or by the
 * `wp service-status` WP-CLI commands, so production deployments should
 * disable WP-Cron's HTTP trigger (DISABLE_WP_CRON) and call one of those
 * two instead on a real one-minute cron.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cron {

	const RUN_MONITOR_CHECKS       = 'ssm_run_monitor_checks';
	const PROCESS_NOTIFICATIONS    = 'ssm_process_notification_queue';
	const AGGREGATE_HOURLY         = 'ssm_aggregate_uptime_hourly';
	const AGGREGATE_DAILY          = 'ssm_aggregate_uptime_daily';
	const CLEANUP_OLD_DATA         = 'ssm_cleanup_old_data';
	const MAINTENANCE_TRANSITIONS  = 'ssm_maintenance_transitions';

	/**
	 * Registers custom cron intervals used by the plugin.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedules( array $schedules ) {
		$schedules['ssm_one_minute']     = array(
			'interval' => 60,
			'display'  => __( 'Every Minute (Service Status Manager)', 'service-status-manager' ),
		);
		$schedules['ssm_five_minutes']   = array(
			'interval' => 300,
			'display'  => __( 'Every Five Minutes (Service Status Manager)', 'service-status-manager' ),
		);
		$schedules['ssm_fifteen_minutes'] = array(
			'interval' => 900,
			'display'  => __( 'Every Fifteen Minutes (Service Status Manager)', 'service-status-manager' ),
		);

		return $schedules;
	}

	/**
	 * Schedules every recurring event, if not already scheduled.
	 */
	public static function schedule_events() {
		$events = array(
			self::RUN_MONITOR_CHECKS      => 'ssm_one_minute',
			self::PROCESS_NOTIFICATIONS   => 'ssm_one_minute',
			self::AGGREGATE_HOURLY        => 'hourly',
			self::AGGREGATE_DAILY         => 'daily',
			self::CLEANUP_OLD_DATA        => 'daily',
			self::MAINTENANCE_TRANSITIONS => 'ssm_five_minutes',
		);

		foreach ( $events as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + 60, $recurrence, $hook );
			}
		}
	}

	/**
	 * Clears every scheduled event owned by the plugin.
	 */
	public static function unschedule_events() {
		$hooks = array(
			self::RUN_MONITOR_CHECKS,
			self::PROCESS_NOTIFICATIONS,
			self::AGGREGATE_HOURLY,
			self::AGGREGATE_DAILY,
			self::CLEANUP_OLD_DATA,
			self::MAINTENANCE_TRANSITIONS,
		);

		foreach ( $hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			while ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
				$timestamp = wp_next_scheduled( $hook );
			}
		}
	}
}
