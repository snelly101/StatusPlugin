<?php
/**
 * The notification queue: every outbound email/SMS/Teams message is
 * written here first and dispatched asynchronously by a scheduled batch
 * processor (Cron::PROCESS_NOTIFICATIONS), never synchronously during an
 * admin request or monitor check.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

use ServiceStatusManager\SubscriberManager;
use ServiceStatusManager\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NotificationQueue {

	const SLUG_SMTP2GO = 'smtp2go';
	const SLUG_WP_MAIL  = 'wp_mail';

	/**
	 * Adds a notification to the queue. Silently deduplicates: if a row
	 * with the same dedup_key already exists (e.g. from an overlapping
	 * cron run), this is a no-op rather than an error.
	 *
	 * @param array $args {
	 *     @type int    $subscriber_id
	 *     @type string $channel         email|sms|teams
	 *     @type string $event_type      e.g. incident_created, maintenance_started
	 *     @type string $reference_type  incident|maintenance|subscriber
	 *     @type int    $reference_id
	 *     @type array  $payload         Rendered message context (subject, body_html, body_text, sms_summary, url, severity, ...).
	 *     @type string $dedup_key
	 *     @type int    $priority        0 (highest) - 5 (lowest). Defaults to 3. See NotificationManager::priority_for().
	 *     @type string $next_attempt_at Optional MySQL datetime; defaults to now (subject to quiet-hours deferral for SMS).
	 * }
	 * @return int|false New queue row ID, or false if it was a duplicate.
	 */
	public static function enqueue( array $args ) {
		global $wpdb;

		$channel = sanitize_key( $args['channel'] );

		$next_attempt_at = $args['next_attempt_at'] ?? \ssm_now();
		if ( 'sms' === $channel ) {
			$next_attempt_at = self::apply_quiet_hours( $next_attempt_at, $args['payload']['severity'] ?? 'informational' );
		}

		$wpdb->suppress_errors( true );

		$inserted = $wpdb->insert(
			\ssm_table( 'notification_queue' ),
			array(
				'dedup_key'       => substr( $args['dedup_key'], 0, 190 ),
				'subscriber_id'   => absint( $args['subscriber_id'] ),
				'channel'         => $channel,
				'event_type'      => sanitize_key( $args['event_type'] ),
				'reference_type'  => isset( $args['reference_type'] ) ? sanitize_key( $args['reference_type'] ) : null,
				'reference_id'    => isset( $args['reference_id'] ) ? absint( $args['reference_id'] ) : null,
				'payload'         => wp_json_encode( $args['payload'] ?? array() ),
				'status'          => 'pending',
				'priority'        => self::clamp_priority( $args['priority'] ?? 3 ),
				'attempts'        => 0,
				'max_attempts'    => (int) apply_filters( 'ssm_notification_max_attempts', 5 ),
				'next_attempt_at' => $next_attempt_at,
				'created_at'      => \ssm_now(),
			)
		);

		$wpdb->suppress_errors( false );

		if ( ! $inserted ) {
			\ssm_log(
				sprintf(
					'Notification queue: insert skipped for subscriber #%d, channel %s, event %s (likely a duplicate dedup_key from an earlier attempt, or a DB error: %s).',
					absint( $args['subscriber_id'] ?? 0 ),
					$channel,
					sanitize_key( $args['event_type'] ?? '' ),
					$wpdb->last_error ? $wpdb->last_error : 'none reported'
				),
				'debug'
			);
			return false;
		}

		self::trigger_immediate_processing();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Bulk-inserts many queue rows in one round trip, for fan-outs large
	 * enough (hundreds to thousands of subscriber-channel pairs) that one
	 * enqueue() call per row would mean one round trip per row. Duplicate
	 * dedup_keys are silently skipped (INSERT IGNORE), matching enqueue()'s
	 * existing "duplicate is a no-op, not an error" semantics.
	 *
	 * Callers are expected to chunk large sets themselves (~500 rows per
	 * call is a reasonable batch size) - this method does not chunk
	 * internally, so a single call building one enormous VALUES list is
	 * the caller's own choice.
	 *
	 * Unlike enqueue(), reference_type/reference_id are required on every
	 * row (not nullable) - the only current callers (NotificationManager's
	 * incident/maintenance fan-out) always have them; the low-volume
	 * subscriber-management messages that legitimately omit them still go
	 * through enqueue().
	 *
	 * @param array[] $rows Each shaped like enqueue()'s $args, minus next_attempt_at chunking concerns (still honoured per-row).
	 * @return int Number of rows actually inserted (excludes duplicates).
	 */
	public static function enqueue_many( array $rows ) {
		global $wpdb;

		if ( empty( $rows ) ) {
			return 0;
		}

		$table        = \ssm_table( 'notification_queue' );
		$now          = \ssm_now();
		$max_attempts = (int) apply_filters( 'ssm_notification_max_attempts', 5 );

		$placeholders = array();
		$values       = array();

		foreach ( $rows as $args ) {
			$channel = sanitize_key( $args['channel'] );

			$next_attempt_at = $args['next_attempt_at'] ?? $now;
			if ( 'sms' === $channel ) {
				$next_attempt_at = self::apply_quiet_hours( $next_attempt_at, $args['payload']['severity'] ?? 'informational' );
			}

			$placeholders[] = '(%s,%d,%s,%s,%s,%d,%s,%s,%d,%d,%d,%s,%s)';
			array_push(
				$values,
				substr( $args['dedup_key'], 0, 190 ),
				absint( $args['subscriber_id'] ),
				$channel,
				sanitize_key( $args['event_type'] ),
				sanitize_key( $args['reference_type'] ),
				absint( $args['reference_id'] ),
				wp_json_encode( $args['payload'] ?? array() ),
				'pending',
				self::clamp_priority( $args['priority'] ?? 3 ),
				0,
				$max_attempts,
				$next_attempt_at,
				$now
			);
		}

		$sql = "INSERT IGNORE INTO {$table}
			(dedup_key, subscriber_id, channel, event_type, reference_type, reference_id, payload, status, priority, attempts, max_attempts, next_attempt_at, created_at)
			VALUES " . implode( ',', $placeholders );

		$wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$inserted = (int) $wpdb->rows_affected;

		self::trigger_immediate_processing();

		return $inserted;
	}

	/**
	 * @param mixed $priority Raw priority value.
	 * @return int Clamped to 0-5.
	 */
	private static function clamp_priority( $priority ) {
		return max( 0, min( 5, (int) $priority ) );
	}

	/**
	 * Atomically claims up to $limit due rows for a single channel in one
	 * round trip - a multi-table UPDATE...JOIN against a derived table,
	 * which MySQL 5.7+/MariaDB 10.2+ both allow (the "can't specify target
	 * table for update in FROM clause" restriction doesn't apply to a
	 * subquery aliased in a JOIN, since it's materialised as a temp table
	 * before the join runs). No SKIP LOCKED dependency, so no MySQL 8+/
	 * MariaDB 10.6+ requirement.
	 *
	 * @param string $channel       email|sms|teams.
	 * @param int    $limit         Max rows to claim.
	 * @param string $locked_by     Opaque identifier for this run (observability only - correctness comes from the atomic claim itself, not from this value).
	 * @param int    $lease_seconds How long the claim is held before NotificationQueue::reclaim_expired() will recover it if this run dies mid-dispatch.
	 * @return object[] Claimed rows (status already 'processing').
	 */
	public static function claim_batch( $channel, $limit, $locked_by, $lease_seconds = 120 ) {
		global $wpdb;
		$table = \ssm_table( 'notification_queue' );

		$now           = \ssm_now();
		$lease_expires = gmdate( 'Y-m-d H:i:s', time() + max( 30, (int) $lease_seconds ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} t
				INNER JOIN (
					SELECT id FROM {$table}
					WHERE channel = %s AND status IN ('pending','retry_scheduled') AND next_attempt_at <= %s
					ORDER BY priority ASC, id ASC
					LIMIT %d
				) c ON c.id = t.id
				SET t.status = 'processing', t.locked_by = %s, t.locked_at = %s, t.lease_expires_at = %s",
				$channel,
				$now,
				max( 1, (int) $limit ),
				$locked_by,
				$now,
				$lease_expires
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $wpdb->rows_affected ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE channel = %s AND status = 'processing' AND locked_by = %s ORDER BY priority ASC, id ASC",
				$channel,
				$locked_by
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Recovers rows stuck in 'processing' because the run that claimed
	 * them (via claim_batch()) died before finishing - a PHP fatal error,
	 * the host killing a long-running request, etc. Run at the start of
	 * every NotificationDispatcher pass.
	 *
	 * @return int Number of rows recovered.
	 */
	public static function reclaim_expired() {
		global $wpdb;
		$table = \ssm_table( 'notification_queue' );

		$recovered = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', locked_by = NULL, locked_at = NULL, lease_expires_at = NULL
				WHERE status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < %s",
				\ssm_now()
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $recovered ) {
			\ssm_log( sprintf( 'Notification queue: reclaimed %d row(s) stuck in "processing" past their lease.', $recovered ), 'debug' );
		}

		return (int) $recovered;
	}

	/**
	 * Fires a non-blocking "loopback" request to the plugin's own secured
	 * cron endpoint immediately after something is queued, so messages
	 * are usually sent within a second or two instead of waiting for the
	 * next WP-Cron tick (which only runs on site traffic and can be
	 * delayed well beyond its nominal one-minute schedule on a
	 * low-traffic site).
	 *
	 * This is best-effort: if the request cannot be made - a host that
	 * blocks loopback HTTP requests, for example - nothing is lost,
	 * delivery still happens on the next regular
	 * Cron::PROCESS_NOTIFICATIONS run, or via the "Process notification
	 * queue now" tool.
	 *
	 * Debounced with a short transient lock so a burst of enqueue() calls
	 * (e.g. notifying hundreds of subscribers about one incident) fires a
	 * single loopback request rather than one per message, and never
	 * blocks the caller (a monitor check, an admin saving an incident, a
	 * public subscription request) waiting for it to complete.
	 *
	 * Public so NotificationManager can also call it right after writing a
	 * notification_events row - the deferred fan-out needs the same
	 * "wake the dispatcher up soon" behaviour as a direct enqueue() does.
	 */
	public static function trigger_immediate_processing() {
		if ( get_transient( 'ssm_notify_trigger_lock' ) ) {
			return;
		}
		set_transient( 'ssm_notify_trigger_lock', 1, 3 );

		$token = \ssm_get_setting( 'cron_secret', '' );
		if ( '' === $token ) {
			return;
		}

		$url = add_query_arg( 'token', $token, rest_url( 'service-status-manager/v1/cron/run' ) );

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
	 * Delays an SMS send time to fall outside configured quiet hours,
	 * unless the message is critical and quiet-hours override is enabled.
	 *
	 * @param string $when     Proposed send time (MySQL datetime, UTC).
	 * @param string $severity Message severity slug.
	 * @return string
	 */
	private static function apply_quiet_hours( $when, $severity ) {
		$start = \ssm_get_setting( 'quiet_hours_start' );
		$end   = \ssm_get_setting( 'quiet_hours_end' );

		if ( ! $start || ! $end ) {
			return $when;
		}
		if ( 'critical' === $severity && apply_filters( 'ssm_critical_overrides_quiet_hours', true ) ) {
			return $when;
		}

		try {
			$tz  = new \DateTimeZone( \ssm_get_setting( 'quiet_hours_timezone', wp_timezone_string() ) );
			$now = new \DateTime( $when, new \DateTimeZone( 'UTC' ) );
			$now->setTimezone( $tz );

			$today_start = new \DateTime( $now->format( 'Y-m-d' ) . ' ' . $start, $tz );
			$today_end   = new \DateTime( $now->format( 'Y-m-d' ) . ' ' . $end, $tz );

			$in_quiet_hours = $today_end > $today_start
				? ( $now >= $today_start && $now < $today_end )
				: ( $now >= $today_start || $now < $today_end ); // Overnight window, e.g. 22:00-07:00.

			if ( ! $in_quiet_hours ) {
				return $when;
			}

			$defer_until = $today_end > $today_start ? $today_end : ( $now < $today_end ? $today_end : ( clone $today_end )->modify( '+1 day' ) );
			$defer_until->setTimezone( new \DateTimeZone( 'UTC' ) );

			return $defer_until->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return $when;
		}
	}

	/**
	 * Processes a batch of due notifications (pending or retry-scheduled)
	 * across the whole queue. This is the cron/CLI/manual-tool entry
	 * point, and is deliberately the only path that can touch a large
	 * backlog - callers that only care about one subscriber's own
	 * messages should use process_for_subscriber() instead, so they
	 * never end up blocked processing someone else's backlog.
	 *
	 * @return int Number of notifications processed.
	 */
	public static function process_batch() {
		global $wpdb;
		$table = \ssm_table( 'notification_queue' );

		$batch_size = (int) apply_filters( 'ssm_notification_batch_size', 100 );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN ('pending','retry_scheduled') AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d",
				\ssm_now(),
				$batch_size
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$processed = self::process_rows( $rows );

		update_option( 'ssm_last_notification_run', \ssm_now(), false );

		return $processed;
	}

	/**
	 * Processes only the due notifications belonging to a single
	 * subscriber, bounded to a small limit. Used to send a brand-new
	 * subscriber's confirmation message(s) immediately after they submit
	 * the subscription form, without risking a slow request if a large,
	 * unrelated backlog (e.g. a major incident notifying thousands of
	 * other subscribers) happens to be sitting in the shared queue at the
	 * same time.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @param int $limit         Safety cap on rows processed (a real
	 *                           subscriber never has more than a
	 *                           handful of channels).
	 * @return int Number of notifications processed.
	 */
	public static function process_for_subscriber( $subscriber_id, $limit = 10 ) {
		global $wpdb;
		$table = \ssm_table( 'notification_queue' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE subscriber_id = %d AND status IN ('pending','retry_scheduled') AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d",
				absint( $subscriber_id ),
				\ssm_now(),
				max( 1, absint( $limit ) )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return self::process_rows( $rows );
	}

	/**
	 * Claims and dispatches a set of already-selected queue rows. Shared
	 * by process_batch() and process_for_subscriber().
	 *
	 * @param array $rows Queue rows to process.
	 * @return int Number actually processed.
	 */
	private static function process_rows( array $rows ) {
		global $wpdb;
		$table = \ssm_table( 'notification_queue' );

		$processed = 0;

		foreach ( $rows as $row ) {
			// Claim the row atomically so a second, overlapping run (cron,
			// the immediate-processing trigger, and a manual "process now"
			// click can all race) cannot pick up and double-send it.
			$claimed = $wpdb->update(
				$table,
				array( 'status' => 'processing' ),
				array( 'id' => $row->id, 'status' => $row->status )
			);

			if ( ! $claimed ) {
				continue;
			}

			self::dispatch_row( $row );
			++$processed;
		}

		return $processed;
	}

	/**
	 * Dispatches a batch of already-claimed rows (from claim_batch()),
	 * tallying outcomes for the caller's observability (NotificationRuns).
	 *
	 * All rows in one call are expected to share a channel (claim_batch()
	 * claims per-channel) - when the resolved provider for that channel
	 * implements ConcurrentSendProviderInterface, the whole batch is sent
	 * at once via ConcurrentHttpClient instead of one HTTP round trip at a
	 * time; a provider that doesn't falls back to the serial per-row loop,
	 * exactly as before.
	 *
	 * @param object[] $rows    Claimed queue rows.
	 * @param string   $channel email|sms|teams - the channel this batch was claimed for.
	 * @return array{sent:int,failed:int,other:int}
	 */
	public static function dispatch_claimed_rows( array $rows, $channel ) {
		$tally = array( 'sent' => 0, 'failed' => 0, 'other' => 0 );

		if ( empty( $rows ) ) {
			return $tally;
		}

		$provider_slug = self::resolve_provider_slug( $channel );
		$provider      = self::get_provider( $channel );

		if ( $provider instanceof ConcurrentSendProviderInterface && count( $rows ) > 1 && ! NotificationCircuitBreaker::is_open( $provider_slug ) ) {
			return self::dispatch_concurrent( $rows, $channel, $provider, $provider_slug );
		}

		foreach ( $rows as $row ) {
			$outcome = self::dispatch_row( $row );

			if ( isset( $tally[ $outcome ] ) ) {
				++$tally[ $outcome ];
			} else {
				++$tally['other'];
			}
		}

		return $tally;
	}

	/**
	 * Concurrent-batch counterpart to dispatch_row(): applies the same
	 * pre-send gates (breaker was already checked by the caller; monthly/
	 * per-incident SMS limits, subscription gate, per-provider rate limit
	 * are checked per row here) to filter the batch down to sendable rows,
	 * hands the survivors to the provider's send_many() in one call, then
	 * finalizes each result exactly like the serial path does.
	 *
	 * @param object[]                        $rows          Claimed rows, all for $channel.
	 * @param string                          $channel       email|sms|teams.
	 * @param ConcurrentSendProviderInterface $provider      Resolved provider (also implements NotificationProviderInterface).
	 * @param string                          $provider_slug Resolved provider slug.
	 * @return array{sent:int,failed:int,other:int}
	 */
	private static function dispatch_concurrent( array $rows, $channel, $provider, $provider_slug ) {
		global $wpdb;
		$table = \ssm_table( 'notification_queue' );

		$tally      = array( 'sent' => 0, 'failed' => 0, 'other' => 0 );
		$items      = array();
		$rows_by_id = array();
		$rate_limit = self::provider_rate_limit( $provider, $provider_slug );

		foreach ( $rows as $row ) {
			$rows_by_id[ $row->id ] = $row;

			if ( 'sms' === $channel && self::sms_monthly_limit_reached() ) {
				$wpdb->update( $table, array( 'status' => 'cancelled', 'last_error' => 'Monthly SMS limit reached.' ), array( 'id' => $row->id ) );
				++$tally['other'];
				continue;
			}

			if ( 'sms' === $channel && (int) \ssm_get_setting( 'sms_per_incident_limit', 0 ) > 0 && self::sms_per_incident_limit_reached( $row ) ) {
				$wpdb->update( $table, array( 'status' => 'cancelled', 'last_error' => 'Per-incident SMS limit reached.' ), array( 'id' => $row->id ) );
				++$tally['other'];
				continue;
			}

			$subscriber = SubscriberManager::get_subscriber( $row->subscriber_id );
			$gate       = self::gate_check( $subscriber, $channel, $row->event_type );

			if ( true !== $gate ) {
				$wpdb->update( $table, array( 'status' => 'cancelled', 'last_error' => $gate ), array( 'id' => $row->id ) );
				++$tally['other'];
				continue;
			}

			if ( $rate_limit > 0 && NotificationRateLimiter::reserve_capacity( $provider_slug, $rate_limit, 1 ) < 1 ) {
				$wpdb->update(
					$table,
					array( 'status' => 'retry_scheduled', 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 1 ) ),
					array( 'id' => $row->id )
				);
				++$tally['other'];
				continue;
			}

			$items[ $row->id ] = array(
				'subscriber' => $subscriber,
				'payload'    => json_decode( (string) $row->payload, true ) ?: array(),
			);
		}

		if ( empty( $items ) ) {
			return $tally;
		}

		try {
			$results = $provider->send_many( $items );
		} catch ( \Throwable $e ) {
			$fallback = new SendResult( false, '', $e->getMessage() );
			$results  = array_fill_keys( array_keys( $items ), $fallback );
		}

		foreach ( $items as $row_id => $item ) {
			$row     = $rows_by_id[ $row_id ];
			$result  = $results[ $row_id ] ?? new SendResult( false, '', 'Provider did not return a result for this message.' );
			$outcome = self::finalize_send_result( $row, $result, $provider_slug );

			if ( isset( $tally[ $outcome ] ) ) {
				++$tally[ $outcome ];
			} else {
				++$tally['other'];
			}
		}

		return $tally;
	}

	/**
	 * Dispatches a single claimed queue row to its provider and records
	 * the outcome.
	 *
	 * @param object $row Queue row (status already set to "processing").
	 * @return string One of: sent, failed, retry_scheduled, cancelled, other.
	 */
	private static function dispatch_row( $row ) {
		global $wpdb;
		$table = \ssm_table( 'notification_queue' );

		$provider_slug = self::resolve_provider_slug( $row->channel );

		if ( NotificationCircuitBreaker::is_open( $provider_slug ) ) {
			$wpdb->update(
				$table,
				array(
					'status'          => 'retry_scheduled',
					'last_error'      => sprintf( 'Provider "%s" circuit breaker is open; deferring.', $provider_slug ),
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ),
				),
				array( 'id' => $row->id )
			);
			return 'retry_scheduled';
		}

		if ( 'sms' === $row->channel && self::sms_monthly_limit_reached() ) {
			$wpdb->update( $table, array( 'status' => 'cancelled', 'last_error' => 'Monthly SMS limit reached.' ), array( 'id' => $row->id ) );
			return 'cancelled';
		}

		$provider   = self::get_provider( $row->channel );
		$rate_limit = $provider ? self::provider_rate_limit( $provider, $provider_slug ) : 0;

		if ( $rate_limit > 0 && NotificationRateLimiter::reserve_capacity( $provider_slug, $rate_limit, 1 ) < 1 ) {
			$wpdb->update(
				$table,
				array( 'status' => 'retry_scheduled', 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 1 ) ),
				array( 'id' => $row->id )
			);
			return 'retry_scheduled';
		}

		$subscriber = SubscriberManager::get_subscriber( $row->subscriber_id );
		$gate       = self::gate_check( $subscriber, $row->channel, $row->event_type );

		if ( true !== $gate ) {
			$wpdb->update( $table, array( 'status' => 'cancelled', 'last_error' => $gate ), array( 'id' => $row->id ) );
			return 'cancelled';
		}

		$payload = json_decode( (string) $row->payload, true ) ?: array();

		if ( ! $provider ) {
			$wpdb->update( $table, array( 'status' => 'failed', 'last_error' => 'No provider available for channel.' ), array( 'id' => $row->id ) );
			return 'failed';
		}

		if ( 'sms' === $row->channel && (int) \ssm_get_setting( 'sms_per_incident_limit', 0 ) > 0 && self::sms_per_incident_limit_reached( $row ) ) {
			$wpdb->update( $table, array( 'status' => 'cancelled', 'last_error' => 'Per-incident SMS limit reached.' ), array( 'id' => $row->id ) );
			return 'cancelled';
		}

		try {
			$result = $provider->send( $subscriber, $payload );
		} catch ( \Throwable $e ) {
			$result = new SendResult( false, '', $e->getMessage() );
		}

		return self::finalize_send_result( $row, $result, $provider_slug );
	}

	/**
	 * Records a send outcome (success or failure) against a queue row -
	 * shared by the serial (dispatch_row()) and concurrent-batch
	 * (dispatch_concurrent()) paths so retry classification, circuit
	 * breaker updates, and backoff scheduling behave identically either
	 * way.
	 *
	 * @param object     $row           Queue row (status already "processing").
	 * @param SendResult $result        Outcome of the send attempt.
	 * @param string     $provider_slug Resolved provider slug.
	 * @return string One of: sent, failed, retry_scheduled.
	 */
	private static function finalize_send_result( $row, SendResult $result, $provider_slug ) {
		global $wpdb;
		$table = \ssm_table( 'notification_queue' );

		$attempts = (int) $row->attempts + 1;

		self::log_delivery( $row, $result );

		if ( $result->success ) {
			NotificationCircuitBreaker::record_success( $provider_slug );

			if ( 'sms' === $row->channel ) {
				self::increment_sms_monthly_count();
			}

			$wpdb->update(
				$table,
				array(
					'status'              => 'sent',
					'attempts'            => $attempts,
					'sent_at'             => \ssm_now(),
					'provider_message_id' => substr( (string) $result->response, 0, 190 ),
				),
				array( 'id' => $row->id )
			);

			do_action( 'ssm_notification_sent', $row );
			return 'sent';
		}

		$classification = NotificationRetryPolicy::classify( $result->http_code, (string) $result->error, $provider_slug );
		NotificationCircuitBreaker::record_failure( $provider_slug, $classification );

		// A permanent failure (bad recipient, bad credentials) is never
		// worth retrying with backoff - it will fail identically every
		// time, so fail it immediately rather than burning attempts.
		if ( NotificationRetryPolicy::TRANSIENT !== $classification ) {
			$wpdb->update(
				$table,
				array( 'status' => 'failed', 'attempts' => $attempts, 'last_error' => $result->error ),
				array( 'id' => $row->id )
			);
			do_action( 'ssm_notification_failed', $row, $result->error );
			return 'failed';
		}

		if ( $attempts >= (int) $row->max_attempts ) {
			$wpdb->update(
				$table,
				array( 'status' => 'failed', 'attempts' => $attempts, 'last_error' => $result->error ),
				array( 'id' => $row->id )
			);
			do_action( 'ssm_notification_failed', $row, $result->error );
			return 'failed';
		}

		$delay = min( 6 * HOUR_IN_SECONDS, (int) pow( 2, $attempts ) * MINUTE_IN_SECONDS );
		$delay = (int) apply_filters( 'ssm_notification_retry_delay', $delay, $attempts, $row );

		$wpdb->update(
			$table,
			array(
				'status'          => 'retry_scheduled',
				'attempts'        => $attempts,
				'last_error'      => $result->error,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
			),
			array( 'id' => $row->id )
		);

		return 'retry_scheduled';
	}

	/**
	 * Whether the site-wide monthly SMS cap (`sms_monthly_limit` setting,
	 * 0 = unlimited) has been reached. Tracked with a transient counter,
	 * the same lightweight pattern SmsProvider uses for its per-subscriber
	 * daily cap, just keyed globally instead of per-subscriber.
	 *
	 * @return bool
	 */
	private static function sms_monthly_limit_reached() {
		$limit = (int) \ssm_get_setting( 'sms_monthly_limit', 0 );
		if ( $limit <= 0 ) {
			return false;
		}

		return (int) get_transient( self::sms_monthly_counter_key() ) >= $limit;
	}

	/**
	 * Increments the site-wide monthly SMS counter after a successful send.
	 */
	private static function increment_sms_monthly_count() {
		$key   = self::sms_monthly_counter_key();
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, 32 * DAY_IN_SECONDS );
	}

	/**
	 * @return string Transient key for the current calendar month's SMS counter.
	 */
	private static function sms_monthly_counter_key() {
		return 'ssm_sms_monthly_count_' . gmdate( 'Y-m' );
	}

	/**
	 * Whether this row's incident has already had `sms_per_incident_limit`
	 * SMS messages sent for it. Only meaningful for incident-referenced SMS
	 * rows; maintenance and other event types are not subject to this cap.
	 *
	 * @param object $row Queue row about to be dispatched (not yet counted as sent).
	 * @return bool
	 */
	private static function sms_per_incident_limit_reached( $row ) {
		if ( 'incident' !== $row->reference_type || ! $row->reference_id ) {
			return false;
		}

		global $wpdb;
		$table = \ssm_table( 'notification_queue' );
		$limit = (int) \ssm_get_setting( 'sms_per_incident_limit', 0 );

		// Deliberately counts only 'sent', not 'processing': claim_batch()
		// flips a whole batch to 'processing' before any row in it is
		// actually dispatched, so every row in a same-incident batch
		// larger than the limit would otherwise see all its own
		// not-yet-sent siblings as "already used" and every row would
		// cancel itself out - the limit would effectively always trigger
		// once claimed together. Counting only confirmed sends can very
		// rarely let a genuine race (two overlapping runs claiming
		// different batches for the same incident at once) overshoot the
		// cap by a few messages; that's an acceptable trade for a soft
		// cost-control budget, not a hard security limit.
		$sent = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE channel = 'sms' AND reference_type = 'incident' AND reference_id = %d AND status = 'sent'",
				$row->reference_id
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $sent >= $limit;
	}

	/**
	 * Re-checks, at send time, that this notification still satisfies
	 * every subscription rule - a subscriber may have unsubscribed or
	 * paused between the notification being queued and now.
	 *
	 * Verification messages themselves (subscription_confirmation,
	 * teams_verify, management_link) are exempt from the "subscriber must
	 * be active" and "channel must already be verified" checks, since
	 * their entire purpose is to activate/verify a channel that is not
	 * verified yet - the only thing that can legitimately block them is
	 * the subscriber having fully unsubscribed since the link was queued.
	 *
	 * @param object|null $subscriber Subscriber row.
	 * @param string      $channel    Channel.
	 * @param string      $event_type Queue row event type.
	 * @return true|string True if allowed to send, otherwise a cancellation reason.
	 */
	private static function gate_check( $subscriber, $channel, $event_type = '' ) {
		if ( ! $subscriber ) {
			return 'Subscriber no longer exists.';
		}

		$is_verification_message = in_array( $event_type, array( 'subscription_confirmation', 'teams_verify', 'management_link' ), true );

		if ( 'unsubscribed' === $subscriber->status ) {
			return 'Subscriber has unsubscribed.';
		}
		if ( $is_verification_message ) {
			return true;
		}
		if ( 'active' !== $subscriber->status ) {
			return 'Subscriber is not active (status: ' . $subscriber->status . ').';
		}

		global $wpdb;
		$table = \ssm_table( 'subscriber_channels' );
		$channel_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE subscriber_id = %d AND channel = %s", $subscriber->id, $channel ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $channel_row || ! $channel_row->is_active ) {
			return 'Channel is not active for this subscriber.';
		}
		if ( ! $channel_row->verified ) {
			return 'Channel has not been verified.';
		}

		return true;
	}

	/**
	 * Records a delivery attempt in the permanent log table (kept even
	 * after the queue row itself is eventually pruned - see Cleanup).
	 *
	 * @param object     $row    Queue row.
	 * @param SendResult $result Send outcome.
	 */
	private static function log_delivery( $row, SendResult $result ) {
		global $wpdb;

		$wpdb->insert(
			\ssm_table( 'notification_log' ),
			array(
				'queue_id'          => $row->id,
				'subscriber_id'     => $row->subscriber_id,
				'channel'           => $row->channel,
				'event_type'        => $row->event_type,
				'success'           => $result->success ? 1 : 0,
				'provider_response' => $result->response,
				'error_message'     => $result->error,
				'created_at'        => \ssm_now(),
			)
		);
	}

	/**
	 * Resolves the provider instance for a channel. For 'email' this
	 * mirrors resolve_provider_slug()'s decision exactly (including the
	 * circuit-breaker-aware SMTP2GO -> wp_mail fallback), so the instance
	 * returned here is always the one whose slug dispatch_row()/
	 * dispatch_concurrent() are keying breaker/rate-limit state under.
	 *
	 * @param string $channel email|sms|teams.
	 * @return NotificationProviderInterface|null
	 */
	public static function get_provider( $channel ) {
		switch ( $channel ) {
			case 'email':
				return self::SLUG_SMTP2GO === self::resolve_provider_slug( 'email' ) ? new Smtp2goApiProvider() : new EmailProvider();
			case 'sms':
				return self::get_sms_provider();
			case 'teams':
				return new TeamsProvider();
		}

		return apply_filters( 'ssm_notification_provider_instance', null, $channel );
	}

	/**
	 * Resolves which provider *slug* a channel currently dispatches
	 * through - the single source of truth get_provider() mirrors into an
	 * actual instance, and dispatch_row()/dispatch_concurrent() use
	 * directly for circuit-breaker/rate-limit/retry-classification keys.
	 *
	 * For 'email', this is where the SMTP2GO circuit breaker being open
	 * silently becomes "use wp_mail instead" - by the time a caller checks
	 * `NotificationCircuitBreaker::is_open( $slug )`, the slug returned
	 * here has already accounted for that, so there's no separate
	 * "provider is down, what now" branch needed elsewhere.
	 *
	 * @param string $channel email|sms|teams.
	 * @return string
	 */
	public static function resolve_provider_slug( $channel ) {
		if ( 'email' === $channel ) {
			$configured = \ssm_get_setting( 'email_provider', self::SLUG_WP_MAIL );

			if ( self::SLUG_SMTP2GO !== $configured ) {
				return self::SLUG_WP_MAIL;
			}

			$fallback_enabled = (bool) \ssm_get_setting( 'smtp2go_fallback_to_wp_mail', true );

			if ( $fallback_enabled && NotificationCircuitBreaker::is_open( self::SLUG_SMTP2GO ) ) {
				return self::SLUG_WP_MAIL;
			}

			return self::SLUG_SMTP2GO;
		}

		if ( 'sms' === $channel ) {
			return (string) \ssm_get_setting( 'sms_provider', 'twilio' );
		}

		return $channel;
	}

	/**
	 * Resolves the configured SMS provider implementation.
	 *
	 * @return NotificationProviderInterface|null
	 */
	private static function get_sms_provider() {
		$configured = \ssm_get_setting( 'sms_provider', 'twilio' );

		/**
		 * Filters the map of SMS provider slug => class name, so
		 * additional gateways (MessageBird, Vonage, AWS SNS, Esendex,
		 * other UK SMS providers) can register themselves.
		 *
		 * @param array $providers Provider slug => fully-qualified class name.
		 */
		$providers = apply_filters(
			'ssm_sms_providers',
			array(
				'twilio' => TwilioSmsProvider::class,
			)
		);

		if ( isset( $providers[ $configured ] ) && class_exists( $providers[ $configured ] ) ) {
			return new $providers[ $configured ]();
		}

		return null;
	}

	/**
	 * @param NotificationProviderInterface $provider      Resolved provider instance.
	 * @param string                        $provider_slug Its slug (for the generic filter fallback).
	 * @return int Requests-per-second limit. 0 or less means unlimited.
	 */
	private static function provider_rate_limit( $provider, $provider_slug ) {
		if ( $provider instanceof RateLimitedProviderInterface ) {
			return (int) $provider->get_rate_limit_per_second();
		}

		return (int) apply_filters( 'ssm_notification_rate_limit_per_second', 0, $provider_slug );
	}

	/**
	 * Admin action: resets a failed notification back to pending.
	 *
	 * @param int $id Queue row ID.
	 */
	public static function retry( $id ) {
		global $wpdb;
		$wpdb->update(
			\ssm_table( 'notification_queue' ),
			array( 'status' => 'pending', 'next_attempt_at' => \ssm_now(), 'last_error' => null ),
			array( 'id' => absint( $id ) )
		);
		AuditLog::record( 'notification_retried', 'notification', $id );
	}

	/**
	 * Admin action: cancels a pending/failed notification.
	 *
	 * @param int $id Queue row ID.
	 */
	public static function cancel( $id ) {
		global $wpdb;
		$wpdb->update( \ssm_table( 'notification_queue' ), array( 'status' => 'cancelled' ), array( 'id' => absint( $id ) ) );
		AuditLog::record( 'notification_cancelled', 'notification', $id );
	}

	/**
	 * Paginated queue listing for the admin screen.
	 *
	 * @param array $args per_page, paged, channel, status, subscriber_id, reference_type, reference_id.
	 * @return array{items: array, total: int}
	 */
	public static function query_for_admin( array $args = array() ) {
		global $wpdb;
		$table = \ssm_table( 'notification_queue' );

		$args = wp_parse_args( $args, array( 'per_page' => 20, 'paged' => 1, 'channel' => '', 'status' => '', 'subscriber_id' => 0 ) );

		$where  = array( '1=1' );
		$params = array();

		foreach ( array( 'channel' => 'channel', 'status' => 'status' ) as $arg_key => $column ) {
			if ( ! empty( $args[ $arg_key ] ) ) {
				$where[]  = "{$column} = %s";
				$params[] = sanitize_key( $args[ $arg_key ] );
			}
		}
		if ( ! empty( $args['subscriber_id'] ) ) {
			$where[]  = 'subscriber_id = %d';
			$params[] = absint( $args['subscriber_id'] );
		}

		$where_sql = implode( ' AND ', $where );
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

		$sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params = array_merge( $params, array( $per_page, $offset ) );

		return array(
			'items' => $wpdb->get_results( $wpdb->prepare( $sql, $params ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'total' => $total,
		);
	}
}
