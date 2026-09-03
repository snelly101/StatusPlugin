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
		self::purge_notification_queue_rows( (int) $settings['notification_queue_retention_days'], (int) $settings['notification_queue_failed_retention_days'] );
		self::purge_notification_events();

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
	 * Removes finished notification queue rows once they are old enough
	 * that a retry or de-duplication lookup will never need them again
	 * (their outcome remains visible in notification_log). Sent/cancelled
	 * rows use the configurable `notification_queue_retention_days`
	 * setting; failed rows get their own, longer-by-default
	 * `notification_queue_failed_retention_days` - previously failed rows
	 * were never purged at all, which permanently blocked dedup_key reuse
	 * for anything that failed once (e.g. a subscriber's phone number that
	 * was temporarily invalid).
	 *
	 * @param int $retention_days        Days to keep sent/cancelled rows. 0 disables.
	 * @param int $failed_retention_days Days to keep failed rows. 0 disables.
	 */
	private static function purge_notification_queue_rows( $retention_days, $failed_retention_days ) {
		global $wpdb;
		$table = ssm_table( 'notification_queue' );

		if ( $retention_days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status IN ('sent','cancelled') AND created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( $failed_retention_days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $failed_retention_days * DAY_IN_SECONDS ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'failed' AND created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Removes finished notification_events rows (their outcome is only
	 * ever used transiently by the dispatcher, so there's no reason to
	 * keep them once fanned out - unlike notification_queue rows, they
	 * have no dedup_key that needs the row to keep existing).
	 */
	private static function purge_notification_events() {
		global $wpdb;
		$table = ssm_table( 'notification_events' );

		$retention_days = (int) apply_filters( 'ssm_notification_events_retention_days', 30 );
		if ( $retention_days <= 0 ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status IN ('done','failed') AND created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
