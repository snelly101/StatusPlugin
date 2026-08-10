<?php
/**
 * Admin\Admin trait: settings, status page, webhook, report and log form
 * handlers. Implemented in full in Phase 8.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait AdminSettingsTrait {

	public function handle_save_settings() {
		$this->guard( 'ssm_save_settings', Capabilities::MANAGE_SETTINGS );
		wp_die( esc_html__( 'Settings are not yet available.', 'service-status-manager' ) );
	}

	public function handle_save_status_page() {
		$this->guard( 'ssm_save_status_page', Capabilities::MANAGE );
		wp_die( esc_html__( 'Status page management is not yet available.', 'service-status-manager' ) );
	}

	public function handle_save_webhook_out() {
		$this->guard( 'ssm_save_webhook_out', Capabilities::MANAGE_INTEGRATIONS );
		wp_die( esc_html__( 'Webhook management is not yet available.', 'service-status-manager' ) );
	}

	public function handle_delete_webhook_out() {
		$this->guard( 'ssm_delete_webhook_out', Capabilities::MANAGE_INTEGRATIONS );
		wp_die( esc_html__( 'Webhook management is not yet available.', 'service-status-manager' ) );
	}

	public function handle_save_webhook_in() {
		$this->guard( 'ssm_save_webhook_in', Capabilities::MANAGE_INTEGRATIONS );
		wp_die( esc_html__( 'Webhook management is not yet available.', 'service-status-manager' ) );
	}

	public function handle_delete_webhook_in() {
		$this->guard( 'ssm_delete_webhook_in', Capabilities::MANAGE_INTEGRATIONS );
		wp_die( esc_html__( 'Webhook management is not yet available.', 'service-status-manager' ) );
	}

	public function handle_export_report() {
		$this->guard( 'ssm_export_report', Capabilities::VIEW );
		wp_die( esc_html__( 'Reporting is not yet available.', 'service-status-manager' ) );
	}

	public function handle_download_logs() {
		$this->guard( 'ssm_download_logs', Capabilities::MANAGE );
		wp_die( esc_html__( 'Log export is not yet available.', 'service-status-manager' ) );
	}

	public function handle_uninstall_setting() {
		$this->guard( 'ssm_uninstall_setting', Capabilities::MANAGE_SETTINGS );
		wp_die( esc_html__( 'Settings are not yet available.', 'service-status-manager' ) );
	}
}
