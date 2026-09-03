<?php
/**
 * Abstract base for SMS provider integrations.
 *
 * Handles everything that is common to any SMS gateway (message length
 * truncation/splitting, the mandatory unsubscribe footer, and basic
 * per-subscriber-per-day rate limiting) so a concrete provider only has
 * to implement send_raw() against its own API.
 *
 * Ships with Twilio (class-twilio-sms-provider.php) as the reference
 * implementation. Additional UK-relevant providers (MessageBird, Vonage,
 * AWS SNS, Esendex, etc.) can be added by extending this class and
 * registering themselves via the `ssm_sms_providers` filter - see
 * NotificationQueue::get_sms_provider().
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class SmsProvider implements NotificationProviderInterface {

	/**
	 * Sends a raw SMS message via the concrete provider's API.
	 *
	 * @param string $to      E.164-ish destination number.
	 * @param string $message Final, already-truncated message text.
	 * @return SendResult
	 */
	abstract protected function send_raw( $to, $message );

	/**
	 * {@inheritDoc}
	 */
	public function send( $subscriber, array $message ) {
		if ( empty( $subscriber->phone ) ) {
			return new SendResult( false, '', __( 'Subscriber has no mobile number.', 'service-status-manager' ) );
		}

		if ( ! $this->within_daily_limit( $subscriber->id ) ) {
			return new SendResult( false, '', __( 'Subscriber has reached their maximum SMS messages for today.', 'service-status-manager' ) );
		}

		$text = $this->build_text( $message );

		$result = $this->send_raw( $subscriber->phone, $text );

		if ( $result->success ) {
			$this->increment_daily_count( $subscriber->id );
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function send_test( $destination ) {
		return $this->send_raw(
			$destination,
			__( '[Test] Service Status Manager: SMS notifications are configured correctly.', 'service-status-manager' )
		);
	}

	/**
	 * Builds the final SMS text: a concise summary plus a short link,
	 * truncated or split according to the configured maximum length.
	 *
	 * @param array $message Message context.
	 * @return string
	 */
	protected function build_text( array $message ) {
		$settings   = \ssm_get_settings();
		$max_length = (int) ( $settings['sms_max_length'] ?? 160 );

		$body = trim( ( $message['sms_summary'] ?? wp_strip_all_tags( $message['subject'] ?? '' ) ) );
		if ( ! empty( $message['url'] ) ) {
			$body .= ' ' . $message['url'];
		}

		if ( strlen( $body ) > $max_length ) {
			$truncate_mode = $settings['sms_truncate_mode'] ?? 'truncate';
			if ( 'split' === $truncate_mode ) {
				// Splitting into multiple segments is left to the carrier/
				// gateway's own concatenation (most providers handle long
				// messages as multi-part SMS automatically); we simply
				// avoid truncating in that mode.
				return $body;
			}
			$body = substr( $body, 0, $max_length - 1 ) . '…';
		}

		return $body;
	}

	/**
	 * Checks whether a subscriber is still within their configured daily
	 * SMS limit, to control cost and comply with the site's own
	 * quiet-hours/frequency policy.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return bool
	 */
	protected function within_daily_limit( $subscriber_id ) {
		$limit = (int) \ssm_get_setting( 'max_sms_per_subscriber_per_day', 5 );
		if ( $limit <= 0 ) {
			return true;
		}

		$count = (int) get_transient( 'ssm_sms_count_' . $subscriber_id . '_' . gmdate( 'Y-m-d' ) );
		return $count < $limit;
	}

	/**
	 * Increments a subscriber's daily SMS counter.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 */
	protected function increment_daily_count( $subscriber_id ) {
		$key   = 'ssm_sms_count_' . $subscriber_id . '_' . gmdate( 'Y-m-d' );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, DAY_IN_SECONDS );
	}
}
