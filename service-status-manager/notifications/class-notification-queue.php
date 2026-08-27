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
				'attempts'        => 0,
				'max_attempts'    => (int) apply_filters( 'ssm_notification_max_attempts', 5 ),
				'next_attempt_at' => $next_attempt_at,
				'created_at'      => \ssm_now(),
			)
		);

		$wpdb->suppress_errors( false );

		if ( ! $inserted ) {
			return false;
		}

		self::trigger_immediate_processing();

		return (int) $wpdb->insert_id;
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
	 */
	private static function trigger_immediate_processing() {
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
	 * Dispatches a single claimed queue row to its provider and records
	 * the outcome.
	 *
	 * @param object $row Queue row (status already set to "processing").
	 */
	private static function dispatch_row( $row ) {
		global $wpdb;
		$table = \ssm_table( 'notification_queue' );

		$subscriber = SubscriberManager::get_subscriber( $row->subscriber_id );
		$gate       = self::gate_check( $subscriber, $row->channel, $row->event_type );

		if ( true !== $gate ) {
			$wpdb->update( $table, array( 'status' => 'cancelled', 'last_error' => $gate ), array( 'id' => $row->id ) );
			return;
		}

		$payload = json_decode( (string) $row->payload, true ) ?: array();
		$provider = self::get_provider( $row->channel );

		if ( ! $provider ) {
			$wpdb->update( $table, array( 'status' => 'failed', 'last_error' => 'No provider available for channel.' ), array( 'id' => $row->id ) );
			return;
		}

		try {
			$result = $provider->send( $subscriber, $payload );
		} catch ( \Throwable $e ) {
			$result = new SendResult( false, '', $e->getMessage() );
		}

		$attempts = (int) $row->attempts + 1;

		self::log_delivery( $row, $result );

		if ( $result->success ) {
			$wpdb->update(
				$table,
				array(
					'status'   => 'sent',
					'attempts' => $attempts,
					'sent_at'  => \ssm_now(),
				),
				array( 'id' => $row->id )
			);

			do_action( 'ssm_notification_sent', $row );
			return;
		}

		if ( $attempts >= (int) $row->max_attempts ) {
			$wpdb->update(
				$table,
				array( 'status' => 'failed', 'attempts' => $attempts, 'last_error' => $result->error ),
				array( 'id' => $row->id )
			);
			do_action( 'ssm_notification_failed', $row, $result->error );
			return;
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
	 * Resolves the provider instance for a channel.
	 *
	 * @param string $channel email|sms|teams.
	 * @return NotificationProviderInterface|null
	 */
	public static function get_provider( $channel ) {
		switch ( $channel ) {
			case 'email':
				return new EmailProvider();
			case 'sms':
				return self::get_sms_provider();
			case 'teams':
				return new TeamsProvider();
		}

		return apply_filters( 'ssm_notification_provider_instance', null, $channel );
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
