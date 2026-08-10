<?php
/**
 * Admin\Admin trait: incident and maintenance form handlers.
 *
 * Split out of class-admin.php purely to keep that file to a manageable
 * size; these methods execute with full access to Admin's private helpers
 * (guard(), redirect_with_notice()) because PHP traits are compiled into
 * the composing class.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\IncidentManager;
use ServiceStatusManager\MaintenanceManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait AdminIncidentsTrait {

	public function handle_save_incident() {
		$this->guard( 'ssm_save_incident', Capabilities::MANAGE_INCIDENTS );

		$id   = absint( $_POST['id'] ?? 0 );
		$data = array(
			'title'                => wp_unslash( $_POST['title'] ?? '' ),
			'description'          => wp_unslash( $_POST['description'] ?? '' ),
			'internal_notes'       => wp_unslash( $_POST['internal_notes'] ?? '' ),
			'severity'             => wp_unslash( $_POST['severity'] ?? 'minor' ),
			'status'               => wp_unslash( $_POST['status'] ?? 'investigating' ),
			'starts_at'            => ! empty( $_POST['starts_at'] ) ? $this->local_to_utc( wp_unslash( $_POST['starts_at'] ) ) : '',
			'scheduled_publish_at' => ! empty( $_POST['scheduled_publish_at'] ) ? $this->local_to_utc( wp_unslash( $_POST['scheduled_publish_at'] ) ) : '',
			'is_public'            => ! empty( $_POST['is_public'] ),
			'notify'               => ! empty( $_POST['notify'] ),
			'is_pinned'            => ! empty( $_POST['is_pinned'] ),
			'root_cause'           => wp_unslash( $_POST['root_cause'] ?? '' ),
			'resolution'           => wp_unslash( $_POST['resolution'] ?? '' ),
			'service_ids'          => array_map( 'absint', (array) ( $_POST['service_ids'] ?? array() ) ),
			'monitor_ids'          => array_map( 'absint', (array) ( $_POST['monitor_ids'] ?? array() ) ),
			'initial_message'      => wp_unslash( $_POST['initial_message'] ?? '' ),
		);

		if ( $id ) {
			$result = IncidentManager::update_incident( $id, $data );
		} else {
			$result = IncidentManager::create_incident( $data );
			$id     = is_wp_error( $result ) ? 0 : $result;
		}

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'incidents', $result->get_error_message(), 'error' );
		}

		$this->redirect_with_notice( 'incidents', __( 'Incident saved.', 'service-status-manager' ), 'success', array( 'incident_id' => $id ) );
	}

	public function handle_add_incident_update() {
		$this->guard( 'ssm_add_incident_update', Capabilities::EDIT_UPDATES );

		$incident_id = absint( $_POST['incident_id'] ?? 0 );
		$status      = sanitize_key( wp_unslash( $_POST['status'] ?? 'investigating' ) );
		$message     = wp_unslash( $_POST['message'] ?? '' );
		$is_internal = ! empty( $_POST['is_internal'] );

		if ( $is_internal && ! current_user_can( Capabilities::MANAGE_INCIDENTS ) ) {
			$is_internal = false;
		}

		$result = IncidentManager::add_update( $incident_id, $status, $message, $is_internal );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'incidents', $result->get_error_message(), 'error', array( 'incident_id' => $incident_id ) );
		}

		$this->redirect_with_notice( 'incidents', __( 'Update added.', 'service-status-manager' ), 'success', array( 'incident_id' => $incident_id ) );
	}

	public function handle_delete_incident() {
		$this->guard( 'ssm_delete_incident', Capabilities::MANAGE_INCIDENTS );
		IncidentManager::delete_incident( absint( $_POST['id'] ?? 0 ) );
		$this->redirect_with_notice( 'incidents', __( 'Incident deleted.', 'service-status-manager' ) );
	}

	public function handle_save_maintenance() {
		$this->guard( 'ssm_save_maintenance', Capabilities::MANAGE_INCIDENTS );

		$id   = absint( $_POST['id'] ?? 0 );
		$data = array(
			'title'               => wp_unslash( $_POST['title'] ?? '' ),
			'description'         => wp_unslash( $_POST['description'] ?? '' ),
			'scheduled_start'     => wp_unslash( $_POST['scheduled_start'] ?? '' ),
			'scheduled_end'       => wp_unslash( $_POST['scheduled_end'] ?? '' ),
			'timezone'            => wp_unslash( $_POST['timezone'] ?? wp_timezone_string() ),
			'impact'              => wp_unslash( $_POST['impact'] ?? 'none' ),
			'is_public'           => ! empty( $_POST['is_public'] ),
			'notify_on_announce'  => ! empty( $_POST['notify_on_announce'] ),
			'notify_on_start'     => ! empty( $_POST['notify_on_start'] ),
			'notify_on_complete'  => ! empty( $_POST['notify_on_complete'] ),
			'notify_on_extend'    => ! empty( $_POST['notify_on_extend'] ),
			'notify_on_cancel'    => ! empty( $_POST['notify_on_cancel'] ),
			'reminder_hours'      => array_map( 'absint', (array) ( $_POST['reminder_hours'] ?? array() ) ),
			'service_ids'         => array_map( 'absint', (array) ( $_POST['service_ids'] ?? array() ) ),
			'monitor_ids'         => array_map( 'absint', (array) ( $_POST['monitor_ids'] ?? array() ) ),
		);

		// datetime-local inputs submit the site's configured timezone; convert to UTC for storage.
		if ( ! empty( $data['scheduled_start'] ) ) {
			$data['scheduled_start'] = $this->local_to_utc( $data['scheduled_start'] );
		}
		if ( ! empty( $data['scheduled_end'] ) ) {
			$data['scheduled_end'] = $this->local_to_utc( $data['scheduled_end'] );
		}

		if ( $id ) {
			$result = MaintenanceManager::update_maintenance( $id, $data );
		} else {
			$result = MaintenanceManager::create_maintenance( $data );
			$id     = is_wp_error( $result ) ? 0 : $result;
		}

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'maintenance', $result->get_error_message(), 'error' );
		}

		$this->redirect_with_notice( 'maintenance', __( 'Maintenance saved.', 'service-status-manager' ), 'success', array( 'maintenance_id' => $id ) );
	}

	public function handle_delete_maintenance() {
		$this->guard( 'ssm_delete_maintenance', Capabilities::MANAGE_INCIDENTS );

		if ( isset( $_POST['cancel_only'] ) ) {
			MaintenanceManager::cancel_maintenance( absint( $_POST['id'] ?? 0 ) );
			$this->redirect_with_notice( 'maintenance', __( 'Maintenance cancelled.', 'service-status-manager' ) );
		}

		MaintenanceManager::delete_maintenance( absint( $_POST['id'] ?? 0 ) );
		$this->redirect_with_notice( 'maintenance', __( 'Maintenance deleted.', 'service-status-manager' ) );
	}

	/**
	 * Converts a "Y-m-d\TH:i" datetime-local value in the site's configured
	 * timezone into a UTC MySQL datetime string for storage.
	 *
	 * @param string $local_datetime Local datetime string.
	 * @return string
	 */
	private function local_to_utc( $local_datetime ) {
		try {
			$date = new \DateTime( $local_datetime, wp_timezone() );
			$date->setTimezone( new \DateTimeZone( 'UTC' ) );
			return $date->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return $local_datetime;
		}
	}
}
