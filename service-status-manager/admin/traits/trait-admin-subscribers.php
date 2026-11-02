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

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// "Everything" ticked means no specific selections - the backend
		// treats an empty selection set as "notify about everything" (see
		// SubscriberManager::get_matching_subscribers()), exactly as the
		// public manage page does.
		$following_everything = ! empty( $post['following_everything'] );

		$result = SubscriberManager::admin_update_subscriber(
			$id,
			array(
				'name'        => $post['name'] ?? '',
				'status'      => $post['status'] ?? '',
				'preferences' => array(
					'min_severity'              => $post['min_severity'] ?? '',
					'maintenance_notifications' => ! empty( $post['maintenance_notifications'] ),
				),
				'selections'  => $following_everything ? array() : array(
					'group'   => (array) ( $post['groups'] ?? array() ),
					'service' => (array) ( $post['services'] ?? array() ),
					'monitor' => (array) ( $post['monitors'] ?? array() ),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'subscribers', $result->get_error_message(), 'error' );
		}

		$this->redirect_with_notice( 'subscribers', __( 'Subscriber updated.', 'service-status-manager' ) );
	}

	public function handle_verify_channel() {
		$this->guard( 'ssm_verify_channel', Capabilities::MANAGE_SUBSCRIBERS );

		$result = SubscriberManager::admin_verify_channel( absint( $_POST['id'] ?? 0 ), sanitize_key( wp_unslash( $_POST['channel'] ?? '' ) ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'subscribers', $result->get_error_message(), 'error' );
		}

		$this->redirect_with_notice( 'subscribers', __( 'Channel verified.', 'service-status-manager' ) );
	}

	public function handle_delete_subscriber() {
		$this->guard( 'ssm_delete_subscriber', Capabilities::MANAGE_SUBSCRIBERS );
		SubscriberManager::admin_delete_subscriber( absint( $_POST['id'] ?? 0 ) );
		$this->redirect_with_notice( 'subscribers', __( 'Subscriber and all their personal data have been deleted.', 'service-status-manager' ) );
	}
}
