<?php
/**
 * Per-provider circuit breaker: stops hammering a provider that's
 * genuinely down (as opposed to one recipient having a bad address).
 *
 * State lives in a single small autoload-false option (a handful of
 * providers' worth of state - not worth a dedicated table), following the
 * same lightweight pattern already used for `ssm_last_notification_run`.
 * A short-lived race between two overlapping runs writing this option is
 * harmless: the breaker's job is "stop hammering a dead provider soon",
 * not exact-once failure counting.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NotificationCircuitBreaker {

	const STATE_CLOSED    = 'closed';
	const STATE_OPEN       = 'open';
	const STATE_HALF_OPEN   = 'half_open';

	const OPTION_KEY = 'ssm_circuit_breaker_state';

	/**
	 * Whether sends to this provider should currently be blocked. Also
	 * responsible for the open -> half-open transition once the cooldown
	 * has elapsed (a probe request is allowed through the moment it flips).
	 *
	 * @param string $provider_slug e.g. 'twilio', 'smtp2go'.
	 * @return bool
	 */
	public static function is_open( $provider_slug ) {
		$state = self::get_state( $provider_slug );

		if ( self::STATE_OPEN !== $state['state'] ) {
			return false;
		}

		$cooldown = self::cooldown_seconds( $provider_slug );

		if ( $state['opened_at'] && ( time() - (int) $state['opened_at'] ) >= $cooldown ) {
			self::set_state( $provider_slug, array_merge( $state, array( 'state' => self::STATE_HALF_OPEN ) ) );
			return false;
		}

		return true;
	}

	/**
	 * Records a successful send, closing the breaker (from any state).
	 *
	 * @param string $provider_slug Provider identifier.
	 */
	public static function record_success( $provider_slug ) {
		$state = self::get_state( $provider_slug );

		if ( self::STATE_CLOSED === $state['state'] && 0 === $state['consecutive_failures'] ) {
			return;
		}

		self::set_state(
			$provider_slug,
			array(
				'state'                => self::STATE_CLOSED,
				'consecutive_failures' => 0,
				'opened_at'            => null,
			)
		);
	}

	/**
	 * Records a failed send. Only transient failures count toward the
	 * trip threshold - a permanent, recipient-specific failure (bad phone
	 * number) says nothing about provider health. A permanent
	 * account-level failure (bad credentials) force-opens immediately,
	 * since every subsequent request is certain to fail identically.
	 *
	 * @param string $provider_slug  Provider identifier.
	 * @param string $classification One of NotificationRetryPolicy's classification constants.
	 */
	public static function record_failure( $provider_slug, $classification ) {
		if ( NotificationRetryPolicy::PERMANENT_ACCOUNT === $classification ) {
			self::force_open( $provider_slug );
			return;
		}

		if ( NotificationRetryPolicy::PERMANENT_RECIPIENT === $classification ) {
			return;
		}

		$state = self::get_state( $provider_slug );

		if ( self::STATE_HALF_OPEN === $state['state'] ) {
			// The probe failed - back to fully open, cooldown restarts.
			self::force_open( $provider_slug );
			return;
		}

		$failures  = (int) $state['consecutive_failures'] + 1;
		$threshold = (int) apply_filters( 'ssm_circuit_breaker_threshold', \ssm_get_setting( 'circuit_breaker_failure_threshold', 5 ), $provider_slug );

		if ( $failures >= $threshold ) {
			self::force_open( $provider_slug );
			return;
		}

		self::set_state( $provider_slug, array_merge( $state, array( 'consecutive_failures' => $failures ) ) );
	}

	/**
	 * Immediately trips the breaker open, regardless of failure count.
	 *
	 * @param string $provider_slug Provider identifier.
	 */
	public static function force_open( $provider_slug ) {
		$state = self::get_state( $provider_slug );

		self::set_state(
			$provider_slug,
			array(
				'state'                => self::STATE_OPEN,
				'consecutive_failures' => max( 1, (int) $state['consecutive_failures'] ),
				'opened_at'            => time(),
			)
		);
	}

	/**
	 * @param string $provider_slug Provider identifier.
	 * @return array{state:string,consecutive_failures:int,opened_at:int|null}
	 */
	public static function get_state( $provider_slug ) {
		$all     = get_option( self::OPTION_KEY, array() );
		$default = array(
			'state'                => self::STATE_CLOSED,
			'consecutive_failures' => 0,
			'opened_at'            => null,
		);

		if ( ! isset( $all[ $provider_slug ] ) || ! is_array( $all[ $provider_slug ] ) ) {
			return $default;
		}

		return wp_parse_args( $all[ $provider_slug ], $default );
	}

	/**
	 * @param string $provider_slug Provider identifier.
	 * @param array  $state         New state for this provider.
	 */
	private static function set_state( $provider_slug, array $state ) {
		$all                    = get_option( self::OPTION_KEY, array() );
		$all[ $provider_slug ]  = $state;
		update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * @param string $provider_slug Provider identifier.
	 * @return int
	 */
	private static function cooldown_seconds( $provider_slug ) {
		return (int) apply_filters( 'ssm_circuit_breaker_cooldown_seconds', \ssm_get_setting( 'circuit_breaker_cooldown_seconds', 60 ), $provider_slug );
	}
}
