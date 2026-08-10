<?php
/**
 * Protected (authenticated + capability-checked) REST endpoints for
 * managing incidents, service status, monitors, and notifications.
 *
 * Authentication uses WordPress' standard REST mechanisms - cookie+nonce
 * for same-site admin requests, or Application Passwords for external
 * integrations (enabled by default in modern WordPress). Every route
 * additionally checks a plugin capability in its permission_callback, so
 * an authenticated-but-unprivileged user still cannot act.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Api;

use ServiceStatusManager\Capabilities;
use ServiceStatusManager\IncidentManager;
use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\MonitorManager;
use ServiceStatusManager\Notifications\NotificationQueue;
use ServiceStatusManager\Monitoring\MonitorRunner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestAdminController {

	/**
	 * Registers this controller's routes.
	 */
	public function register_routes() {
		$ns = RestApi::NAMESPACE_V1;

		register_rest_route( $ns, '/incidents', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_incident' ),
			'permission_callback' => array( $this, 'can_manage_incidents' ),
		) );

		register_rest_route( $ns, '/incidents/(?P<id>\d+)', array(
			'methods'             => 'PUT,PATCH',
			'callback'            => array( $this, 'update_incident' ),
			'permission_callback' => array( $this, 'can_manage_incidents' ),
		) );

		register_rest_route( $ns, '/incidents/(?P<id>\d+)/resolve', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'resolve_incident' ),
			'permission_callback' => array( $this, 'can_manage_incidents' ),
		) );

		register_rest_route( $ns, '/services/(?P<id>\d+)/status', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'set_service_status' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );

		register_rest_route( $ns, '/monitors/(?P<id>\d+)/result', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'submit_monitor_result' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );

		register_rest_route( $ns, '/monitors/check', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'trigger_checks' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );

		register_rest_route( $ns, '/notifications/test', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'test_notification' ),
			'permission_callback' => array( $this, 'can_manage_integrations' ),
		) );
	}

	/** @return bool */
	public function can_manage_incidents() {
		return current_user_can( Capabilities::MANAGE_INCIDENTS );
	}

	/** @return bool */
	public function can_manage() {
		return current_user_can( Capabilities::MANAGE );
	}

	/** @return bool */
	public function can_manage_integrations() {
		return current_user_can( Capabilities::MANAGE_INTEGRATIONS );
	}

	/**
	 * POST /incidents
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_incident( \WP_REST_Request $request ) {
		// Note: WordPress core already rejects cookie-authenticated REST
		// requests that lack a valid X-WP-Nonce header (see
		// rest_cookie_check_errors()), so no additional manual nonce check
		// is needed here - Application Password requests authenticate via
		// the Authorization header instead and are unaffected by that check.
		$id = IncidentManager::create_incident( $request->get_json_params() );

		if ( is_wp_error( $id ) ) {
			$id->add_data( array( 'status' => 400 ) );
			return $id;
		}

		return rest_ensure_response( array( 'id' => $id ) );
	}

	/**
	 * PUT/PATCH /incidents/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_incident( \WP_REST_Request $request ) {
		$result = IncidentManager::update_incident( (int) $request->get_param( 'id' ), $request->get_json_params() );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response( array( 'updated' => true ) );
	}

	/**
	 * POST /incidents/{id}/resolve
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resolve_incident( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: array();
		$result = IncidentManager::add_update(
			(int) $request->get_param( 'id' ),
			'resolved',
			$params['message'] ?? __( 'This incident has been resolved.', 'service-status-manager' )
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response( array( 'resolved' => true ) );
	}

	/**
	 * POST /services/{id}/status
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_service_status( \WP_REST_Request $request ) {
		$service = ServiceManager::get_service( (int) $request->get_param( 'id' ) );
		if ( ! $service ) {
			return new \WP_Error( 'ssm_not_found', __( 'Service not found.', 'service-status-manager' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params() ?: array();
		if ( empty( $params['status'] ) ) {
			return new \WP_Error( 'ssm_missing_status', __( 'A status value is required.', 'service-status-manager' ), array( 'status' => 400 ) );
		}

		ServiceManager::set_status( $service->id, $params['status'] );

		return rest_ensure_response( array( 'updated' => true, 'status' => ServiceManager::get_service( $service->id )->status ) );
	}

	/**
	 * POST /monitors/{id}/result - allows an external system (already
	 * authenticated as a WordPress user via Application Password) to
	 * submit a manual monitor result. For unauthenticated third-party
	 * monitoring systems, see Api\WebhookApi instead, which uses HMAC
	 * signing rather than WordPress user credentials.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit_monitor_result( \WP_REST_Request $request ) {
		$monitor = MonitorManager::get_monitor( (int) $request->get_param( 'id' ) );
		if ( ! $monitor ) {
			return new \WP_Error( 'ssm_not_found', __( 'Monitor not found.', 'service-status-manager' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params() ?: array();
		$state  = ServiceManager::sanitize_status( $params['state'] ?? 'unknown' );

		MonitorManager::set_manual_state( $monitor->id, $state );

		return rest_ensure_response( array( 'updated' => true, 'state' => $state ) );
	}

	/**
	 * POST /monitors/check - triggers an immediate check run.
	 *
	 * @return \WP_REST_Response
	 */
	public function trigger_checks() {
		$count = MonitorRunner::run_due_checks( true );
		return rest_ensure_response( array( 'checked' => $count ) );
	}

	/**
	 * POST /notifications/test
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_notification( \WP_REST_Request $request ) {
		$params  = $request->get_json_params() ?: array();
		$channel = sanitize_key( $params['channel'] ?? '' );
		$to      = sanitize_text_field( $params['destination'] ?? '' );

		$provider = NotificationQueue::get_provider( $channel );
		if ( ! $provider ) {
			return new \WP_Error( 'ssm_unknown_channel', __( 'Unknown notification channel.', 'service-status-manager' ), array( 'status' => 400 ) );
		}

		$result = $provider->send_test( $to );

		return rest_ensure_response( array( 'success' => $result->success, 'error' => $result->error ) );
	}
}
