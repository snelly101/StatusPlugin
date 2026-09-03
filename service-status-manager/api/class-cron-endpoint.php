<?php
/**
 * Secured HTTP endpoint for driving monitor checks and notification
 * processing from a real system cron job, for deployments where WP-Cron
 * (which only fires on incoming site traffic) is not reliable enough -
 * see Cron and Monitoring\MonitorRunner for background.
 *
 * Example crontab entry:
 *
 *   * * * * * curl -fsS "https://example.com/wp-json/service-status-manager/v1/cron/run?token=SECRET" >/dev/null
 *
 * The token is a 32-byte random secret (Settings > Tools), compared with
 * a constant-time comparison, and the response never includes monitor
 * configuration, credentials, or subscriber data - only aggregate counts.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Api;

use ServiceStatusManager\RateLimiter;
use ServiceStatusManager\MaintenanceManager;
use ServiceStatusManager\Monitoring\MonitorRunner;
use ServiceStatusManager\Notifications\NotificationDispatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CronEndpoint {

	/**
	 * Registers this controller's route.
	 */
	public function register_routes() {
		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/cron/run',
			array(
				'methods'             => 'GET,POST',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run( \WP_REST_Request $request ) {
		if ( ! RateLimiter::allow( 'cron_endpoint_' . RateLimiter::client_fingerprint(), 30, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'ssm_rate_limited', __( 'Too many requests.', 'service-status-manager' ), array( 'status' => 429 ) );
		}

		$provided = $request->get_param( 'token' );
		if ( ! $provided ) {
			$provided = $request->get_header( 'X-SSM-Cron-Secret' );
		}

		$expected = \ssm_get_setting( 'cron_secret', '' );

		if ( empty( $expected ) || empty( $provided ) || ! hash_equals( $expected, (string) $provided ) ) {
			\ssm_log( 'Rejected cron endpoint request: invalid or missing token.', 'warning' );
			return new \WP_Error( 'ssm_forbidden', __( 'Invalid token.', 'service-status-manager' ), array( 'status' => 403 ) );
		}

		// A self-chained hop from NotificationDispatcher only needs to
		// re-enter the dispatcher - re-running monitor checks and
		// maintenance transitions on every fast chained hop would be
		// wasted work (they already run on the normal scope=full tick).
		$scope = sanitize_key( $request->get_param( 'scope' ) ?: 'full' );

		if ( 'notifications' === $scope ) {
			$result = NotificationDispatcher::run_once( 'all', null, 'cron_chain' );

			return rest_ensure_response(
				array(
					'ok'                      => true,
					'scope'                   => 'notifications',
					'notifications_processed' => $result['rows_sent'] + $result['rows_failed'],
					'timestamp'               => \ssm_now(),
				)
			);
		}

		$checked = MonitorRunner::run_due_checks();
		$result  = NotificationDispatcher::run_once( 'all', null, 'cron_endpoint' );
		MaintenanceManager::process_transitions();

		return rest_ensure_response(
			array(
				'ok'                     => true,
				'monitors_checked'       => $checked,
				'notifications_processed' => $result['rows_sent'] + $result['rows_failed'],
				'timestamp'              => \ssm_now(),
			)
		);
	}
}
