<?php
/**
 * Rolls raw monitor_checks rows up into hourly and daily aggregates, and
 * serves the aggregated data used by the public uptime history displays.
 *
 * Aggregating (rather than querying raw check rows for history views) is
 * what keeps the public status page fast even with thousands of monitors
 * checked every minute for years - see Cleanup for how the raw rows this
 * class consumes are eventually pruned once they have been aggregated.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UptimeAggregator {

	/**
	 * Cron entry point (hourly): aggregates the previous complete hour of
	 * raw checks for every monitor.
	 */
	public static function aggregate_hourly() {
		global $wpdb;

		$period_start = gmdate( 'Y-m-d H:00:00', strtotime( '-1 hour', current_time( 'timestamp', true ) ) );
		$period_end   = gmdate( 'Y-m-d H:i:s', strtotime( $period_start ) + HOUR_IN_SECONDS );

		$checks_table = ssm_table( 'monitor_checks' );
		$agg_table    = ssm_table( 'monitor_aggregates' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT monitor_id,
					COUNT(*) AS checks_total,
					SUM(CASE WHEN result = 'up' THEN 1 ELSE 0 END) AS checks_up,
					AVG(response_time_ms) AS avg_response_time_ms
				FROM {$checks_table}
				WHERE checked_at >= %s AND checked_at < %s
				GROUP BY monitor_id",
				$period_start,
				$period_end
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $rows as $row ) {
			$uptime_pct = $row->checks_total > 0 ? round( ( $row->checks_up / $row->checks_total ) * 100, 4 ) : 0;

			self::upsert_aggregate( $agg_table, $row->monitor_id, 'hour', $period_start, (int) $row->checks_total, (int) $row->checks_up, $uptime_pct, $row->avg_response_time_ms ? (int) round( $row->avg_response_time_ms ) : null );
		}

		update_option( 'ssm_last_hourly_aggregate', ssm_now(), false );
	}

	/**
	 * Cron entry point (daily): aggregates the previous complete day from
	 * the hourly aggregates (cheaper than re-scanning raw checks once
	 * they've been rolled up once already).
	 */
	public static function aggregate_daily() {
		global $wpdb;

		$period_start = gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day', current_time( 'timestamp', true ) ) );
		$period_end   = gmdate( 'Y-m-d H:i:s', strtotime( $period_start ) + DAY_IN_SECONDS );

		$agg_table = ssm_table( 'monitor_aggregates' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT monitor_id,
					SUM(checks_total) AS checks_total,
					SUM(checks_up) AS checks_up,
					AVG(avg_response_time_ms) AS avg_response_time_ms
				FROM {$agg_table}
				WHERE period_type = 'hour' AND period_start >= %s AND period_start < %s
				GROUP BY monitor_id",
				$period_start,
				$period_end
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $rows as $row ) {
			$uptime_pct = $row->checks_total > 0 ? round( ( $row->checks_up / $row->checks_total ) * 100, 4 ) : 0;
			self::upsert_aggregate( $agg_table, $row->monitor_id, 'day', $period_start, (int) $row->checks_total, (int) $row->checks_up, $uptime_pct, $row->avg_response_time_ms ? (int) round( $row->avg_response_time_ms ) : null );
		}

		update_option( 'ssm_last_daily_aggregate', ssm_now(), false );
	}

	/**
	 * Inserts or updates a single aggregate row.
	 *
	 * @param string   $table                Aggregate table name.
	 * @param int      $monitor_id           Monitor ID.
	 * @param string   $period_type          "hour" or "day".
	 * @param string   $period_start         Period start (MySQL datetime, UTC).
	 * @param int      $checks_total         Total checks in the period.
	 * @param int      $checks_up            Successful checks in the period.
	 * @param float    $uptime_pct           Uptime percentage.
	 * @param int|null $avg_response_time_ms Average response time.
	 */
	private static function upsert_aggregate( $table, $monitor_id, $period_type, $period_start, $checks_total, $checks_up, $uptime_pct, $avg_response_time_ms ) {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"INSERT INTO {$table} (monitor_id, period_type, period_start, checks_total, checks_up, uptime_pct, avg_response_time_ms)
				VALUES (%d, %s, %s, %d, %d, %f, %d)
				ON DUPLICATE KEY UPDATE checks_total = VALUES(checks_total), checks_up = VALUES(checks_up), uptime_pct = VALUES(uptime_pct), avg_response_time_ms = VALUES(avg_response_time_ms)",
				$monitor_id,
				$period_type,
				$period_start,
				$checks_total,
				$checks_up,
				$uptime_pct,
				$avg_response_time_ms
			)
		);
	}

	/**
	 * Returns a day-by-day uptime history for a monitor, oldest first,
	 * with gaps (days without any recorded checks) represented as null so
	 * the front end can render them distinctly from "100% up".
	 *
	 * @param int $monitor_id Monitor ID.
	 * @param int $days       Number of days of history to return.
	 * @return array<int,array{date:string,uptime_pct:float|null}>
	 */
	public static function get_daily_history( $monitor_id, $days = 90 ) {
		global $wpdb;
		$table = ssm_table( 'monitor_aggregates' );

		$since = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT period_start, uptime_pct FROM {$table} WHERE monitor_id = %d AND period_type = 'day' AND period_start >= %s ORDER BY period_start ASC",
				absint( $monitor_id ),
				$since
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$by_date = array();
		foreach ( $rows as $row ) {
			$by_date[ gmdate( 'Y-m-d', strtotime( $row->period_start ) ) ] = (float) $row->uptime_pct;
		}

		$history = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date      = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$history[] = array(
				'date'       => $date,
				'uptime_pct' => $by_date[ $date ] ?? null,
			);
		}

		return $history;
	}

	/**
	 * Calculates a single monitor's overall uptime percentage across a
	 * date range, weighted by the number of checks in each day.
	 *
	 * @param int $monitor_id Monitor ID.
	 * @param int $days       Number of days.
	 * @return float
	 */
	public static function get_monitor_uptime_percentage( $monitor_id, $days = 90 ) {
		global $wpdb;
		$table = ssm_table( 'monitor_aggregates' );
		$since = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(checks_total) AS total, SUM(checks_up) AS up FROM {$table} WHERE monitor_id = %d AND period_type = 'day' AND period_start >= %s",
				absint( $monitor_id ),
				$since
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row || ! $row->total ) {
			return 100.0;
		}

		return round( ( $row->up / $row->total ) * 100, 2 );
	}

	/**
	 * Calculates a service's overall uptime percentage as the average of
	 * its monitors' individual uptime percentages.
	 *
	 * @param int $service_id Service ID.
	 * @param int $days       Number of days.
	 * @return float
	 */
	public static function get_service_uptime_percentage( $service_id, $days = 90 ) {
		$monitors = MonitorManager::get_monitors_for_service( $service_id );

		if ( empty( $monitors ) ) {
			return 100.0;
		}

		$total = 0.0;
		$count = 0;
		foreach ( $monitors as $monitor ) {
			if ( 'manual' === $monitor->type ) {
				continue;
			}
			$total += self::get_monitor_uptime_percentage( $monitor->id, $days );
			++$count;
		}

		return $count > 0 ? round( $total / $count, 2 ) : 100.0;
	}
}
