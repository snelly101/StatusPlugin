<?php
/**
 * Registers plugin-specific capabilities and roles.
 *
 * Every sensitive action in the plugin is gated on a capability rather than
 * a hard-coded role name (current_user_can(), never a role check), so site
 * owners can re-map who holds these capabilities using any role-management
 * plugin without touching plugin code.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capabilities {

	const MANAGE       = 'ssm_manage_status'; // Full plugin access (Status administrator).
	const MANAGE_INCIDENTS = 'ssm_manage_incidents'; // Create/update incidents & maintenance (Incident manager).
	const EDIT_UPDATES = 'ssm_edit_updates'; // Add public incident updates (Status editor).
	const VIEW          = 'ssm_view_status'; // Read-only dashboard/reports access (Status viewer).
	const MANAGE_SUBSCRIBERS = 'ssm_manage_subscribers';
	const MANAGE_SETTINGS    = 'ssm_manage_settings';
	const MANAGE_INTEGRATIONS = 'ssm_manage_integrations';
	const EXPORT_DATA        = 'ssm_export_data';
	const DELETE_DATA        = 'ssm_delete_data';

	/**
	 * Returns every capability introduced by this plugin.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::MANAGE,
			self::MANAGE_INCIDENTS,
			self::EDIT_UPDATES,
			self::VIEW,
			self::MANAGE_SUBSCRIBERS,
			self::MANAGE_SETTINGS,
			self::MANAGE_INTEGRATIONS,
			self::EXPORT_DATA,
			self::DELETE_DATA,
		);
	}

	/**
	 * Creates the plugin's custom roles and grants capabilities to
	 * administrator, adding all plugin capabilities so existing site
	 * admins retain full control after activation.
	 */
	public static function install() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		add_role(
			'ssm_status_administrator',
			__( 'Status Administrator', 'service-status-manager' ),
			array_fill_keys( self::all(), true ) + array( 'read' => true )
		);

		add_role(
			'ssm_incident_manager',
			__( 'Incident Manager', 'service-status-manager' ),
			array(
				'read'                     => true,
				self::MANAGE_INCIDENTS     => true,
				self::EDIT_UPDATES         => true,
				self::VIEW                 => true,
				self::MANAGE_SUBSCRIBERS   => true,
			)
		);

		add_role(
			'ssm_status_editor',
			__( 'Status Editor', 'service-status-manager' ),
			array(
				'read'             => true,
				self::EDIT_UPDATES => true,
				self::VIEW         => true,
			)
		);

		add_role(
			'ssm_status_viewer',
			__( 'Status Viewer', 'service-status-manager' ),
			array(
				'read'     => true,
				self::VIEW => true,
			)
		);
	}

	/**
	 * Removes plugin capabilities from all roles. Called on uninstall only.
	 */
	public static function remove() {
		global $wp_roles;

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = wp_roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		foreach ( $wp_roles->roles as $role_name => $role_info ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}

		remove_role( 'ssm_status_administrator' );
		remove_role( 'ssm_incident_manager' );
		remove_role( 'ssm_status_editor' );
		remove_role( 'ssm_status_viewer' );
	}
}
