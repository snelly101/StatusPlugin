<?php
/**
 * Admin\Admin trait: notification queue form handlers. Implemented in full
 * in Phase 6 (see notifications/class-notification-queue.php).
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait AdminNotificationsTrait {

	public function handle_retry_notification() {
		$this->guard( 'ssm_retry_notification', Capabilities::MANAGE_SUBSCRIBERS );
		wp_die( esc_html__( 'Notification management is not yet available.', 'service-status-manager' ) );
	}

	public function handle_cancel_notification() {
		$this->guard( 'ssm_cancel_notification', Capabilities::MANAGE_SUBSCRIBERS );
		wp_die( esc_html__( 'Notification management is not yet available.', 'service-status-manager' ) );
	}

	public function handle_send_test_notification() {
		$this->guard( 'ssm_send_test_notification', Capabilities::MANAGE_INTEGRATIONS );
		wp_die( esc_html__( 'Notification management is not yet available.', 'service-status-manager' ) );
	}
}
