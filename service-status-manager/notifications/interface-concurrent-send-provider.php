<?php
/**
 * Optional companion to NotificationProviderInterface: a provider that can
 * accept a whole claimed batch and send it concurrently (via
 * ConcurrentHttpClient) instead of one send() call at a time.
 *
 * The base interface stays untouched by this - a provider that doesn't
 * implement this one is dispatched exactly as before (a serial loop over
 * send()), so adding concurrency support to a provider is strictly
 * additive and never a breaking change to existing providers.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ConcurrentSendProviderInterface {

	/**
	 * Sends many notifications concurrently.
	 *
	 * @param array $items Keyed by an opaque caller-chosen key (the queue
	 *                      row ID in practice) => array{
	 *     subscriber: object,
	 *     payload: array,
	 * }.
	 * @return array Keyed the same as $items => SendResult. A provider must
	 *               return a result for every key it was given, even a
	 *               failure one - a missing key is treated by the caller as
	 *               "no result returned" and reported as a failure.
	 */
	public function send_many( array $items );
}
