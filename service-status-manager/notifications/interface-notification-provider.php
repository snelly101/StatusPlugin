<?php
/**
 * Contract every notification channel provider must implement.
 *
 * Providers are intentionally "dumb": they receive an already-rendered
 * subject/body plus whatever destination-specific data they need, and
 * report back success/failure. All the decision-making about *whether*
 * to notify a subscriber (their selections, severity preference, quiet
 * hours, verification state, rate limits) happens upstream in
 * NotificationManager and NotificationQueue - providers never query the
 * database themselves.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface NotificationProviderInterface {

	/**
	 * Sends a single notification.
	 *
	 * @param object $subscriber Subscriber row.
	 * @param array  $message    {subject: string, body_html: string, body_text: string, event_type: string, url: string, ...payload}.
	 * @return SendResult
	 */
	public function send( $subscriber, array $message );

	/**
	 * Sends a test message to verify the provider/channel is configured
	 * correctly, used by the "Send test notification" admin tool.
	 *
	 * @param string $destination Destination address/number/webhook URL.
	 * @return SendResult
	 */
	public function send_test( $destination );
}
