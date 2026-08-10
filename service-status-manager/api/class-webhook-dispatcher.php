<?php
/**
 * Dispatches outgoing webhooks for incident, maintenance, service, and
 * monitor lifecycle events to every active, subscribed integration
 * (Settings > Tools > Webhooks).
 *
 * Each configured webhook has its own secret; outgoing payloads are
 * signed the same way incoming ones are validated (HMAC-SHA256 over
 * "{timestamp}.{body}", see Api\WebhookApi) so receivers can verify
 * authenticity. Delivery is synchronous (a short, configurable per-
 * webhook timeout) with the outcome written to the delivery log;
 * administrators can inspect failures there and use the "Test" action to
 * retry manually - see Settings > Tools > Webhooks.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Api;

use ServiceStatusManager\Encryption;
use ServiceStatusManager\IncidentManager;
use ServiceStatusManager\MaintenanceManager;
use ServiceStatusManager\ServiceManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebhookDispatcher {

	/**
	 * @param object $incident Incident row.
	 */
	public static function dispatch_incident_created( $incident ) {
		self::dispatch(
			'incident.created',
			array(
				'incident' => self::incident_payload( $incident ),
			)
		);
	}

	/**
	 * @param object $incident Incident row.
	 * @param object $update   Update row.
	 */
	public static function dispatch_incident_updated( $incident, $update ) {
		self::dispatch(
			'incident.updated',
			array(
				'incident' => self::incident_payload( $incident ),
				'update'   => array(
					'status'  => $update->status,
					'message' => $update->message,
				),
			)
		);
	}

	/**
	 * @param object $incident Incident row.
	 */
	public static function dispatch_incident_resolved( $incident ) {
		self::dispatch( 'incident.resolved', array( 'incident' => self::incident_payload( $incident ) ) );
	}

	/**
	 * @param int    $service_id Service ID.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 */
	public static function dispatch_service_status_changed( $service_id, $old_status, $new_status ) {
		$service = ServiceManager::get_service( $service_id );
		self::dispatch(
			'service.status_changed',
			array(
				'service_id' => (int) $service_id,
				'name'       => $service->name ?? '',
				'old_status' => $old_status,
				'new_status' => $new_status,
			)
		);
	}

	/**
	 * @param int    $monitor_id Monitor ID.
	 * @param string $old_state  Previous state.
	 * @param string $new_state  New state.
	 */
	public static function dispatch_monitor_status_changed( $monitor_id, $old_state, $new_state ) {
		self::dispatch(
			'monitor.status_changed',
			array(
				'monitor_id' => (int) $monitor_id,
				'old_status' => $old_state,
				'new_status' => $new_state,
			)
		);
	}

	/**
	 * @param int    $maintenance_id Maintenance ID.
	 * @param string $old_status     Previous status.
	 * @param string $new_status     New status.
	 */
	public static function dispatch_maintenance_status_changed( $maintenance_id, $old_status, $new_status ) {
		$event_map = array(
			'in_progress' => 'maintenance.started',
			'completed'   => 'maintenance.completed',
			'cancelled'   => 'maintenance.cancelled',
		);

		$maintenance = MaintenanceManager::get_maintenance( $maintenance_id );

		self::dispatch(
			$event_map[ $new_status ] ?? 'maintenance.status_changed',
			array(
				'maintenance_id' => (int) $maintenance_id,
				'title'          => $maintenance->title ?? '',
				'old_status'     => $old_status,
				'new_status'     => $new_status,
			)
		);
	}

	/**
	 * Builds the public-safe incident payload shared by every incident event.
	 *
	 * @param object $incident Incident row.
	 * @return array
	 */
	private static function incident_payload( $incident ) {
		return array(
			'id'       => (int) $incident->id,
			'title'    => $incident->title,
			'severity' => $incident->severity,
			'status'   => $incident->status,
			'services' => wp_list_pluck( IncidentManager::get_services_for_incident( $incident->id ), 'name' ),
		);
	}

	/**
	 * Sends a signed payload to every active webhook subscribed to the
	 * given event type.
	 *
	 * @param string $event Event type slug, e.g. "incident.created".
	 * @param array  $data  Event-specific payload data.
	 */
	private static function dispatch( $event, array $data ) {
		global $wpdb;
		$table = \ssm_table( 'webhooks_outgoing' );

		$webhooks = $wpdb->get_results( "SELECT * FROM {$table} WHERE is_active = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $webhooks as $webhook ) {
			$events = json_decode( (string) $webhook->events, true ) ?: array();
			if ( ! in_array( $event, $events, true ) && ! in_array( '*', $events, true ) ) {
				continue;
			}

			self::send( $webhook, $event, $data );
		}
	}

	/**
	 * Signs and sends a single webhook delivery, logging the outcome.
	 *
	 * @param object $webhook Webhook row.
	 * @param string $event   Event type slug.
	 * @param array  $data    Payload data.
	 */
	private static function send( $webhook, $event, array $data ) {
		$timestamp = time();
		$body      = wp_json_encode(
			array(
				'event'     => $event,
				'timestamp' => $timestamp,
				'data'      => $data,
			)
		);

		$secret    = Encryption::decrypt( $webhook->secret );
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		$response = wp_remote_post(
			$webhook->url,
			array(
				'timeout' => max( 1, (int) $webhook->timeout_seconds ),
				'headers' => array(
					'Content-Type'      => 'application/json',
					'X-SSM-Signature'   => $signature,
					'X-SSM-Timestamp'   => (string) $timestamp,
					'X-SSM-Event'       => $event,
				),
				'body'    => $body,
			)
		);

		$success       = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 300;
		$response_code = is_wp_error( $response ) ? null : wp_remote_retrieve_response_code( $response );
		$response_body = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );

		global $wpdb;
		$wpdb->insert(
			\ssm_table( 'webhook_delivery_log' ),
			array(
				'webhook_id'      => $webhook->id,
				'direction'       => 'out',
				'event_type'      => $event,
				'request_payload' => substr( $body, 0, 2000 ),
				'response_code'   => $response_code,
				'response_body'   => substr( (string) $response_body, 0, 2000 ),
				'success'         => $success ? 1 : 0,
				'created_at'      => \ssm_now(),
			)
		);

		if ( ! $success ) {
			\ssm_log( sprintf( 'Outgoing webhook #%d delivery failed for event %s.', $webhook->id, $event ), 'warning' );
		}
	}

	/**
	 * Sends a manual test delivery for a configured webhook (Settings >
	 * Tools > Webhooks > Test).
	 *
	 * @param int $webhook_id Webhook ID.
	 * @return bool
	 */
	public static function send_test( $webhook_id ) {
		global $wpdb;
		$table   = \ssm_table( 'webhooks_outgoing' );
		$webhook = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $webhook_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $webhook ) {
			return false;
		}

		self::send( $webhook, 'test', array( 'message' => 'This is a test delivery from Service Status Manager.' ) );

		return true;
	}
}
