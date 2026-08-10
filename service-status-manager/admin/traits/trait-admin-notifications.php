<?php
/**
 * Admin\Admin trait: notification queue form handlers.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\Notifications\NotificationQueue;
use ServiceStatusManager\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait AdminNotificationsTrait {

	public function handle_retry_notification() {
		$this->guard( 'ssm_retry_notification', Capabilities::MANAGE_SUBSCRIBERS );
		NotificationQueue::retry( absint( $_POST['id'] ?? 0 ) );
		$this->redirect_with_notice( 'notifications', __( 'Notification queued for retry.', 'service-status-manager' ) );
	}

	public function handle_cancel_notification() {
		$this->guard( 'ssm_cancel_notification', Capabilities::MANAGE_SUBSCRIBERS );
		NotificationQueue::cancel( absint( $_POST['id'] ?? 0 ) );
		$this->redirect_with_notice( 'notifications', __( 'Notification cancelled.', 'service-status-manager' ) );
	}

	public function handle_send_test_notification() {
		$this->guard( 'ssm_send_test_notification', Capabilities::MANAGE_INTEGRATIONS );

		if ( ! RateLimiter::allow( 'test_notification_' . get_current_user_id(), 10, 10 * MINUTE_IN_SECONDS ) ) {
			$this->redirect_with_notice( 'notifications', __( 'Too many test notifications sent recently. Please wait a few minutes.', 'service-status-manager' ), 'error' );
		}

		$channel     = sanitize_key( wp_unslash( $_POST['channel'] ?? '' ) );
		$destination = sanitize_text_field( wp_unslash( $_POST['destination'] ?? '' ) );

		$provider = NotificationQueue::get_provider( $channel );
		if ( ! $provider ) {
			$this->redirect_with_notice( 'notifications', __( 'Unknown notification channel.', 'service-status-manager' ), 'error' );
		}

		$result = $provider->send_test( $destination );

		\ServiceStatusManager\AuditLog::record( 'test_notification_sent', 'notification', null, null, array( 'channel' => $channel, 'success' => $result->success ) );

		if ( $result->success ) {
			$this->redirect_with_notice( 'notifications', __( 'Test notification sent successfully.', 'service-status-manager' ) );
		}

		$this->redirect_with_notice( 'notifications', sprintf(
			/* translators: %s: error message */
			__( 'Test notification failed: %s', 'service-status-manager' ),
			$result->error
		), 'error' );
	}
}
