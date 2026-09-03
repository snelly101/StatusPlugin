<?php
/**
 * WP-CLI commands: `wp service-status queue <command>`.
 *
 * Registered as its own nested command group (rather than flat methods on
 * Cli) so `queue status`/`queue retry-failed` read naturally as two words,
 * matching how WP-CLI itself nests things like `wp cron event`.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

use ServiceStatusManager\Notifications\NotificationQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CliQueue {

	/**
	 * Shows queue depth per channel/status, and the age of the oldest due
	 * row - a quick way to see whether anything is backing up without
	 * opening the admin dashboard.
	 *
	 * ## OPTIONS
	 *
	 * [--channel=<channel>]
	 * : Only show one channel (email|sms|teams). Defaults to all three.
	 *
	 * ## EXAMPLES
	 *
	 *     wp service-status queue status
	 *     wp service-status queue status --channel=sms
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		global $wpdb;
		$table = \ssm_table( 'notification_queue' );

		$channels = isset( $assoc_args['channel'] ) ? array( sanitize_key( $assoc_args['channel'] ) ) : array( 'email', 'sms', 'teams' );

		$rows = array();

		foreach ( $channels as $channel ) {
			$counts = $wpdb->get_results(
				$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$table} WHERE channel = %s GROUP BY status", $channel ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);

			$by_status = array();
			foreach ( $counts as $row ) {
				$by_status[ $row->status ] = (int) $row->total;
			}

			$oldest_due = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MIN(next_attempt_at) FROM {$table} WHERE channel = %s AND status IN ('pending','retry_scheduled') AND next_attempt_at <= %s",
					$channel,
					\ssm_now()
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			$rows[] = array(
				'channel'          => $channel,
				'pending'          => $by_status['pending'] ?? 0,
				'retry_scheduled'  => $by_status['retry_scheduled'] ?? 0,
				'processing'       => $by_status['processing'] ?? 0,
				'sent'             => $by_status['sent'] ?? 0,
				'failed'           => $by_status['failed'] ?? 0,
				'cancelled'        => $by_status['cancelled'] ?? 0,
				'oldest_due_since' => $oldest_due ? \ssm_format_datetime( $oldest_due ) : '-',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'channel', 'pending', 'retry_scheduled', 'processing', 'sent', 'failed', 'cancelled', 'oldest_due_since' ) );

		$last_run = get_option( 'ssm_last_notification_run' );
		\WP_CLI::log( 'Last dispatcher run: ' . ( $last_run ? \ssm_format_datetime( $last_run ) : 'never' ) );
	}

	/**
	 * Resets failed notifications back to pending so they're picked up by
	 * the next dispatcher run.
	 *
	 * ## OPTIONS
	 *
	 * [--channel=<channel>]
	 * : Only retry one channel (email|sms|teams). Defaults to all.
	 *
	 * [--limit=<number>]
	 * : Maximum rows to retry in one call. Default 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp service-status queue retry-failed
	 *     wp service-status queue retry-failed --channel=email --limit=500
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function retry_failed( $args, $assoc_args ) {
		global $wpdb;
		$table = \ssm_table( 'notification_queue' );

		$limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 100;

		if ( isset( $assoc_args['channel'] ) ) {
			$channel = sanitize_key( $assoc_args['channel'] );
			$ids     = $wpdb->get_col(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'failed' AND channel = %s ORDER BY id ASC LIMIT %d", $channel, $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		} else {
			$ids = $wpdb->get_col(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'failed' ORDER BY id ASC LIMIT %d", $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		}

		foreach ( $ids as $id ) {
			NotificationQueue::retry( (int) $id );
		}

		\WP_CLI::success( sprintf( 'Reset %d failed notification(s) back to pending.', count( $ids ) ) );
	}
}
