<?php
/**
 * Classifies a provider send failure so the queue can decide whether it's
 * worth retrying, rather than applying the same exponential backoff to
 * every failure regardless of cause.
 *
 * A permanently invalid phone number and a transient Twilio 503 used to be
 * treated identically - both burned through every retry attempt before
 * finally failing. This tells the difference: a recipient-level problem
 * (bad number, rejected address) fails immediately without wasting
 * attempts; an account-level problem (bad credentials) fails immediately
 * *and* signals the whole channel is broken, not just this one row; only
 * genuinely transient problems (timeouts, 429s, 5xx) get retried.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NotificationRetryPolicy {

	const TRANSIENT          = 'transient';
	const PERMANENT_RECIPIENT = 'permanent_recipient';
	const PERMANENT_ACCOUNT   = 'permanent_account';

	/**
	 * Classifies a failed send attempt from its HTTP status code (if any)
	 * and error message.
	 *
	 * @param int|null $http_code     HTTP status code, or null for a
	 *                                 connection-level failure (timeout,
	 *                                 DNS/SSL error, connection reset -
	 *                                 i.e. a WP_Error with no response at all).
	 * @param string   $error_message Provider/transport error message, used
	 *                                 only to let a provider-specific filter
	 *                                 refine the default HTTP-status mapping.
	 * @param string   $provider_slug Provider identifier (e.g. 'twilio',
	 *                                 'smtp2go'), passed through to the
	 *                                 per-provider filter hook.
	 * @return string One of self::TRANSIENT, self::PERMANENT_RECIPIENT, self::PERMANENT_ACCOUNT.
	 */
	public static function classify( $http_code, $error_message = '', $provider_slug = '' ) {
		$classification = self::classify_by_status( $http_code );

		/**
		 * Filters the default HTTP-status-based classification, so a
		 * provider can refine it using its own structured error codes
		 * (e.g. distinguishing "temporarily unreachable" from "invalid
		 * number" within the same HTTP status).
		 *
		 * @param string   $classification One of the TRANSIENT/PERMANENT_* constants.
		 * @param int|null $http_code      HTTP status code, if any.
		 * @param string   $error_message  Provider/transport error message.
		 */
		return apply_filters( "ssm_notification_retry_classify_{$provider_slug}", $classification, $http_code, $error_message );
	}

	/**
	 * @param int|null $http_code HTTP status code, or null for a connection-level failure.
	 * @return string
	 */
	private static function classify_by_status( $http_code ) {
		if ( null === $http_code ) {
			// Timeout, DNS failure, connection reset, SSL error - we never
			// even got a response, so we can't know whether the provider
			// actually processed the request. Treat as transient (retry
			// with backoff); the claim lease (see NotificationQueue) is
			// what keeps a retry from racing a still-in-flight original
			// attempt, not this classification.
			return self::TRANSIENT;
		}

		if ( 429 === $http_code || ( $http_code >= 500 && $http_code < 600 ) ) {
			return self::TRANSIENT;
		}

		if ( 401 === $http_code || 403 === $http_code ) {
			// Authentication/authorisation failure - every subsequent
			// request will fail identically until an administrator fixes
			// the credentials. Not a per-recipient problem.
			return self::PERMANENT_ACCOUNT;
		}

		if ( $http_code >= 400 && $http_code < 500 ) {
			// Any other 4xx (invalid recipient, rejected content, etc.) -
			// specific to this one row, never worth retrying as-is.
			return self::PERMANENT_RECIPIENT;
		}

		// Anything else (2xx reaching here would mean the caller already
		// treated the send as successful and wouldn't be classifying it;
		// an unrecognised code) - default to transient rather than
		// silently dropping a notification we're unsure about.
		return self::TRANSIENT;
	}
}
