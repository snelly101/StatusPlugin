<?php
/**
 * WP-CLI commands: `wp service-status provider <command>`.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

use ServiceStatusManager\Notifications\NotificationQueue;
use ServiceStatusManager\Notifications\NotificationCircuitBreaker;
use ServiceStatusManager\Notifications\HealthCheckableProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CliProvider {

	/**
	 * Checks provider configuration/connectivity and reports circuit
	 * breaker state, without sending any real notification.
	 *
	 * ## OPTIONS
	 *
	 * [--channel=<channel>]
	 * : Only check one channel (email|sms|teams). Defaults to all three.
	 *
	 * ## EXAMPLES
	 *
	 *     wp service-status provider health
	 *     wp service-status provider health --channel=email
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function health( $args, $assoc_args ) {
		$channels = isset( $assoc_args['channel'] ) ? array( sanitize_key( $assoc_args['channel'] ) ) : array( 'email', 'sms', 'teams' );

		$rows        = array();
		$any_unhealthy = false;

		foreach ( $channels as $channel ) {
			$slug     = NotificationQueue::resolve_provider_slug( $channel );
			$provider = NotificationQueue::get_provider( $channel );
			$breaker  = NotificationCircuitBreaker::get_state( $slug );

			if ( $provider instanceof HealthCheckableProviderInterface ) {
				$result  = $provider->health_check();
				$healthy = $result['healthy'] ? 'yes' : 'no';
				$message = $result['message'];

				if ( ! $result['healthy'] ) {
					$any_unhealthy = true;
				}
			} else {
				$healthy = 'n/a';
				$message = 'This provider does not support a health check.';
			}

			$rows[] = array(
				'channel'  => $channel,
				'provider' => $slug,
				'healthy'  => $healthy,
				'breaker'  => $breaker['state'],
				'message'  => $message,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'channel', 'provider', 'healthy', 'breaker', 'message' ) );

		if ( $any_unhealthy ) {
			\WP_CLI::warning( 'One or more providers reported unhealthy - see the message column above.' );
		}
	}
}
