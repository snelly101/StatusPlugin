<?php
/**
 * Orchestrates monitor checks: resolves the correct provider for each
 * monitor, executes it, records the result, applies failure/recovery
 * thresholds, and triggers automatic incident creation/resolution.
 *
 * Entry point for three callers that all need to behave identically:
 * the WP-Cron event (Cron::RUN_MONITOR_CHECKS), the WP-CLI command
 * (`wp service-status run-checks`), and the secured HTTP cron endpoint
 * (Api\CronEndpoint) for real system cron - see class docblocks there for
 * why WP-Cron alone is not sufficient for reliable sub-minute monitoring.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Monitoring;

use ServiceStatusManager\MonitorManager;
use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\IncidentManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MonitorRunner {

	const LOCK_KEY = 'ssm_monitor_run_lock';

	/**
	 * Runs every monitor whose next check is due, honouring a run lock so
	 * overlapping triggers (WP-Cron plus a real system cron, for example)
	 * never check the same monitor twice concurrently.
	 *
	 * @param bool $ignore_schedule When true, runs every active
	 *                              non-manual monitor regardless of
	 *                              next_check_at (used by the "Run checks
	 *                              now" admin tool and WP-CLI).
	 * @return int Number of monitors checked.
	 */
	public static function run_due_checks( $ignore_schedule = false ) {
		if ( false !== get_transient( self::LOCK_KEY ) ) {
			\ssm_log( 'Monitor run skipped: another run is already in progress.', 'debug' );
			return 0;
		}

		set_transient( self::LOCK_KEY, time(), MINUTE_IN_SECONDS );

		try {
			$batch_size = (int) apply_filters( 'ssm_monitor_batch_size', 50 );
			$monitors   = $ignore_schedule ? self::get_all_active_monitors() : MonitorManager::get_due_monitors( $batch_size );

			foreach ( $monitors as $monitor ) {
				self::run_single_check( $monitor );
			}

			return count( $monitors );
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * @return array Every active, non-manual monitor.
	 */
	private static function get_all_active_monitors() {
		global $wpdb;
		$table = \ssm_table( 'monitors' );
		$rows  = $wpdb->get_results( "SELECT id FROM {$table} WHERE is_active = 1 AND type != 'manual'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$monitors = array();
		foreach ( $rows as $row ) {
			$monitor = MonitorManager::get_monitor( $row->id );
			if ( $monitor ) {
				$monitors[] = $monitor;
			}
		}

		return $monitors;
	}

	/**
	 * Resolves the MonitorProviderInterface implementation for a monitor
	 * type, allowing third-party types to plug in via
	 * `ssm_monitor_provider_instance`.
	 *
	 * @param string $type Monitor type slug.
	 * @return MonitorProviderInterface|null
	 */
	public static function get_provider( $type ) {
		$provider = null;

		switch ( $type ) {
			case 'manual':
				$provider = new ManualMonitor();
				break;
			case 'http':
				$provider = new HttpMonitor();
				break;
			case 'tcp':
				$provider = new TcpMonitor();
				break;
		}

		/**
		 * Filters the monitor provider instance used for a given type,
		 * allowing third-party monitor types (Ping, DNS, SMTP, Microsoft
		 * 365, API, NinjaOne, PRTG, Auvik, Veeam, WatchGuard, webhook, etc.)
		 * to register themselves.
		 *
		 * @param MonitorProviderInterface|null $provider Resolved provider, or null.
		 * @param string                        $type     Monitor type slug.
		 */
		return apply_filters( 'ssm_monitor_provider_instance', $provider, $type );
	}

	/**
	 * Executes and records a single monitor check, then applies
	 * failure/recovery threshold logic.
	 *
	 * @param object $monitor Decorated monitor row.
	 */
	public static function run_single_check( $monitor ) {
		$provider = self::get_provider( $monitor->type );

		if ( ! $provider ) {
			\ssm_log( sprintf( 'No monitor provider registered for type "%s" (monitor #%d).', $monitor->type, $monitor->id ), 'error' );
			return;
		}

		try {
			$result = $provider->check( $monitor );
		} catch ( \Throwable $e ) {
			$result = new CheckResult( false, null, null, $e->getMessage() );
			\ssm_log( sprintf( 'Monitor #%d check threw an exception: %s', $monitor->id, $e->getMessage() ), 'error' );
		}

		self::record_check( $monitor, $result );
		self::apply_thresholds( $monitor, $result );
		self::schedule_next_check( $monitor );
	}

	/**
	 * Inserts the raw check result row (subject to the configured
	 * retention period - see Cleanup).
	 *
	 * @param object      $monitor Monitor row.
	 * @param CheckResult $result  Check result.
	 */
	private static function record_check( $monitor, CheckResult $result ) {
		global $wpdb;

		$wpdb->insert(
			\ssm_table( 'monitor_checks' ),
			array(
				'monitor_id'       => $monitor->id,
				'checked_at'       => \ssm_now(),
				'result'           => $result->success ? 'up' : 'down',
				'response_time_ms' => $result->response_time_ms,
				'http_status'      => $result->http_status,
				'error_message'    => $result->error_message,
				'ssl_expiry_days'  => $result->ssl_expiry_days,
			)
		);

		$wpdb->update(
			\ssm_table( 'monitors' ),
			array(
				'last_checked_at'       => \ssm_now(),
				'last_response_time_ms' => $result->response_time_ms,
				'last_error'            => $result->error_message,
			),
			array( 'id' => $monitor->id )
		);
	}

	/**
	 * Applies consecutive failure/recovery threshold logic, updates the
	 * monitor's current_state, recalculates its service's status, and
	 * triggers automatic incident creation/resolution as needed.
	 *
	 * @param object      $monitor Monitor row (pre-update values).
	 * @param CheckResult $result  Latest check result.
	 */
	private static function apply_thresholds( $monitor, CheckResult $result ) {
		global $wpdb;
		$table = \ssm_table( 'monitors' );

		if ( $result->success ) {
			$consecutive_failures  = 0;
			$consecutive_successes = $monitor->consecutive_successes + 1;

			$new_state = $monitor->current_state;

			$was_failing = in_array( $monitor->current_state, array( 'major_outage', 'partial_outage', 'unknown' ), true );
			if ( $was_failing && $consecutive_successes >= $monitor->recovery_threshold ) {
				$new_state = self::response_time_state( $monitor, $result );
				self::handle_recovery( $monitor );
			} elseif ( ! $was_failing ) {
				$new_state = self::response_time_state( $monitor, $result );
			}

			$wpdb->update(
				$table,
				array(
					'consecutive_failures'  => $consecutive_failures,
					'consecutive_successes' => $consecutive_successes,
					'current_state'         => $new_state,
					'updated_at'            => \ssm_now(),
				),
				array( 'id' => $monitor->id )
			);
		} else {
			$consecutive_failures  = $monitor->consecutive_failures + 1;
			$consecutive_successes = 0;
			$new_state              = $monitor->current_state;

			if ( $consecutive_failures >= $monitor->failure_threshold && 'major_outage' !== $monitor->current_state ) {
				$new_state = 'major_outage';
				self::handle_failure( $monitor, $result, $consecutive_failures );
			}

			$wpdb->update(
				$table,
				array(
					'consecutive_failures'  => $consecutive_failures,
					'consecutive_successes' => $consecutive_successes,
					'current_state'         => $new_state,
					'updated_at'            => \ssm_now(),
				),
				array( 'id' => $monitor->id )
			);
		}

		ServiceManager::recalculate_status( $monitor->service_id );
	}

	/**
	 * Maps a successful check's response time against the monitor's
	 * configured warning/critical thresholds to a status slug.
	 *
	 * @param object      $monitor Monitor row.
	 * @param CheckResult $result  Check result.
	 * @return string
	 */
	private static function response_time_state( $monitor, CheckResult $result ) {
		if ( null === $result->response_time_ms ) {
			return 'operational';
		}
		if ( $monitor->critical_response_ms && $result->response_time_ms >= $monitor->critical_response_ms ) {
			return 'partial_outage';
		}
		if ( $monitor->warning_response_ms && $result->response_time_ms >= $monitor->warning_response_ms ) {
			return 'degraded';
		}
		return 'operational';
	}

	/**
	 * Creates (or reuses, to avoid duplicates) an automatic incident when
	 * a monitor crosses its failure threshold.
	 *
	 * @param object      $monitor              Monitor row.
	 * @param CheckResult $result               Latest failing check result.
	 * @param int         $consecutive_failures Number of consecutive failures observed.
	 */
	private static function handle_failure( $monitor, CheckResult $result, $consecutive_failures ) {
		\ssm_log( sprintf( 'Monitor #%d "%s" reached its failure threshold (%d consecutive failures).', $monitor->id, $monitor->name, $consecutive_failures ), 'warning' );

		if ( empty( $monitor->auto_incident ) ) {
			return;
		}

		$dedup_key = 'monitor-failure-' . $monitor->id;

		if ( IncidentManager::get_open_incident_by_dedup_key( $dedup_key ) ) {
			return;
		}

		IncidentManager::create_incident(
			array(
				'title'            => sprintf(
					/* translators: %s: monitor name */
					__( '%s is experiencing issues', 'service-status-manager' ),
					$monitor->name
				),
				'description'      => sprintf(
					/* translators: 1: monitor name, 2: failure reason */
					__( 'Automated monitoring detected a problem with %1$s: %2$s', 'service-status-manager' ),
					$monitor->name,
					$result->error_message ? $result->error_message : __( 'the check did not succeed.', 'service-status-manager' )
				),
				'severity'         => 'major',
				'status'           => 'investigating',
				'service_ids'      => array( $monitor->service_id ),
				'monitor_ids'      => array( $monitor->id ),
				'auto_created'     => true,
				'dedup_key'        => $dedup_key,
				'notify'           => true,
				'is_public'        => true,
				'initial_message'  => sprintf(
					/* translators: 1: consecutive failure count, 2: failure reason */
					__( 'Automated monitoring recorded %1$d consecutive failed checks. Last error: %2$s', 'service-status-manager' ),
					$consecutive_failures,
					$result->error_message ? $result->error_message : __( 'unknown error', 'service-status-manager' )
				),
			)
		);
	}

	/**
	 * Resolves the automatic incident (if any) associated with a monitor
	 * once it has met its recovery threshold.
	 *
	 * @param object $monitor Monitor row.
	 */
	private static function handle_recovery( $monitor ) {
		$dedup_key = 'monitor-failure-' . $monitor->id;
		$incident  = IncidentManager::get_open_incident_by_dedup_key( $dedup_key );

		if ( ! $incident ) {
			return;
		}

		IncidentManager::add_update(
			$incident->id,
			'resolved',
			__( 'Automated monitoring confirmed the service has recovered.', 'service-status-manager' )
		);
	}

	/**
	 * Schedules a monitor's next check time.
	 *
	 * @param object $monitor Monitor row.
	 */
	private static function schedule_next_check( $monitor ) {
		global $wpdb;
		$wpdb->update(
			\ssm_table( 'monitors' ),
			array( 'next_check_at' => gmdate( 'Y-m-d H:i:s', time() + max( 60, (int) $monitor->check_frequency ) ) ),
			array( 'id' => $monitor->id )
		);
	}
}
