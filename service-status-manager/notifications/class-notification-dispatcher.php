<?php
/**
 * Self-chaining, time-boxed dispatcher - the single entry point for
 * processing notifications, whatever triggers it (the cron REST endpoint,
 * WP-Cron, WP-CLI, or the admin "process now" tool).
 *
 * There is no persistent worker process here (shared hosting has no
 * shell/SSH access, so systemd/Supervisor-managed daemons are simply not
 * deployable). Instead, run_once() works through as much of the queue as
 * it can within a computed time budget, then - if work remains when the
 * budget runs out - fires one more non-blocking loopback request so the
 * next hop picks up immediately rather than waiting for the next WP-Cron
 * tick. In practice this behaves like a worker that "runs" for a few
 * seconds at a time, chained back-to-back, rather than one that runs
 * forever.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

use ServiceStatusManager\NotificationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NotificationDispatcher {

	const CHANNELS = array( 'email', 'sms', 'teams' );

	const RUN_LOCK_KEY = 'ssm_notify_dispatch_lock';

	/**
	 * Fans out due notification_events and dispatches due notification_queue
	 * rows until either the queue is empty or the time budget runs out. If
	 * budget ran out with work remaining, chains to another hop.
	 *
	 * @param string   $channel        'all' or a single channel (email|sms|teams).
	 * @param float|null $time_budget  Seconds to run for. Defaults to resolve_time_budget().
	 * @param string   $trigger_source For the notification_runs log: cron_endpoint|wp_cron|cli|admin|cron_chain.
	 * @return array{events_fanned_out:int,rows_claimed:int,rows_sent:int,rows_failed:int,chained:bool,run_id:string}
	 */
	public static function run_once( $channel = 'all', $time_budget = null, $trigger_source = 'manual' ) {
		if ( self::run_lock_held() ) {
			return array(
				'events_fanned_out' => 0,
				'rows_claimed'      => 0,
				'rows_sent'         => 0,
				'rows_failed'       => 0,
				'chained'           => false,
				'run_id'            => '',
				'skipped_reason'    => 'already_running',
			);
		}

		$start  = microtime( true );
		$budget = self::resolve_time_budget( $time_budget );

		self::acquire_run_lock( $budget );

		global $wpdb;
		$runs_table = \ssm_table( 'notification_runs' );
		$run_id     = substr( wp_generate_password( 20, false, false ), 0, 20 );

		$wpdb->insert(
			$runs_table,
			array(
				'run_id'         => $run_id,
				'trigger_source' => sanitize_key( $trigger_source ),
				'started_at'     => \ssm_now(),
			)
		);

		$channels = 'all' === $channel ? self::CHANNELS : array( sanitize_key( $channel ) );

		NotificationQueue::reclaim_expired();

		$totals = array(
			'events_fanned_out' => 0,
			'rows_claimed'      => 0,
			'rows_sent'         => 0,
			'rows_failed'       => 0,
		);

		while ( ( microtime( true ) - $start ) < $budget ) {
			$fanned = NotificationManager::fan_out_pending_events( (int) apply_filters( 'ssm_notification_fanout_batch_size', 50 ) );
			$totals['events_fanned_out'] += $fanned;

			$claimed_this_pass = 0;

			foreach ( $channels as $ch ) {
				$batch_size = (int) apply_filters( 'ssm_notification_dispatch_batch_size', 100, $ch );
				$rows       = NotificationQueue::claim_batch( $ch, $batch_size, $run_id );

				if ( empty( $rows ) ) {
					continue;
				}

				$claimed_this_pass      += count( $rows );
				$totals['rows_claimed'] += count( $rows );

				$result = NotificationQueue::dispatch_claimed_rows( $rows );
				$totals['rows_sent']   += $result['sent'];
				$totals['rows_failed'] += $result['failed'];
			}

			self::extend_run_lock( $budget );

			if ( 0 === $fanned && 0 === $claimed_this_pass ) {
				break;
			}
		}

		$ran_out_of_time = ( microtime( true ) - $start ) >= $budget;
		$chained         = false;

		if ( $ran_out_of_time && self::has_remaining_work() ) {
			self::trigger_chained_hop();
			$chained = true;
		}

		self::release_run_lock();

		$wpdb->update(
			$runs_table,
			array(
				'finished_at'        => \ssm_now(),
				'events_fanned_out'  => $totals['events_fanned_out'],
				'rows_claimed'       => $totals['rows_claimed'],
				'rows_sent'          => $totals['rows_sent'],
				'rows_failed'        => $totals['rows_failed'],
				'channels_processed' => implode( ',', $channels ),
				'chained_next'       => $chained ? 1 : 0,
			),
			array( 'run_id' => $run_id )
		);

		update_option( 'ssm_last_notification_run', \ssm_now(), false );

		return array_merge( $totals, array( 'chained' => $chained, 'run_id' => $run_id ) );
	}

	/**
	 * @param float|null $requested Explicit override, if any.
	 * @return float Seconds this run is allowed to work for.
	 */
	private static function resolve_time_budget( $requested ) {
		if ( null !== $requested ) {
			return max( 1.0, (float) $requested );
		}

		$default = (float) apply_filters( 'ssm_notification_dispatch_default_budget', 20 );

		$max_execution = (int) ini_get( 'max_execution_time' );
		if ( $max_execution <= 0 ) {
			// 0 means "unlimited" (typical for WP-CLI) - the configured
			// default is the whole story in that case.
			return $default;
		}

		$safety_margin = (float) apply_filters( 'ssm_notification_dispatch_safety_margin', 5 );
		$available      = max( 1.0, $max_execution - $safety_margin );

		return min( $default, $available );
	}

	/**
	 * @return bool True if there are due queue rows or pending events left to work on.
	 */
	private static function has_remaining_work() {
		global $wpdb;

		$queue_table  = \ssm_table( 'notification_queue' );
		$events_table = \ssm_table( 'notification_events' );

		$due_row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$queue_table} WHERE status IN ('pending','retry_scheduled') AND next_attempt_at <= %s LIMIT 1",
				\ssm_now()
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $due_row ) {
			return true;
		}

		$pending_event = $wpdb->get_var( "SELECT 1 FROM {$events_table} WHERE status = 'pending' LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return (bool) $pending_event;
	}

	/**
	 * Fires a non-blocking loopback to the cron REST endpoint with
	 * scope=notifications, so the next hop skips monitor checks and
	 * maintenance transitions and goes straight back into run_once().
	 * Debounced the same way NotificationQueue::trigger_immediate_processing()
	 * is, so a burst of chained hops from overlapping runs collapses to one.
	 */
	private static function trigger_chained_hop() {
		if ( get_transient( 'ssm_notify_chain_lock' ) ) {
			return;
		}
		set_transient( 'ssm_notify_chain_lock', 1, 2 );

		$token = \ssm_get_setting( 'cron_secret', '' );
		if ( '' === $token ) {
			return;
		}

		$url = add_query_arg(
			array( 'token' => $token, 'scope' => 'notifications' ),
			rest_url( 'service-status-manager/v1/cron/run' )
		);

		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	/**
	 * @return bool True if another run currently holds the lock.
	 */
	private static function run_lock_held() {
		return (bool) get_transient( self::RUN_LOCK_KEY );
	}

	/**
	 * @param float $budget This run's time budget, used to size the lock's lifetime.
	 */
	private static function acquire_run_lock( $budget ) {
		set_transient( self::RUN_LOCK_KEY, 1, (int) $budget + 15 );
	}

	/**
	 * Re-extends the lock on each chained-hop pass, so a long-running
	 * dispatch (many hops back-to-back) doesn't have its own lock expire
	 * out from under it mid-run.
	 *
	 * @param float $budget This run's time budget.
	 */
	private static function extend_run_lock( $budget ) {
		set_transient( self::RUN_LOCK_KEY, 1, (int) $budget + 15 );
	}

	/**
	 * Releases the run lock at the end of a pass (whether or not it chains
	 * to another hop - the chained hop acquires its own lock when it runs).
	 */
	private static function release_run_lock() {
		delete_transient( self::RUN_LOCK_KEY );
	}
}
