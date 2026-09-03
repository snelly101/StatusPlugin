<?php
/**
 * Admin\Admin trait: Appearance settings form handlers (save, reset,
 * export, import). Purely visual - never touches services, monitors,
 * incidents, subscribers, notifications or provider credentials.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\Capabilities;
use ServiceStatusManager\AppearanceSettings;
use ServiceStatusManager\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait AdminAppearanceTrait {

	public function handle_save_appearance() {
		$this->guard( 'ssm_save_appearance', Capabilities::MANAGE_SETTINGS );

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$new_settings = array(
			'primary_color'       => $post['primary_color'] ?? '',
			'primary_hover_color' => $post['primary_hover_color'] ?? '',

			'bg_color'     => $post['bg_color'] ?? '',
			'bg_alt_color' => $post['bg_alt_color'] ?? '',

			'surface_color'       => $post['surface_color'] ?? '',
			'surface_hover_color' => $post['surface_hover_color'] ?? '',
			'card_border_color'   => $post['card_border_color'] ?? '',
			'card_border_width'   => $post['card_border_width'] ?? 0,
			'card_radius_scale'   => $post['card_radius_scale'] ?? '',
			'card_shadow'         => $post['card_shadow'] ?? '',

			'text_color'       => $post['text_color'] ?? '',
			'text_muted_color' => $post['text_muted_color'] ?? '',

			'status_operational_color' => $post['status_operational_color'] ?? '',
			'status_degraded_color'    => $post['status_degraded_color'] ?? '',
			'status_partial_color'     => $post['status_partial_color'] ?? '',
			'status_outage_color'      => $post['status_outage_color'] ?? '',
			'status_maintenance_color' => $post['status_maintenance_color'] ?? '',
			'status_unknown_color'     => $post['status_unknown_color'] ?? '',

			'content_max_width' => $post['content_max_width'] ?? 0,

			'custom_css' => $post['custom_css'] ?? '',
		);

		AppearanceSettings::update( $new_settings );
		AuditLog::record( 'appearance_updated', 'appearance', 0 );

		$this->redirect_with_notice( 'appearance', __( 'Appearance settings saved.', 'service-status-manager' ) );
	}

	public function handle_reset_appearance() {
		$this->guard( 'ssm_reset_appearance', Capabilities::MANAGE_SETTINGS );

		AppearanceSettings::reset();
		AuditLog::record( 'appearance_reset', 'appearance', 0 );

		$this->redirect_with_notice( 'appearance', __( 'Appearance settings reset to defaults.', 'service-status-manager' ) );
	}

	public function handle_export_appearance() {
		$this->guard( 'ssm_export_appearance', Capabilities::MANAGE_SETTINGS );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="service-status-appearance.json"' );
		echo AppearanceSettings::export_json(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function handle_import_appearance() {
		$this->guard( 'ssm_import_appearance', Capabilities::MANAGE_SETTINGS );

		if ( empty( $_FILES['appearance_file']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['appearance_file']['error'] ) {
			$this->redirect_with_notice( 'appearance', __( 'Please choose a file to import.', 'service-status-manager' ), 'error' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
		$contents = file_get_contents( $_FILES['appearance_file']['tmp_name'] );
		$result   = AppearanceSettings::import_json( $contents );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'appearance', $result->get_error_message(), 'error' );
		}

		AuditLog::record( 'appearance_imported', 'appearance', 0 );

		$this->redirect_with_notice( 'appearance', __( 'Appearance settings imported.', 'service-status-manager' ) );
	}
}
