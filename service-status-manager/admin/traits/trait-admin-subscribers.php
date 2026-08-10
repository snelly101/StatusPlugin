<?php
/**
 * Admin\Admin trait: subscriber form handlers.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\Capabilities;
use ServiceStatusManager\SubscriberManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait AdminSubscribersTrait {

	public function handle_save_subscriber() {
		$this->guard( 'ssm_save_subscriber', Capabilities::MANAGE_SUBSCRIBERS );

		$id = absint( $_POST['id'] ?? 0 );

		if ( ! $id ) {
			$this->redirect_with_notice( 'subscribers', __( 'New subscribers must sign up through the public subscription form so consent is recorded correctly.', 'service-status-manager' ), 'error' );
		}

		$result = SubscriberManager::admin_update_subscriber(
			$id,
			array(
				'name'   => wp_unslash( $_POST['name'] ?? '' ),
				'status' => wp_unslash( $_POST['status'] ?? '' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'subscribers', $result->get_error_message(), 'error' );
		}

		$this->redirect_with_notice( 'subscribers', __( 'Subscriber updated.', 'service-status-manager' ) );
	}

	public function handle_delete_subscriber() {
		$this->guard( 'ssm_delete_subscriber', Capabilities::MANAGE_SUBSCRIBERS );
		SubscriberManager::admin_delete_subscriber( absint( $_POST['id'] ?? 0 ) );
		$this->redirect_with_notice( 'subscribers', __( 'Subscriber and all their personal data have been deleted.', 'service-status-manager' ) );
	}
}
