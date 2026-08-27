<?php
/**
 * Notification rule engine: listens for domain events (incident/
 * maintenance lifecycle, subscriber verification) and decides *who*
 * should be notified and *how*, then hands off to
 * Notifications\NotificationQueue::enqueue() for actual delivery.
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
		add_action( 'ssm_maintenance_started', array( __CLASS__, 'on_maintenance_started' ) );
		add_action( 'ssm_maintenance_completed', array( __CLASS__, 'on_maintenance_completed' ) );
		add_action( 'ssm_maintenance_extended', array( __CLASS__, 'on_maintenance_extended' ) );
		add_action( 'ssm_maintenance_cancelled', array( __CLASS__, 'on_maintenance_cancelled' ) );
		add_action( 'ssm_maintenance_reminder', array( __CLASS__, 'on_maintenance_reminder' ), 10, 2 );

		add_action( 'ssm_subscriber_verification_needed', array( __CLASS__, 'on_verification_needed' ), 10, 3 );
		add_action( 'ssm_subscriber_management_link_requested', array( __CLASS__, 'on_management_link_requested' ), 10, 2 );
		add_action( 'ssm_subscriber_confirmed', array( __CLASS__, 'on_subscriber_confirmed' ) );
	}

	/**
	 * @param object $incident Incident row.
	 */
	public static function on_incident_created( $incident ) {
		self::notify_for_incident( $incident, 'incident_created', __( 'New Incident: ', 'service-status-manager' ) );
	}

	/**
	 * @param object $incident Incident row.
	 * @param object $update   The new update row.
	 */
	public static function on_incident_updated( $incident, $update ) {
		self::notify_for_incident( $incident, 'incident_updated', __( 'Incident Update: ', 'service-status-manager' ), $update );
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

		self::notify_for_incident( $incident, 'incident_resolved', __( 'Resolved: ', 'service-status-manager' ) );
	}

	/**
	 * Builds and queues notifications for every subscriber matching an
	 * incident's affected services/monitors.
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
					'sms_summary'       => sprintf( '%s: %s', ucfirst( $incident->severity ), wp_strip_all_tags( $incident->title ) ),
					'severity'          => $incident->severity,
					'status_label'      => ucfirst( $incident->status ),
					'affected_services' => implode( ', ', wp_list_pluck( $services, 'name' ) ),
					'started_at'        => ssm_format_datetime( $incident->starts_at ),
					'url'               => $url,
					'manage_url'        => self::manage_url( $subscriber->id ),
					'unsubscribe_url'   => self::unsubscribe_url( $subscriber->id ),
					'event_type'        => $event,
				);

				$queued_id = NotificationQueue::enqueue(
					array(
						'subscriber_id'  => $subscriber->id,
						'channel'        => $channel,
						'event_type'     => $event,
						'reference_type' => 'incident',
						'reference_id'   => $incident->id,
						'payload'        => $payload,
						'dedup_key'      => sprintf( 'incident-%d-%s-%d-%s', $incident->id, $update ? $update->id : $event, $subscriber->id, $channel ),
					)
				);

				ssm_log( sprintf( 'Incident #%d (%s): queue row %s for subscriber #%d via %s.', $incident->id, $event, $queued_id ? '#' . $queued_id : 'NOT created (duplicate dedup_key)', $subscriber->id, $channel ), 'debug' );
			}
		}
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
	 * @param object $maintenance Maintenance row.
	 */
	public static function on_maintenance_announced( $maintenance ) {
		self::notify_for_maintenance( $maintenance, 'maintenance_announced', __( 'Scheduled Maintenance: ', 'service-status-manager' ) );
	}

	/**
	 * @param object $maintenance Maintenance row.
	 */
	public static function on_maintenance_started( $maintenance ) {
		self::notify_for_maintenance( $maintenance, 'maintenance_started', __( 'Maintenance Started: ', 'service-status-manager' ) );
	}

	/**
	 * @param object $maintenance Maintenance row.
	 */
	public static function on_maintenance_completed( $maintenance ) {
		self::notify_for_maintenance( $maintenance, 'maintenance_completed', __( 'Maintenance Completed: ', 'service-status-manager' ) );
	}

	/**
	 * @param object $maintenance Maintenance row.
	 */
	public static function on_maintenance_extended( $maintenance ) {
		self::notify_for_maintenance( $maintenance, 'maintenance_extended', __( 'Maintenance Extended: ', 'service-status-manager' ) );
	}

	/**
	 * @param object $maintenance Maintenance row.
	 */
	public static function on_maintenance_cancelled( $maintenance ) {
		self::notify_for_maintenance( $maintenance, 'maintenance_cancelled', __( 'Maintenance Cancelled: ', 'service-status-manager' ) );
	}

	/**
	 * @param object $maintenance Maintenance row.
	 * @param int    $lead_hours  Hours before start this reminder fires.
	 */
	public static function on_maintenance_reminder( $maintenance, $lead_hours ) {
		self::notify_for_maintenance(
			$maintenance,
			'maintenance_reminder',
			sprintf(
				/* translators: %d: hours until maintenance starts */
				__( 'Reminder: Maintenance in %d hour(s): ', 'service-status-manager' ),
				$lead_hours
			)
		);
	}

	/**
	 * Builds and queues maintenance notifications for matching, opted-in
	 * subscribers.
	 *
	 * @param object $maintenance Maintenance row.
	 * @param string $event       Event type slug.
	 * @param string $prefix      Subject line prefix.
	 */
	private static function notify_for_maintenance( $maintenance, $event, $prefix ) {
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
			'maintenance_reminder'  => __( 'Reminder', 'service-status-manager' ),
		);

		$page = StatusPageManager::get_page_by_slug( 'main' );
		$url  = $page ? trailingslashit( home_url() ) : home_url( '/' );

		foreach ( $subscribers as $subscriber ) {
			foreach ( self::active_channels( $subscriber->id ) as $channel ) {
				$payload = array(
					'subject'           => $prefix . $maintenance->title,
					'body_html'         => wpautop( wp_kses_post( $maintenance->description ) ),
					'body_text'         => wp_strip_all_tags( $maintenance->description ),
					'sms_summary'       => sprintf( '%s (%s - %s)', wp_strip_all_tags( $maintenance->title ), ssm_format_datetime( $maintenance->scheduled_start ), ssm_format_datetime( $maintenance->scheduled_end ) ),
					'severity'          => 'informational',
					'status_label'      => $status_labels[ $event ] ?? '',
					'schedule_label'    => sprintf(
						/* translators: 1: start date/time, 2: end date/time */
						__( '%1$s to %2$s', 'service-status-manager' ),
						ssm_format_datetime( $maintenance->scheduled_start ),
						ssm_format_datetime( $maintenance->scheduled_end )
					),
					'affected_services' => implode( ', ', wp_list_pluck( $services, 'name' ) ),
					'url'               => $url,
					'manage_url'        => self::manage_url( $subscriber->id ),
					'unsubscribe_url'   => self::unsubscribe_url( $subscriber->id ),
					'event_type'        => $event,
				);

				NotificationQueue::enqueue(
					array(
						'subscriber_id'  => $subscriber->id,
						'channel'        => $channel,
						'event_type'     => $event,
						'reference_type' => 'maintenance',
						'reference_id'   => $maintenance->id,
						'payload'        => $payload,
						'dedup_key'      => sprintf( 'maintenance-%d-%s-%d-%s', $maintenance->id, $event, $subscriber->id, $channel ),
					)
				);
			}
		}
	}

	/**
	 * Finds active subscribers whose selections overlap the given
	 * services/monitors/groups (or who have no specific selections at
	 * all, which is treated as "subscribed to everything"), filtered by
	 * minimum severity preference.
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

		$active = $wpdb->get_results( "SELECT * FROM {$subscribers_table} WHERE status = 'active'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		ssm_log( sprintf( 'Notification targeting: %d subscriber(s) with status=active found overall.', count( $active ) ), 'debug' );

		$matches = array();

		foreach ( $active as $subscriber ) {
			if ( $maintenance_context && ! $subscriber->maintenance_notifications ) {
				ssm_log( sprintf( 'Notification targeting: subscriber #%d skipped - opted out of maintenance notifications.', $subscriber->id ), 'debug' );
				continue;
			}
			if ( ! $maintenance_context && ( self::SEVERITY_ORDER[ $severity ] ?? 0 ) < ( self::SEVERITY_ORDER[ $subscriber->min_severity ] ?? 0 ) ) {
				ssm_log( sprintf( 'Notification targeting: subscriber #%d skipped - event severity "%s" is below their minimum "%s".', $subscriber->id, $severity, $subscriber->min_severity ), 'debug' );
				continue;
			}

			$selections = $wpdb->get_results( $wpdb->prepare( "SELECT scope_type, scope_id FROM {$selections_table} WHERE subscriber_id = %d", $subscriber->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

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
				ssm_log( sprintf( 'Notification targeting: subscriber #%d skipped - their selected services/groups/monitors do not overlap with this event.', $subscriber->id ), 'debug' );
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
	 * Queues a channel verification (double opt-in) message.
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
				'dedup_key'      => 'manage-link-' . $subscriber_id . '-' . substr( md5( $token ), 0, 8 ),
			)
		);
	}
}
