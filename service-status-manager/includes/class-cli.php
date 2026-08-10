<?php
/**
 * WP-CLI commands: `wp service-status <command>`.
 *
 * These exist so monitoring and maintenance can be driven by a real
 * system cron job instead of relying on WP-Cron, which only fires on
 * incoming site traffic and cannot reliably guarantee sub-minute (or even
 * sub-hour, on a low-traffic site) execution. A typical production crontab
 * entry looks like:
 *
 *   * * * * * cd /path/to/wordpress && wp service-status run-checks --quiet
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

use ServiceStatusManager\Monitoring\MonitorRunner;
use ServiceStatusManager\Notifications\NotificationQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cli {

	/**
	 * Runs every monitor whose next check is due.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Check every active monitor immediately, ignoring each monitor's
	 * next-check schedule.
	 *
	 * ## EXAMPLES
	 *
	 *     wp service-status run-checks
	 *     wp service-status run-checks --all
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function run_checks( $args, $assoc_args ) {
		$count = MonitorRunner::run_due_checks( ! empty( $assoc_args['all'] ) );
		\WP_CLI::success( sprintf( 'Checked %d monitor(s).', $count ) );
	}

	/**
	 * Processes a batch of pending notifications from the queue.
	 *
	 * ## EXAMPLES
	 *
	 *     wp service-status process-notifications
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function process_notifications( $args, $assoc_args ) {
		$count = NotificationQueue::process_batch();
		\WP_CLI::success( sprintf( 'Processed %d notification(s).', $count ) );
	}

	/**
	 * Rolls raw monitor check data up into hourly and daily aggregates.
	 *
	 * ## EXAMPLES
	 *
	 *     wp service-status aggregate
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function aggregate( $args, $assoc_args ) {
		UptimeAggregator::aggregate_hourly();
		UptimeAggregator::aggregate_daily();
		\WP_CLI::success( 'Uptime data aggregated.' );
	}

	/**
	 * Runs the scheduled maintenance status transition + reminder job.
	 *
	 * ## EXAMPLES
	 *
	 *     wp service-status process-maintenance
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function process_maintenance( $args, $assoc_args ) {
		MaintenanceManager::process_transitions();
		\WP_CLI::success( 'Maintenance transitions processed.' );
	}

	/**
	 * Deletes data older than the configured retention periods.
	 *
	 * ## EXAMPLES
	 *
	 *     wp service-status cleanup
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cleanup( $args, $assoc_args ) {
		Cleanup::run();
		\WP_CLI::success( 'Cleanup complete.' );
	}
}
