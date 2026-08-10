<?php
/**
 * Read-only reporting queries backing the admin Reports screen and its
 * CSV exports. Exported subscriber-related data is limited to
 * aggregate counts - never individual subscriber contact details - since
 * CSV exports can be handled less carefully than the admin screen itself
 * once downloaded.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reports {

	/**
	 * Dispatches to the correct report builder by key, for CSV export.
	 *
	 * @param string $report Report key.
	 * @return array
	 */
	public static function get_report_rows( $report ) {
		switch ( $report ) {
			case 'uptime_by_service':
				return self::uptime_by_service();
			case 'uptime_by_monitor':
				return self::uptime_by_monitor();
			case 'incidents':
				return self::incident_summary();
			case 'notification_delivery':
				return self::notification_delivery();
			case 'subscriber_growth':
				return self::subscriber_growth();
			case 'channel_usage':
				return self::channel_usage();
			case 'subscribed_services':
				return self::most_subscribed_services();
			case 'response_time':
				return self::monitor_response_time();
		}

		return array();
	}

	/**
	 * @return array Per-service 90-day uptime percentage.
	 */
	public static function uptime_by_service() {
		$rows = array();
		foreach ( ServiceManager::get_services() as $service ) {
			$rows[] = (object) array(
				'service'    => $service->name,
				'status'     => $service->status,
				'uptime_90d' => UptimeAggregator::get_service_uptime_percentage( $service->id, 90 ),
			);
		}
		return $rows;
	}

	/**
	 * @return array Per-monitor 90-day uptime percentage and average response time.
	 */
	public static function uptime_by_monitor() {
		global $wpdb;
		$rows    = array();
		$monitors = $wpdb->get_results( 'SELECT id, name, service_id, type FROM ' . ssm_table( 'monitors' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $monitors as $monitor ) {
			if ( 'manual' === $monitor->type ) {
				continue;
			}
			$rows[] = (object) array(
				'monitor'    => $monitor->name,
				'type'       => $monitor->type,
				'uptime_90d' => UptimeAggregator::get_monitor_uptime_percentage( $monitor->id, 90 ),
			);
		}
		return $rows;
	}

	/**
	 * @return array Incident counts, mean time to acknowledge/resolve, and durations.
	 */
	public static function incident_summary() {
		global $wpdb;
		$table         = ssm_table( 'incidents' );
		$updates_table = ssm_table( 'incident_updates' );

		$incidents = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY starts_at DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows = array();
		foreach ( $incidents as $incident ) {
			$first_ack = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MIN(created_at) FROM {$updates_table} WHERE incident_id = %d AND status IN ('identified','monitoring','resolved') AND is_internal = 0",
					$incident->id
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			$minutes_to_ack = $first_ack ? round( ( strtotime( $first_ack ) - strtotime( $incident->starts_at ) ) / 60 ) : null;
			$minutes_to_resolve = $incident->ends_at ? round( ( strtotime( $incident->ends_at ) - strtotime( $incident->starts_at ) ) / 60 ) : null;

			$rows[] = (object) array(
				'title'                => $incident->title,
				'severity'             => $incident->severity,
				'status'               => $incident->status,
				'started'              => $incident->starts_at,
				'resolved'             => $incident->ends_at,
				'minutes_to_acknowledge' => $minutes_to_ack,
				'minutes_to_resolve'   => $minutes_to_resolve,
			);
		}

		return $rows;
	}

	/**
	 * @return array Notification delivery success/failure counts by channel.
	 */
	public static function notification_delivery() {
		global $wpdb;
		$table = ssm_table( 'notification_log' );

		return $wpdb->get_results(
			"SELECT channel,
				SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS sent,
				SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failed
			FROM {$table}
			GROUP BY channel"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @return array Cumulative subscriber count by signup month.
	 */
	public static function subscriber_growth() {
		global $wpdb;
		$table = ssm_table( 'subscribers' );

		return $wpdb->get_results(
			"SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS new_subscribers
			FROM {$table}
			GROUP BY month
			ORDER BY month ASC"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @return array Verified, active subscriber counts by channel.
	 */
	public static function channel_usage() {
		global $wpdb;
		$table = ssm_table( 'subscriber_channels' );

		return $wpdb->get_results(
			"SELECT channel, COUNT(*) AS active_verified_subscribers
			FROM {$table}
			WHERE is_active = 1 AND verified = 1
			GROUP BY channel"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @return array Services ranked by subscriber selection count.
	 */
	public static function most_subscribed_services() {
		global $wpdb;
		$sql = 'SELECT s.name AS service, COUNT(*) AS subscriber_count
			FROM ' . ssm_table( 'subscriber_selections' ) . ' sel
			INNER JOIN ' . ssm_table( 'services' ) . " s ON s.id = sel.scope_id AND sel.scope_type = 'service'
			GROUP BY s.id
			ORDER BY subscriber_count DESC";

		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @return array Average monitor response time over the last 7 days of hourly aggregates.
	 */
	public static function monitor_response_time() {
		global $wpdb;
		$sql = 'SELECT m.name AS monitor, AVG(a.avg_response_time_ms) AS avg_response_time_ms
			FROM ' . ssm_table( 'monitor_aggregates' ) . ' a
			INNER JOIN ' . ssm_table( 'monitors' ) . ' m ON m.id = a.monitor_id
			WHERE a.period_type = \'hour\' AND a.period_start >= %s
			GROUP BY m.id
			ORDER BY avg_response_time_ms DESC';

		return $wpdb->get_results( $wpdb->prepare( $sql, gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
