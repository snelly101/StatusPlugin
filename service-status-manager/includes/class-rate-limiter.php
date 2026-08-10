<?php
/**
 * Simple fixed-window rate limiter for public endpoints (subscription
 * form, resend-confirmation, token actions, test notifications) backed by
 * the WordPress object cache when available, falling back to transients.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RateLimiter {

	/**
	 * Checks whether an action identified by $key is still allowed within
	 * the given window, incrementing its counter as a side effect.
	 *
	 * @param string $key          Unique rate-limit bucket key.
	 * @param int    $max_attempts Maximum attempts allowed per window.
	 * @param int    $window       Window length in seconds.
	 * @return bool True if allowed, false if the limit has been exceeded.
	 */
	public static function allow( $key, $max_attempts, $window ) {
		$cache_key = 'ssm_rl_' . md5( $key );

		$count = wp_cache_get( $cache_key, 'ssm_rate_limit' );

		if ( false === $count ) {
			$count = (int) get_transient( $cache_key );
		}

		if ( $count >= $max_attempts ) {
			return false;
		}

		++$count;

		wp_cache_set( $cache_key, $count, 'ssm_rate_limit', $window );
		set_transient( $cache_key, $count, $window );

		return true;
	}

	/**
	 * Builds a best-effort fingerprint for anonymous public requests
	 * (IP address only - never anything that could identify a specific
	 * subscriber, to avoid the fingerprint itself becoming personal data
	 * beyond what is already logged).
	 *
	 * @return string
	 */
	public static function client_fingerprint() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return hash( 'sha256', $ip );
	}
}
