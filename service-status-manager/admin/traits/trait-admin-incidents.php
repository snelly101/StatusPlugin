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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait AdminIncidentsTrait {

	public function handle_save_incident() {
		$this->guard( 'ssm_save_incident', Capabilities::MANAGE_INCIDENTS );
		wp_die( esc_html__( 'Incident management is not yet available.', 'service-status-manager' ) );
	}

	public function handle_add_incident_update() {
		$this->guard( 'ssm_add_incident_update', Capabilities::EDIT_UPDATES );
		wp_die( esc_html__( 'Incident management is not yet available.', 'service-status-manager' ) );
	}

	public function handle_delete_incident() {
		$this->guard( 'ssm_delete_incident', Capabilities::MANAGE_INCIDENTS );
		wp_die( esc_html__( 'Incident management is not yet available.', 'service-status-manager' ) );
	}

	public function handle_save_maintenance() {
		$this->guard( 'ssm_save_maintenance', Capabilities::MANAGE_INCIDENTS );
		wp_die( esc_html__( 'Maintenance management is not yet available.', 'service-status-manager' ) );
	}

	public function handle_delete_maintenance() {
		$this->guard( 'ssm_delete_maintenance', Capabilities::MANAGE_INCIDENTS );
		wp_die( esc_html__( 'Maintenance management is not yet available.', 'service-status-manager' ) );
	}
}
