<?php
/**
 * Admin\Admin trait: subscriber form handlers. Implemented in full in
 * Phase 5 (see class-subscriber-manager.php).
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait AdminSubscribersTrait {

	public function handle_save_subscriber() {
		$this->guard( 'ssm_save_subscriber', Capabilities::MANAGE_SUBSCRIBERS );
		wp_die( esc_html__( 'Subscriber management is not yet available.', 'service-status-manager' ) );
	}

	public function handle_delete_subscriber() {
		$this->guard( 'ssm_delete_subscriber', Capabilities::MANAGE_SUBSCRIBERS );
		wp_die( esc_html__( 'Subscriber management is not yet available.', 'service-status-manager' ) );
	}
}
