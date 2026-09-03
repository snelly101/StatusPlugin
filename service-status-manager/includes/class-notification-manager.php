<?php
/**
 * Notification rule engine: listens for domain events (incident/
 * maintenance lifecycle, subscriber verification) and decides *who*
 * should be notified and *how*.
 *
 * Incident/maintenance events are NOT fanned out to subscribers
 * synchronously - at 2,000+ subscribers, looping over every match inside
 * the admin's incident-save HTTP request is exactly the slow-request
 * problem this subsystem exists to avoid. Instead, on_incident_*()/
 * on_maintenance_*() write one lightweight row to `notification_events`
 * and return immediately; the actual targeting + payload-building +
 * queueing happens in fan_out_pending_events(), called by
 * Notifications\NotificationDispatcher on its own time-boxed schedule.
 *
 * This is the only class that evaluates subscriber preferences against
 * an event; NotificationQueue and the individual providers never make
 * targeting decisions themselves.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

use ServiceStatusManager\Notifications\NotificationQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NotificationManager {

	const SEVERITY_ORDER = array( 'informational' => 0, 'minor' => 1, 'major' => 2, 'critical' => 3 );

	/**
	 * Registers the domain event listeners.
	 */
	public static function register() {
		add_action( 'ssm_incident_created', array( __CLASS__, 'on_incident_created' ) );
		add_action( 'ssm_incident_updated', array( __CLASS__, 'on_incident_updated' ), 10, 2 );
		add_action( 'ssm_incident_resolved', array( __CLASS__, 'on_incident_resolved' ) );

		add_action( 'ssm_maintenance_announced', array( __CLASS__, 'on_maintenance_announced' ) );
		add_action( 'ssm_maintenance_started', array( __CLASS__, 'on_maintenance_started' ), 10, 2 );
		add_action( 'ssm_maintenance_completed', array( __CLASS__, 'on_maintenance_completed' ), 10, 2 );
		add_action( 'ssm_maintenance_extended', array( __CLASS__, 'on_maintenance_extended' ) );
		add_action( 'ssm_maintenance_cancelled', array( __CLASS__, 'on_maintenance_cancelled' ), 10, 2 );
		add_action( 'ssm_maintenance_updated', array( __CLASS__, 'on_maintenance_updated' ), 10, 2 );
		add_action( 'ssm_maintenance_reminder', array( __CLASS__, 'on_maintenance_reminder' ), 10, 2 );

		add_action( 'ssm_subscriber_verification_needed', array( __CLASS__, 'on_verification_needed' ), 10, 3 );
		add_action( 'ssm_subscriber_management_link_requested', array( __CLASS__, 'on_management_link_requested' ), 10, 2 );
		add_action( 'ssm_subscriber_confirmed', array( __CLASS__, 'on_subscriber_confirmed' ) );
	}

	/*
	 * ---------------------------------------------------------------
	 * Incident event listeners - queue a notification_events row and
	 * return; see fan_out_incident_event() for the actual targeting.
	 * ---------------------------------------------------------------
	 */

	/**
	 * @param object $incident Incident row.
	 */
	public static function on_incident_created( $incident ) {
		self::queue_incident_event( $incident, 'incident_created' );
	}

	/**
	 * @param object $incident Incident row.
	 * @param object $update   The new update row.
	 */
	public static function on_incident_updated( $incident, $update ) {
		self::queue_incident_event( $incident, 'incident_updated', $update );
	}

	/**
	 * @param object $incident Incident row.
	 */
	public static function on_incident_resolved( $incident ) {
		$minutes_open = $incident->ends_at ? ( strtotime( $incident->ends_at ) - strtotime( $incident->starts_at ) ) / 60 : null;
		$suppress_min = (int) ssm_get_setting( 'suppress_short_incident_recovery_minutes', 0 );

		if ( $suppress_min > 0 && null !== $minutes_open && $minutes_open < $suppress_min ) {
			return;
		}

		self::queue_incident_event( $incident, 'incident_resolved' );
	}

	/**
	 * @param object      $incident Incident row.
	 * @param string      $event    Event type slug.
	 * @param object|null $update   The relevant update row, if any.
	 */
	private static function queue_incident_event( $incident, $event, $update = null ) {
		self::create_event(
			array(
				'event_type'     => $event,
				'reference_type' => 'incident',
				'reference_id'   => $incident->id,
				'update_id'      => $update ? $update->id : null,
				'severity'       => $incident->severity,
			)
		);
	}

	/*
	 * ---------------------------------------------------------------
	 * Maintenance event listeners - same deferred pattern.
	 * ---------------------------------------------------------------
	 */

	/**
	 * @param object $maintenance Maintenance row.
	 */
	public static function on_maintenance_announced( $maintenance ) {
		self::queue_maintenance_event( $maintenance, 'maintenance_announced' );
	}

	/**
	 * @param object      $maintenance Maintenance row.
	 * @param object|null $update      The update row that triggered this transition, if any.
	 */
	public static function on_maintenance_started( $maintenance, $update = null ) {
		self::queue_maintenance_event( $maintenance, 'maintenance_started', $update );
	}

	/**
	 * @param object      $maintenance Maintenance row.
	 * @param object|null $update      The update row that triggered this transition, if any.
	 */
	public static function on_maintenance_completed( $maintenance, $update = null ) {
		self::queue_maintenance_event( $maintenance, 'maintenance_completed', $update );
	}

	/**
	 * @param object $maintenance Maintenance row.
	 */
	public static function on_maintenance_extended( $maintenance ) {
		self::queue_maintenance_event( $maintenance, 'maintenance_extended' );
	}

	/**
	 * @param object      $maintenance Maintenance row.
	 * @param object|null $update      The update row that triggered this transition, if any.
	 */
	public static function on_maintenance_cancelled( $maintenance, $update = null ) {
		self::queue_maintenance_event( $maintenance, 'maintenance_cancelled', $update );
	}

	/**
	 * A status-unchanged progress note posted to an in-progress (or
	 * scheduled) maintenance window's timeline.
	 *
	 * @param object $maintenance Maintenance row.
	 * @param object $update      The new update row.
	 */
	public static function on_maintenance_updated( $maintenance, $update ) {
		self::queue_maintenance_event( $maintenance, 'maintenance_updated', $update );
	}

	/**
	 * @param object $maintenance Maintenance row.
	 * @param int    $lead_hours  Hours before start this reminder fires.
	 */
	public static function on_maintenance_reminder( $maintenance, $lead_hours ) {
		self::queue_maintenance_event( $maintenance, 'maintenance_reminder', null, array( 'lead_hours' => (int) $lead_hours ) );
	}

	/**
	 * @param object      $maintenance Maintenance row.
	 * @param string      $event       Event type slug.
	 * @param object|null $update      The relevant update row, if any.
	 * @param array       $meta        Small extra context that can't be re-derived when fanning out later (e.g. lead_hours).
	 */
	private static function queue_maintenance_event( $maintenance, $event, $update = null, array $meta = array() ) {
		self::create_event(
			array(
				'event_type'     => $event,
				'reference_type' => 'maintenance',
				'reference_id'   => $maintenance->id,
				'update_id'      => $update ? $update->id : null,
				'severity'       => 'informational',
				'meta'           => $meta,
			)
		);
	}

	/**
	 * Inserts a notification_events row and wakes the dispatcher. Cheap and
	 * synchronous (one small insert) - the actual subscriber targeting
	 * happens later, off the admin's request.
	 *
	 * @param array $args {
	 *     @type string   $event_type
	 *     @type string   $reference_type
	 *     @type int      $reference_id
	 *     @type int|null $update_id
	 *     @type string|null $severity
	 *     @type array    $meta
	 * }
	 */
	private static function create_event( array $args ) {
		global $wpdb;

		$table = ssm_table( 'notification_events' );

		$wpdb->insert(
			$table,
			array(
				'event_type'     => $args['event_type'],
				'reference_type' => $args['reference_type'],
				'reference_id'   => $args['reference_id'],
				'update_id'      => $args['update_id'] ?? null,
				'severity'       => $args['severity'] ?? null,
				'meta'           => ! empty( $args['meta'] ) ? wp_json_encode( $args['meta'] ) : null,
				'status'         => 'pending',
				'created_at'     => current_time( 'mysql', true ),
			)
		);

		ssm_log(
			sprintf(
				'Notification event #%d (%s / %s #%d) queued for fan-out.',
				$wpdb->insert_id,
				$args['event_type'],
				$args['reference_type'],
				$args['reference_id']
			),
			'debug'
		);

		NotificationQueue::trigger_immediate_processing();
	}

	/*
	 * ---------------------------------------------------------------
	 * Fan-out: called by NotificationDispatcher, not by the domain
	 * events directly.
	 * ---------------------------------------------------------------
	 */

	/**
	 * Claims and fans out a batch of pending notification_events rows.
	 * Uses the same single-row atomic-claim pattern as the queue itself
	 * (UPDATE ... WHERE status = 'pending') so two overlapping dispatcher
	 * runs can't both fan out the same event.
	 *
	 * @param int $limit Maximum events to fan out in this call.
	 * @return int Number of events actually fanned out.
	 */
	public static function fan_out_pending_events( $limit = 20 ) {
		global $wpdb;

		$table = ssm_table( 'notification_events' );

		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'pending' ORDER BY id ASC LIMIT %d", $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$fanned_out = 0;

		foreach ( $ids as $event_id ) {
			$claimed = $wpdb->query(
				$wpdb->prepare( "UPDATE {$table} SET status = 'processing' WHERE id = %d AND status = 'pending'", $event_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);

			if ( ! $claimed ) {
				continue;
			}

			self::fan_out_event( (int) $event_id );
			++$fanned_out;
		}

		return $fanned_out;
	}

	/**
	 * @param int $event_id notification_events.id, already claimed by the caller.
	 */
	private static function fan_out_event( $event_id ) {
		global $wpdb;

		$table = ssm_table( 'notification_events' );
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $event ) {
			return;
		}

		try {
			if ( 'incident' === $event->reference_type ) {
				self::fan_out_incident_event( $event );
			} elseif ( 'maintenance' === $event->reference_type ) {
				self::fan_out_maintenance_event( $event );
			}

			$wpdb->update(
				$table,
				array(
					'status'        => 'done',
					'fanned_out_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $event_id )
			);
		} catch ( \Throwable $e ) {
			$attempts    = (int) $event->attempts + 1;
			$next_status = $attempts < 3 ? 'pending' : 'failed';

			$wpdb->update(
				$table,
				array(
					'status'     => $next_status,
					'attempts'   => $attempts,
					'last_error' => substr( $e->getMessage(), 0, 500 ),
				),
				array( 'id' => $event_id )
			);

			ssm_log(
				sprintf( 'Notification event #%d fan-out failed on attempt %d: %s', $event_id, $attempts, $e->getMessage() ),
				'error'
			);
		}
	}

	/**
	 * @param object $event notification_events row.
	 */
	private static function fan_out_incident_event( $event ) {
		$incident = IncidentManager::get_incident( $event->reference_id );

		if ( ! $incident ) {
			return;
		}

		$update = $event->update_id ? self::get_incident_update( $event->update_id ) : null;

		self::notify_for_incident( $incident, $event->event_type, self::incident_prefix_for_event( $event->event_type ), $update );
	}

	/**
	 * @param object $event notification_events row.
	 */
	private static function fan_out_maintenance_event( $event ) {
		$maintenance = MaintenanceManager::get_maintenance( $event->reference_id );

		if ( ! $maintenance ) {
			return;
		}

		$update = $event->update_id ? self::get_maintenance_update( $event->update_id ) : null;
		$meta   = $event->meta ? json_decode( $event->meta, true ) : array();

		self::notify_for_maintenance( $maintenance, $event->event_type, self::maintenance_prefix_for_event( $event->event_type, (array) $meta ), $update );
	}

	/**
	 * @param string $event_type Event type slug.
	 * @return string Subject-line prefix, mirrors the literal strings the
	 *                on_incident_*() handlers used to pass directly.
	 */
	private static function incident_prefix_for_event( $event_type ) {
		$prefixes = array(
			'incident_created'  => __( 'New Incident: ', 'service-status-manager' ),
			'incident_updated'  => __( 'Incident Update: ', 'service-status-manager' ),
			'incident_resolved' => __( 'Resolved: ', 'service-status-manager' ),
		);

		return $prefixes[ $event_type ] ?? '';
	}

	/**
	 * @param string $event_type Event type slug.
	 * @param array  $meta       Event meta (only 'lead_hours' is used, for maintenance_reminder).
	 * @return string
	 */
	private static function maintenance_prefix_for_event( $event_type, array $meta = array() ) {
		if ( 'maintenance_reminder' === $event_type ) {
			return sprintf(
				/* translators: %d: hours until maintenance starts */
				__( 'Reminder: Maintenance in %d hour(s): ', 'service-status-manager' ),
				(int) ( $meta['lead_hours'] ?? 0 )
			);
		}

		$prefixes = array(
			'maintenance_announced' => __( 'Scheduled Maintenance: ', 'service-status-manager' ),
			'maintenance_started'   => __( 'Maintenance Started: ', 'service-status-manager' ),
			'maintenance_completed' => __( 'Maintenance Completed: ', 'service-status-manager' ),
			'maintenance_extended'  => __( 'Maintenance Extended: ', 'service-status-manager' ),
			'maintenance_cancelled' => __( 'Maintenance Cancelled: ', 'service-status-manager' ),
			'maintenance_updated'   => __( 'Maintenance Update: ', 'service-status-manager' ),
		);

		return $prefixes[ $event_type ] ?? '';
	}

	/**
	 * @param int $update_id incident_updates.id.
	 * @return object|null
	 */
	private static function get_incident_update( $update_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ssm_table( 'incident_updates' ) . ' WHERE id = %d', $update_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $update_id maintenance_updates.id.
	 * @return object|null
	 */
	private static function get_maintenance_update( $update_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ssm_table( 'maintenance_updates' ) . ' WHERE id = %d', $update_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Builds and queues notifications for every subscriber matching an
	 * incident's affected services/monitors. Called only from
	 * fan_out_incident_event() - never directly from the domain event.
	 *
	 * @param object      $incident Incident row.
	 * @param string      $event    Event type slug.
	 * @param string      $prefix   Subject line prefix.
	 * @param object|null $update   The relevant update row, if any.
	 */
	private static function notify_for_incident( $incident, $event, $prefix, $update = null ) {
		if ( ! $incident->is_public || ! $incident->notify ) {
			ssm_log( sprintf( 'Incident #%d (%s): notifications skipped - "visible on status page" or "send notifications" is off.', $incident->id, $event ), 'debug' );
			return;
		}
		if ( self::below_global_floor( $incident->severity ) ) {
			ssm_log( sprintf( 'Incident #%d (%s): severity "%s" is below the global minimum notify severity setting.', $incident->id, $event, $incident->severity ), 'debug' );
			return;
		}

		$services = IncidentManager::get_services_for_incident( $incident->id );
		$monitors = IncidentManager::get_monitors_for_incident( $incident->id );

		$service_ids = wp_list_pluck( $services, 'id' );
		$monitor_ids = wp_list_pluck( $monitors, 'id' );
		$group_ids   = array_values( array_unique( array_filter( wp_list_pluck( $services, 'group_id' ) ) ) );

		$subscribers = self::get_matching_subscribers( $service_ids, $monitor_ids, $group_ids, $incident->severity );

		ssm_log( sprintf( 'Incident #%d (%s): %d matching active subscriber(s) found.', $incident->id, $event, count( $subscribers ) ), 'debug' );

		$page = StatusPageManager::get_page_by_slug( 'main' );
		$url  = $page ? trailingslashit( home_url() ) . '?ssm_incident=' . $incident->slug : home_url( '/' );

		// A distinct "what kind of notice is this" label (New Incident /
		// Incident Update / Resolved), separate from the incident's
		// operational status (Investigating/Monitoring/Resolved) below -
		// without it, an update notification looks identical in body/SMS
		// to the original "new incident" one, and the only place that
		// difference showed up was the email subject line.
		$notice_label = rtrim( trim( $prefix ), ':' );

		$sms_summary = self::build_sms_summary(
			array(
				sprintf( '%s - %s: %s', $notice_label, ucfirst( $incident->severity ), wp_strip_all_tags( $incident->title ) ),
				ucfirst( $incident->status ),
				$services ? sprintf( __( 'Services: %s', 'service-status-manager' ), implode( ', ', wp_list_pluck( $services, 'name' ) ) ) : '',
			)
		);

		$priority = self::priority_for( $event, $incident->severity );
		$rows     = array();

		foreach ( $subscribers as $subscriber ) {
			$channels = self::active_channels( $subscriber->id );

			if ( empty( $channels ) ) {
				ssm_log( sprintf( 'Incident #%d (%s): subscriber #%d matched but has no active+verified channel - nothing queued for them.', $incident->id, $event, $subscriber->id ), 'debug' );
				continue;
			}

			foreach ( $channels as $channel ) {
				$payload = array(
					'subject'           => $prefix . $incident->title,
					'body_html'         => wpautop( wp_kses_post( $update->message ?? $incident->description ) ),
					'body_text'         => wp_strip_all_tags( $update->message ?? $incident->description ),
					'sms_summary'       => $sms_summary,
					'severity'          => $incident->severity,
					'notice_label'      => $notice_label,
					'status_label'      => ucfirst( $incident->status ),
					'affected_services' => implode( ', ', wp_list_pluck( $services, 'name' ) ),
					'started_at'        => ssm_format_datetime( $incident->starts_at ),
					'url'               => $url,
					'manage_url'        => self::manage_url( $subscriber->id ),
					'unsubscribe_url'   => self::unsubscribe_url( $subscriber->id ),
					'event_type'        => $event,
				);

				$rows[] = array(
					'subscriber_id'  => $subscriber->id,
					'channel'        => $channel,
					'event_type'     => $event,
					'reference_type' => 'incident',
					'reference_id'   => $incident->id,
					'payload'        => $payload,
					'priority'       => $priority,
					'dedup_key'      => sprintf( 'incident-%d-%s-%d-%s', $incident->id, $update ? $update->id : $event, $subscriber->id, $channel ),
				);
			}
		}

		ssm_log( sprintf( 'Incident #%d (%s): %d notification(s) queued for dispatch.', $incident->id, $event, count( $rows ) ), 'debug' );

		self::bulk_enqueue( $rows );
	}

	/**
	 * Joins non-empty parts into a single SMS summary line. Kept as a flat
	 * list of parts (rather than building one long sprintf) so
	 * SmsProvider::build_text()'s existing truncation still cuts off the
	 * least important detail first, since the most important part (what
	 * happened) is always placed first.
	 *
	 * @param string[] $parts Ordered candidate parts, most important first.
	 * @return string
	 */
	private static function build_sms_summary( array $parts ) {
		return implode( ' | ', array_filter( array_map( 'trim', $parts ) ) );
	}

	/**
	 * @param string $severity Severity slug.
	 * @return bool True if below the configured global notification floor.
	 */
	private static function below_global_floor( $severity ) {
		$floor = ssm_get_setting( 'min_notify_severity', 'informational' );
		return ( self::SEVERITY_ORDER[ $severity ] ?? 0 ) < ( self::SEVERITY_ORDER[ $floor ] ?? 0 );
	}

	/**
	 * Maps an event (plus severity, for incidents) to a queue priority.
	 * Lower numbers are claimed first, so a critical incident's SMS never
	 * waits behind a routine maintenance reminder sitting in the same
	 * channel's queue.
	 *
	 * 0 = critical incident
	 * 1 = major incident, transactional subscriber messages (verification/management link)
	 * 2 = minor incident, maintenance started
	 * 3 = informational incident, incident resolved, maintenance completed/cancelled/updated (default)
	 * 4 = maintenance announced/extended/reminder
	 * 5 = reserved for a future digest feature
	 *
	 * @param string      $event_type Event type slug.
	 * @param string|null $severity   Incident severity, when relevant.
	 * @return int
	 */
	public static function priority_for( $event_type, $severity = null ) {
		if ( in_array( $event_type, array( 'subscription_confirmation', 'management_link' ), true ) ) {
			return 1;
		}

		if ( in_array( $event_type, array( 'incident_created', 'incident_updated' ), true ) ) {
			switch ( $severity ) {
				case 'critical':
					return 0;
				case 'major':
					return 1;
				case 'minor':
					return 2;
				default:
					return 3;
			}
		}

		if ( 'incident_resolved' === $event_type ) {
			return 3;
		}

		if ( 'maintenance_started' === $event_type ) {
			return 2;
		}

		if ( in_array( $event_type, array( 'maintenance_completed', 'maintenance_cancelled', 'maintenance_updated' ), true ) ) {
			return 3;
		}

		if ( in_array( $event_type, array( 'maintenance_announced', 'maintenance_extended', 'maintenance_reminder' ), true ) ) {
			return 4;
		}

		return 3;
	}

	/**
	 * @param object      $maintenance Maintenance row.
	 * @param string      $event       Event type slug.
	 * @param string      $prefix      Subject line prefix.
	 * @param object|null $update      The timeline update row that triggered this, if any -
	 *                                 its message is used instead of the maintenance's static
	 *                                 description when present (mirrors notify_for_incident()).
	 */
	private static function notify_for_maintenance( $maintenance, $event, $prefix, $update = null ) {
		if ( ! $maintenance->is_public ) {
			return;
		}

		$services    = MaintenanceManager::get_services_for_maintenance( $maintenance->id );
		$service_ids = wp_list_pluck( $services, 'id' );
		$group_ids   = array_values( array_unique( array_filter( wp_list_pluck( $services, 'group_id' ) ) ) );

		$subscribers = self::get_matching_subscribers( $service_ids, array(), $group_ids, 'informational', true );

		$status_labels = array(
			'maintenance_announced' => __( 'Scheduled', 'service-status-manager' ),
			'maintenance_started'   => __( 'In progress', 'service-status-manager' ),
			'maintenance_completed' => __( 'Completed', 'service-status-manager' ),
			'maintenance_extended'  => __( 'Extended', 'service-status-manager' ),
			'maintenance_cancelled' => __( 'Cancelled', 'service-status-manager' ),
			'maintenance_updated'   => __( 'Update', 'service-status-manager' ),
			'maintenance_reminder'  => __( 'Reminder', 'service-status-manager' ),
		);

		$page = StatusPageManager::get_page_by_slug( 'main' );
		$url  = $page ? trailingslashit( home_url() ) : home_url( '/' );

		$schedule_label = sprintf(
			/* translators: 1: start date/time, 2: end date/time */
			__( '%1$s to %2$s', 'service-status-manager' ),
			ssm_format_datetime( $maintenance->scheduled_start ),
			ssm_format_datetime( $maintenance->scheduled_end )
		);

		$notice_label = rtrim( trim( $prefix ), ':' );

		$sms_summary = self::build_sms_summary(
			array(
				sprintf( '%s: %s', $notice_label, wp_strip_all_tags( $maintenance->title ) ),
				$schedule_label,
				$services ? sprintf( __( 'Services: %s', 'service-status-manager' ), implode( ', ', wp_list_pluck( $services, 'name' ) ) ) : '',
			)
		);

		$priority = self::priority_for( $event );
		$rows     = array();

		foreach ( $subscribers as $subscriber ) {
			foreach ( self::active_channels( $subscriber->id ) as $channel ) {
				$payload = array(
					'subject'           => $prefix . $maintenance->title,
					'body_html'         => wpautop( wp_kses_post( $update->message ?? $maintenance->description ) ),
					'body_text'         => wp_strip_all_tags( $update->message ?? $maintenance->description ),
					'sms_summary'       => $sms_summary,
					'severity'          => 'informational',
					'notice_label'      => $notice_label,
					'status_label'      => $status_labels[ $event ] ?? '',
					'schedule_label'    => $schedule_label,
					'affected_services' => implode( ', ', wp_list_pluck( $services, 'name' ) ),
					'url'               => $url,
					'manage_url'        => self::manage_url( $subscriber->id ),
					'unsubscribe_url'   => self::unsubscribe_url( $subscriber->id ),
					'event_type'        => $event,
				);

				$rows[] = array(
					'subscriber_id'  => $subscriber->id,
					'channel'        => $channel,
					'event_type'     => $event,
					'reference_type' => 'maintenance',
					'reference_id'   => $maintenance->id,
					'payload'        => $payload,
					'priority'       => $priority,
					'dedup_key'      => sprintf( 'maintenance-%d-%s-%d-%s', $maintenance->id, $update ? $event . '-' . $update->id : $event, $subscriber->id, $channel ),
				);
			}
		}

		self::bulk_enqueue( $rows );
	}

	/**
	 * Queues a batch of already-built notification_queue row args in
	 * chunks of ~500 multi-row inserts, rather than one enqueue() call
	 * (and one round trip) per subscriber-channel pair.
	 *
	 * @param array[] $rows Rows shaped for Notifications\NotificationQueue::enqueue_many().
	 */
	private static function bulk_enqueue( array $rows ) {
		if ( empty( $rows ) ) {
			return;
		}

		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			NotificationQueue::enqueue_many( $chunk );
		}
	}

	/**
	 * Finds active subscribers whose selections overlap the given
	 * services/monitors/groups (or who have no specific selections at
	 * all, which is treated as "subscribed to everything"), filtered by
	 * minimum severity preference.
	 *
	 * Runs one lean query for candidate subscribers, then one (or a few,
	 * chunked at 1,000 IDs) query for their selections, rather than a
	 * separate subscriber_selections query per subscriber.
	 *
	 * @param int[]  $service_ids         Affected service IDs.
	 * @param int[]  $monitor_ids         Affected monitor IDs.
	 * @param int[]  $group_ids           Affected group IDs.
	 * @param string $severity            Event severity.
	 * @param bool   $maintenance_context Whether this is a maintenance notification (checks the maintenance_notifications flag instead of severity).
	 * @return array
	 */
	private static function get_matching_subscribers( $service_ids, $monitor_ids, $group_ids, $severity, $maintenance_context = false ) {
		global $wpdb;

		$subscribers_table = ssm_table( 'subscribers' );
		$selections_table  = ssm_table( 'subscriber_selections' );

		$active = $wpdb->get_results( "SELECT id, min_severity, maintenance_notifications FROM {$subscribers_table} WHERE status = 'active'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		ssm_log( sprintf( 'Notification targeting: %d subscriber(s) with status=active found overall.', count( $active ) ), 'debug' );

		$candidates = array();

		foreach ( $active as $subscriber ) {
			if ( $maintenance_context && ! $subscriber->maintenance_notifications ) {
				ssm_log( sprintf( 'Notification targeting: subscriber #%d skipped - opted out of maintenance notifications.', $subscriber->id ), 'debug' );
				continue;
			}
			if ( ! $maintenance_context && ( self::SEVERITY_ORDER[ $severity ] ?? 0 ) < ( self::SEVERITY_ORDER[ $subscriber->min_severity ] ?? 0 ) ) {
				ssm_log( sprintf( 'Notification targeting: subscriber #%d skipped - event severity "%s" is below their minimum "%s".', $subscriber->id, $severity, $subscriber->min_severity ), 'debug' );
				continue;
			}

			$candidates[ (int) $subscriber->id ] = $subscriber;
		}

		if ( empty( $candidates ) ) {
			return array();
		}

		$selections_by_subscriber = array();

		foreach ( array_chunk( array_keys( $candidates ), 1000 ) as $id_chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $id_chunk ), '%d' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare( "SELECT subscriber_id, scope_type, scope_id FROM {$selections_table} WHERE subscriber_id IN ({$placeholders})", $id_chunk ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);

			foreach ( $rows as $row ) {
				$selections_by_subscriber[ (int) $row->subscriber_id ][] = $row;
			}
		}

		$matches = array();

		foreach ( $candidates as $subscriber_id => $subscriber ) {
			$selections = $selections_by_subscriber[ $subscriber_id ] ?? array();

			if ( empty( $selections ) ) {
				$matches[] = $subscriber;
				continue;
			}

			$hit = false;
			foreach ( $selections as $selection ) {
				$hit = ( 'service' === $selection->scope_type && in_array( (int) $selection->scope_id, $service_ids, true ) )
					|| ( 'monitor' === $selection->scope_type && in_array( (int) $selection->scope_id, $monitor_ids, true ) )
					|| ( 'group' === $selection->scope_type && in_array( (int) $selection->scope_id, $group_ids, true ) );

				if ( $hit ) {
					$matches[] = $subscriber;
					break;
				}
			}

			if ( ! $hit ) {
				ssm_log( sprintf( 'Notification targeting: subscriber #%d skipped - their selected services/groups/monitors do not overlap with this event.', $subscriber_id ), 'debug' );
			}
		}

		return $matches;
	}

	/**
	 * @param int $subscriber_id Subscriber ID.
	 * @return string[] Active, verified channel names for this subscriber.
	 */
	private static function active_channels( $subscriber_id ) {
		$channels = SubscriberManager::get_channels( $subscriber_id );

		return array_values(
			array_map(
				fn( $c ) => $c->channel,
				array_filter( $channels, fn( $c ) => $c->is_active && $c->verified )
			)
		);
	}

	/**
	 * @param int $subscriber_id Subscriber ID.
	 * @return string
	 */
	private static function manage_url( $subscriber_id ) {
		$token = SubscriberManager::generate_token( $subscriber_id, 'manage', SubscriberManager::TOKEN_TTL_MANAGE );
		return add_query_arg(
			array( 'ssm_action' => 'manage', 'ssm_id' => $subscriber_id, 'ssm_token' => $token ),
			home_url( '/' )
		);
	}

	/**
	 * @param int $subscriber_id Subscriber ID.
	 * @return string
	 */
	private static function unsubscribe_url( $subscriber_id ) {
		$token = SubscriberManager::generate_token( $subscriber_id, 'unsubscribe', SubscriberManager::TOKEN_TTL_MANAGE );
		return add_query_arg(
			array( 'ssm_action' => 'unsubscribe', 'ssm_id' => $subscriber_id, 'ssm_token' => $token ),
			home_url( '/' )
		);
	}

	/**
	 * Queues a channel verification (double opt-in) message. Per-subscriber
	 * and low-volume (one recipient) - stays a direct, synchronous enqueue()
	 * rather than going through the deferred notification_events path.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $channel       Channel being verified.
	 * @param string $token         Raw verification token.
	 */
	public static function on_verification_needed( $subscriber_id, $channel, $token ) {
		$link_action = 'teams' === $channel ? 'teams_verify' : 'confirm';
		$link        = add_query_arg(
			array( 'ssm_action' => $link_action, 'ssm_id' => $subscriber_id, 'ssm_token' => $token ),
			home_url( '/' )
		);

		$payload = array(
			'subject'     => __( 'Please confirm your subscription', 'service-status-manager' ),
			'body_html'   => '<p>' . esc_html__( 'Please confirm your subscription to status notifications by clicking the link below:', 'service-status-manager' ) . '</p><p><a href="' . esc_url( $link ) . '">' . esc_html__( 'Confirm subscription', 'service-status-manager' ) . '</a></p>',
			'body_text'   => __( 'Please confirm your subscription to status notifications: ', 'service-status-manager' ) . $link,
			'sms_summary' => __( 'Confirm your subscription:', 'service-status-manager' ),
			'severity'    => 'informational',
			'url'         => $link,
			'event_type'  => 'subscription_confirmation',
		);

		NotificationQueue::enqueue(
			array(
				'subscriber_id'  => $subscriber_id,
				'channel'        => 'teams' === $channel ? 'teams' : $channel,
				'event_type'     => 'subscription_confirmation',
				'reference_type' => 'subscriber',
				'reference_id'   => $subscriber_id,
				'payload'        => $payload,
				'priority'       => self::priority_for( 'subscription_confirmation' ),
				'dedup_key'      => 'verify-' . $subscriber_id . '-' . $channel . '-' . substr( md5( $token ), 0, 8 ),
			)
		);
	}

	/**
	 * Fires once, right after a subscriber's first channel is confirmed
	 * (subscriber status flips from pending to active) - sends them their
	 * management link straight away, so they don't have to dig up the
	 * original confirmation email or use "resend" just to find it.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 */
	public static function on_subscriber_confirmed( $subscriber_id ) {
		$token = SubscriberManager::generate_token( $subscriber_id, 'manage', SubscriberManager::TOKEN_TTL_MANAGE );
		self::on_management_link_requested( $subscriber_id, $token );
	}

	/**
	 * Queues a "here is your management link" message for an already
	 * verified/active subscriber requesting it via resend_confirmation().
	 * Per-subscriber and low-volume - stays direct, same as above.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $token         Raw management token.
	 */
	public static function on_management_link_requested( $subscriber_id, $token ) {
		$link = add_query_arg(
			array( 'ssm_action' => 'manage', 'ssm_id' => $subscriber_id, 'ssm_token' => $token ),
			home_url( '/' )
		);

		$payload = array(
			'subject'     => __( 'Manage your status notification subscription', 'service-status-manager' ),
			'body_html'   => '<p><a href="' . esc_url( $link ) . '">' . esc_html__( 'Manage my subscription', 'service-status-manager' ) . '</a></p>',
			'body_text'   => $link,
			'sms_summary' => __( 'Manage your subscription:', 'service-status-manager' ),
			'severity'    => 'informational',
			'url'         => $link,
			'event_type'  => 'management_link',
		);

		NotificationQueue::enqueue(
			array(
				'subscriber_id'  => $subscriber_id,
				'channel'        => 'email',
				'event_type'     => 'management_link',
				'reference_type' => 'subscriber',
				'reference_id'   => $subscriber_id,
				'payload'        => $payload,
				'priority'       => self::priority_for( 'management_link' ),
				'dedup_key'      => 'manage-link-' . $subscriber_id . '-' . substr( md5( $token ), 0, 8 ),
			)
		);
	}
}
