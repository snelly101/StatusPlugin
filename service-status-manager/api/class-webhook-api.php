<?php
/**
 * Incoming webhook endpoint: lets an external system (a NinjaOne/PRTG/
 * Auvik-style monitoring tool, a custom script, etc.) push a monitor
 * status update into the plugin without needing a WordPress user
 * account.
 *
 * Security model (see "Incoming webhooks" in readme.txt for the full
 * write-up and a sample payload):
 *   - Each integration has its own secret (Settings > Tools > Webhooks),
 *     never transmitted in the request itself.
 *   - Requests must include an HMAC-SHA256 signature computed over
 *     "{timestamp}.{raw request body}" using that secret.
 *   - A timestamp header must be within a small window of the server's
 *     current time, and a client-supplied idempotency key is recorded so
 *     a retried/duplicated delivery is processed at most once - together
 *     these prevent replay attacks.
 *   - An optional per-integration IP allow-list can further restrict
 *     which source addresses are accepted.
 *   - Every attempt (accepted or rejected) is written to the webhook
 *     delivery log for audit purposes.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Api;

use ServiceStatusManager\Encryption;
use ServiceStatusManager\MonitorManager;
use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\RateLimiter;
use ServiceStatusManager\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebhookApi {

	const REPLAY_WINDOW = 300; // seconds

	/**
	 * Registers this controller's route.
	 */
	public function register_routes() {
		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/webhook/(?P<webhook_id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		$webhook_id = (int) $request->get_param( 'webhook_id' );

		if ( ! RateLimiter::allow( 'incoming_webhook_' . $webhook_id, 60, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'ssm_rate_limited', __( 'Too many requests.', 'service-status-manager' ), array( 'status' => 429 ) );
		}

		global $wpdb;
		$table   = \ssm_table( 'webhooks_incoming' );
		$webhook = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $webhook_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $webhook || ! $webhook->is_active ) {
			return $this->reject( $webhook_id, $request, 404, __( 'Unknown or inactive webhook integration.', 'service-status-manager' ) );
		}

		$remote_ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$allowed_ips = array_filter( array_map( 'trim', explode( "\n", (string) $webhook->allowed_ips ) ) );
		if ( $allowed_ips && ! in_array( $remote_ip, $allowed_ips, true ) ) {
			return $this->reject( $webhook_id, $request, 403, __( 'Source IP address is not allow-listed for this integration.', 'service-status-manager' ) );
		}

		$timestamp = $request->get_header( 'X-SSM-Timestamp' );
		$signature = $request->get_header( 'X-SSM-Signature' );
		$idempotency_key = $request->get_header( 'X-SSM-Idempotency-Key' );

		if ( ! $timestamp || ! is_numeric( $timestamp ) || abs( time() - (int) $timestamp ) > self::REPLAY_WINDOW ) {
			return $this->reject( $webhook_id, $request, 401, __( 'Missing or stale timestamp.', 'service-status-manager' ) );
		}
		if ( ! $idempotency_key ) {
			return $this->reject( $webhook_id, $request, 400, __( 'Missing idempotency key.', 'service-status-manager' ) );
		}

		$secret = Encryption::decrypt( $webhook->secret );
		$body   = $request->get_body();
		$expected_signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		if ( ! $signature || ! hash_equals( $expected_signature, (string) $signature ) ) {
			return $this->reject( $webhook_id, $request, 401, __( 'Invalid signature.', 'service-status-manager' ) );
		}

		if ( $this->is_replay( $webhook_id, $idempotency_key ) ) {
			// Idempotent: acknowledge without reprocessing.
			return rest_ensure_response( array( 'ok' => true, 'duplicate' => true ) );
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) || empty( $payload['monitor_id'] ) || empty( $payload['state'] ) ) {
			return $this->reject( $webhook_id, $request, 400, __( 'Payload must include monitor_id and state.', 'service-status-manager' ), $idempotency_key );
		}

		$monitor = MonitorManager::get_monitor( (int) $payload['monitor_id'] );
		if ( ! $monitor ) {
			return $this->reject( $webhook_id, $request, 404, __( 'Unknown monitor_id.', 'service-status-manager' ), $idempotency_key );
		}

		$state = ServiceManager::sanitize_status( $payload['state'] );
		MonitorManager::set_manual_state( $monitor->id, $state );

		$this->log( $webhook_id, $request, 200, true, $idempotency_key );
		AuditLog::record( 'webhook_incoming_processed', 'monitor', $monitor->id, null, array( 'state' => $state, 'webhook_id' => $webhook_id ) );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Checks whether an idempotency key has already been processed for
	 * this webhook integration.
	 *
	 * @param int    $webhook_id      Webhook integration ID.
	 * @param string $idempotency_key Client-supplied idempotency key.
	 * @return bool
	 */
	private function is_replay( $webhook_id, $idempotency_key ) {
		global $wpdb;
		$table = \ssm_table( 'webhook_delivery_log' );

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE webhook_id = %d AND direction = 'in' AND idempotency_key = %s AND success = 1",
				$webhook_id,
				substr( (string) $idempotency_key, 0, 190 )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return (bool) $found;
	}

	/**
	 * Logs and rejects a request.
	 *
	 * @param int               $webhook_id      Webhook integration ID.
	 * @param \WP_REST_Request  $request         Request.
	 * @param int               $status          HTTP status to return.
	 * @param string            $message         Error message.
	 * @param string            $idempotency_key Idempotency key, if known.
	 * @return \WP_Error
	 */
	private function reject( $webhook_id, $request, $status, $message, $idempotency_key = '' ) {
		$this->log( $webhook_id, $request, $status, false, $idempotency_key );
		\ssm_log( sprintf( 'Incoming webhook #%d rejected: %s', $webhook_id, $message ), 'warning' );
		return new \WP_Error( 'ssm_webhook_rejected', $message, array( 'status' => $status ) );
	}

	/**
	 * Writes a delivery log row.
	 *
	 * @param int              $webhook_id      Webhook integration ID.
	 * @param \WP_REST_Request $request         Request.
	 * @param int              $response_code   HTTP status recorded.
	 * @param bool             $success         Whether the request was accepted.
	 * @param string           $idempotency_key Idempotency key, if known.
	 */
	private function log( $webhook_id, $request, $response_code, $success, $idempotency_key = '' ) {
		global $wpdb;

		$wpdb->insert(
			\ssm_table( 'webhook_delivery_log' ),
			array(
				'webhook_id'      => $webhook_id ?: null,
				'direction'       => 'in',
				'event_type'      => 'monitor_result',
				'request_payload' => \ssm_mask_secrets( substr( (string) $request->get_body(), 0, 2000 ) ),
				'response_code'   => $response_code,
				'success'         => $success ? 1 : 0,
				'idempotency_key' => $idempotency_key ? substr( $idempotency_key, 0, 190 ) : null,
				'created_at'      => \ssm_now(),
			)
		);
	}
}
