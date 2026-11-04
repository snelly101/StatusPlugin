<?php
/**
 * Per-provider outbound throttling, built on the existing RateLimiter
 * primitive (already proven at the cron/webhook endpoints) rather than a
 * new token-bucket implementation - at the request-per-second volumes
 * involved here, a short 1-second fixed window checked once per row is
 * simple, correct, and cheap.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

use ServiceStatusManager\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NotificationRateLimiter {

	/**
	 * Reserves as much of the requested capacity as the provider's
	 * configured per-second limit currently allows. Any amount not
	 * reserved simply isn't sent this call - the caller is expected to
	 * leave those rows queued for the next pass (this run's next loop
	 * iteration, or the next chained hop moments later) rather than
	 * waiting/blocking for capacity to free up.
	 *
	 * @param string $provider_slug  Provider identifier (e.g. 'twilio', 'smtp2go').
	 * @param int    $max_per_second Configured limit. 0 or less means unlimited.
	 * @param int    $wanted         How many sends the caller would like to make right now.
	 * @return int Number actually granted (0 &lt;= n &lt;= $wanted).
	 */
	public static function reserve_capacity( $provider_slug, $max_per_second, $wanted ) {
		$wanted = max( 0, (int) $wanted );

		if ( $max_per_second <= 0 ) {
			return $wanted;
		}

		$granted = 0;

		// Fixed one-second windows keyed by wall-clock second, rather than
		// one key with a sliding 1s TTL. WordPress's default (non-persistent)
		// object cache ignores expiry, so within a single long dispatcher
		// run a sliding key stayed "full" after the first $max_per_second
		// grants and refused every later pass - precisely the run that now
		// waits a moment for capacity to come back. A fresh key each second
		// resets the count without depending on cache expiry at all; the
		// 2s transient TTL just tidies up behind it.
		$window_key = 'ssm_notify_rl_' . $provider_slug . ':' . time();

		for ( $i = 0; $i < $wanted; $i++ ) {
			if ( ! RateLimiter::allow( $window_key, (int) $max_per_second, 2 ) ) {
				break;
			}
			++$granted;
		}

		return $granted;
	}
}
