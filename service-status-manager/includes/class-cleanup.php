<?php
/**
 * Scheduled housekeeping: prunes raw monitor checks, aggregates,
 * notification logs, and audit log entries once they exceed their
 * configured retention period (Settings > Data Retention).
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cleanup {

	/**
	 * Cron entry point (daily).
	 */
	public static function run() {
		global $wpdb;

		$settings = ssm_get_settings();

		self::delete_older_than( ssm_table( 'monitor_checks' ), 'checked_at', (int) $settings['raw_check_retention_days'] );
		self::delete_older_than( ssm_table( 'monitor_aggregates' ), 'period_start', (int) $settings['hourly_aggregate_retention_days'], "period_type = 'hour'" );
		self::delete_older_than( ssm_table( 'monitor_aggregates' ), 'period_start', (int) $settings['daily_aggregate_retention_days'], "period_type = 'day'" );
		self::delete_older_than( ssm_table( 'notification_log' ), 'created_at', (int) $settings['notification_log_retention_days'] );
		self::delete_older_than( ssm_table( 'audit_log' ), 'created_at', (int) $settings['audit_log_retention_days'] );
		self::delete_older_than( ssm_table( 'logs' ), 'created_at', (int) $settings['log_retention_days'] );
		self::delete_older_than( ssm_table( 'webhook_delivery_log' ), 'created_at', (int) $settings['notification_log_retention_days'] );

		self::purge_expired_verification_tokens();
		self::purge_sent_notification_queue_rows();

		update_option( 'ssm_last_cleanup_run', ssm_now(), false );
	}

	/**
	 * Deletes rows older than the given number of days, in bounded
	 * batches to avoid long-running locks on large tables.
	 *
	 * @param string $table          Fully-prefixed table name.
	 * @param string $date_column    Column holding the comparison date.
	 * @param int    $retention_days Retention window in days. 0 disables cleanup for this table.
	 * @param string $extra_where    Optional additional SQL condition (already trusted, no user input).
	 */
	private static function delete_older_than( $table, $date_column, $retention_days, $extra_where = '' ) {
		if ( $retention_days <= 0 ) {
			return;
		}

		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$where  = $extra_where ? "{$date_column} < %s AND {$extra_where}" : "{$date_column} < %s";

		do {
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$where} LIMIT 1000", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} while ( $deleted > 0 );
	}

	/**
	 * Removes expired, unused verification tokens.
	 */
	private static function purge_expired_verification_tokens() {
		global $wpdb;
		$table = ssm_table( 'verification_tokens' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", ssm_now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Removes fully-sent/cancelled notification queue rows once they are
	 * old enough that a retry or de-duplication lookup will never need
	 * them again (their outcome remains visible in notification_log).
	 */
	private static function purge_sent_notification_queue_rows() {
		global $wpdb;
		$table  = ssm_table( 'notification_queue' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status IN ('sent','cancelled') AND created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
