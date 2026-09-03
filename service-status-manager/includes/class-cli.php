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
use ServiceStatusManager\Notifications\NotificationDispatcher;

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
	 * Fans out pending notification events and dispatches due notifications
	 * from the queue - the same dispatcher the cron endpoint and WP-Cron
	 * use, just run once from the command line.
	 *
	 * ## OPTIONS
	 *
	 * [--channel=<channel>]
	 * : Only process one channel (email|sms|teams). Defaults to all.
	 *
	 * [--time-budget=<seconds>]
	 * : How long this run is allowed to work before handing off to a
	 * chained run. Since WP-CLI has no execution time limit, this defaults
	 * to a larger budget (60s) than a typical web-triggered run gets, so a
	 * single invocation makes more progress through a large backlog.
	 *
	 * ## EXAMPLES
	 *
	 *     wp service-status process
	 *     wp service-status process --channel=sms --time-budget=120
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function process( $args, $assoc_args ) {
		$channel     = sanitize_key( $assoc_args['channel'] ?? 'all' );
		$time_budget = isset( $assoc_args['time-budget'] ) ? max( 1, (int) $assoc_args['time-budget'] ) : 60;

		$result = NotificationDispatcher::run_once( $channel, $time_budget, 'cli' );

		\WP_CLI::success(
			sprintf(
				'Fanned out %d event(s); sent %d, failed %d notification(s).%s',
				$result['events_fanned_out'],
				$result['rows_sent'],
				$result['rows_failed'],
				$result['chained'] ? ' More work remains and was handed off to a chained run.' : ''
			)
		);
	}

	/**
	 * Deprecated alias for `process` (kept working for existing crontabs
	 * written before that command existed).
	 *
	 * ## EXAMPLES
	 *
	 *     wp service-status process-notifications
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function process_notifications( $args, $assoc_args ) {
		\WP_CLI::warning( '`process-notifications` is deprecated - use `wp service-status process` instead. This alias will keep working.' );
		$this->process( $args, $assoc_args );
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
