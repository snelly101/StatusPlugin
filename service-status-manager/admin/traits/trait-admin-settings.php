<?php
/**
 * Admin\Admin trait: settings, status page, webhook, report and log form
 * handlers.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\Capabilities;
use ServiceStatusManager\StatusPageManager;
use ServiceStatusManager\Encryption;
use ServiceStatusManager\AuditLog;
use ServiceStatusManager\Api\WebhookDispatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait AdminSettingsTrait {

	public function handle_save_settings() {
		$this->guard( 'ssm_save_settings', Capabilities::MANAGE_SETTINGS );

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$new_settings = array(
			'from_name'                       => sanitize_text_field( $post['from_name'] ?? '' ),
			'from_email'                      => sanitize_email( $post['from_email'] ?? '' ),
			'public_team_name'                => sanitize_text_field( $post['public_team_name'] ?? '' ),
			'privacy_notice'                  => wp_kses_post( $post['privacy_notice'] ?? '' ),
			'consent_wording_version'         => sanitize_text_field( $post['consent_wording_version'] ?? '1.0' ),
			'retain_ip_addresses'             => ! empty( $post['retain_ip_addresses'] ),
			'min_notify_severity'             => sanitize_key( $post['min_notify_severity'] ?? 'informational' ),
			'suppress_short_incident_recovery_minutes' => absint( $post['suppress_short_incident_recovery_minutes'] ?? 0 ),
			'quiet_hours_start'               => sanitize_text_field( $post['quiet_hours_start'] ?? '' ),
			'quiet_hours_end'                 => sanitize_text_field( $post['quiet_hours_end'] ?? '' ),
			'quiet_hours_timezone'            => sanitize_text_field( $post['quiet_hours_timezone'] ?? wp_timezone_string() ),
			'max_sms_per_subscriber_per_day'  => absint( $post['max_sms_per_subscriber_per_day'] ?? 5 ),
			'sms_provider'                    => sanitize_key( $post['sms_provider'] ?? 'twilio' ),
			'sms_sender'                      => sanitize_text_field( $post['sms_sender'] ?? '' ),
			'sms_default_country'             => sanitize_text_field( $post['sms_default_country'] ?? 'GB' ),
			'sms_max_length'                  => absint( $post['sms_max_length'] ?? 160 ),
			'sms_truncate_mode'               => in_array( $post['sms_truncate_mode'] ?? '', array( 'truncate', 'split' ), true ) ? $post['sms_truncate_mode'] : 'truncate',
			'sms_monthly_limit'               => absint( $post['sms_monthly_limit'] ?? 0 ),
			'sms_per_incident_limit'          => absint( $post['sms_per_incident_limit'] ?? 0 ),
			'raw_check_retention_days'        => absint( $post['raw_check_retention_days'] ?? 35 ),
			'hourly_aggregate_retention_days' => absint( $post['hourly_aggregate_retention_days'] ?? 400 ),
			'daily_aggregate_retention_days'  => absint( $post['daily_aggregate_retention_days'] ?? 1825 ),
			'notification_log_retention_days' => absint( $post['notification_log_retention_days'] ?? 400 ),
			'audit_log_retention_days'        => absint( $post['audit_log_retention_days'] ?? 1825 ),
			'log_level'                       => in_array( $post['log_level'] ?? '', array( 'error', 'warning', 'info', 'debug' ), true ) ? $post['log_level'] : 'error',
			'log_retention_days'              => absint( $post['log_retention_days'] ?? 30 ),
			'ssrf_allowlist'                  => array_filter( array_map( 'trim', explode( "\n", (string) ( $post['ssrf_allowlist'] ?? '' ) ) ) ),
			'smtp_enabled'                    => ! empty( $post['smtp_enabled'] ),
			'smtp_host'                       => sanitize_text_field( $post['smtp_host'] ?? '' ),
			'smtp_port'                       => absint( $post['smtp_port'] ?? 587 ),
			'smtp_encryption'                 => in_array( $post['smtp_encryption'] ?? '', array( 'none', 'ssl', 'tls' ), true ) ? $post['smtp_encryption'] : 'tls',
			'smtp_auth'                       => ! empty( $post['smtp_auth'] ),
			'smtp_username'                   => sanitize_text_field( $post['smtp_username'] ?? '' ),
			'smtp_force_from'                 => ! empty( $post['smtp_force_from'] ),
		);

		if ( ! empty( $post['sms_account_sid'] ) || ! empty( $post['sms_auth_token'] ) ) {
			$existing = json_decode( (string) Encryption::decrypt( ssm_get_setting( 'sms_credentials_encrypted', '' ) ), true ) ?: array();
			$credentials = array(
				'account_sid' => ! empty( $post['sms_account_sid'] ) ? sanitize_text_field( $post['sms_account_sid'] ) : ( $existing['account_sid'] ?? '' ),
				'auth_token'  => ! empty( $post['sms_auth_token'] ) ? sanitize_text_field( $post['sms_auth_token'] ) : ( $existing['auth_token'] ?? '' ),
			);
			$new_settings['sms_credentials_encrypted'] = Encryption::encrypt( wp_json_encode( $credentials ) );
		}

		if ( ! empty( $post['smtp_password'] ) ) {
			$new_settings['smtp_password_encrypted'] = Encryption::encrypt( sanitize_text_field( $post['smtp_password'] ) );
		}

		ssm_update_settings( $new_settings );
		AuditLog::record(
			'settings_updated',
			'settings',
			null,
			null,
			array_diff_key( $new_settings, array_flip( array( 'sms_credentials_encrypted', 'smtp_password_encrypted' ) ) )
		);

		$this->redirect_with_notice( 'settings', __( 'Settings saved.', 'service-status-manager' ) );
	}

	public function handle_save_status_page() {
		$this->guard( 'ssm_save_status_page', Capabilities::MANAGE );

		$id   = absint( $_POST['id'] ?? 0 );
		$data = array(
			'name'                  => wp_unslash( $_POST['name'] ?? '' ),
			'slug'                  => wp_unslash( $_POST['slug'] ?? '' ),
			'logo_url'              => wp_unslash( $_POST['logo_url'] ?? '' ),
			'primary_color'         => wp_unslash( $_POST['primary_color'] ?? '' ),
			'secondary_color'       => wp_unslash( $_POST['secondary_color'] ?? '' ),
			'visibility'            => wp_unslash( $_POST['visibility'] ?? 'public' ),
			'page_title'            => wp_unslash( $_POST['page_title'] ?? '' ),
			'intro_text'            => wp_unslash( $_POST['intro_text'] ?? '' ),
			'support_url'           => wp_unslash( $_POST['support_url'] ?? '' ),
			'privacy_url'           => wp_unslash( $_POST['privacy_url'] ?? '' ),
			'terms_url'             => wp_unslash( $_POST['terms_url'] ?? '' ),
			'timezone'              => wp_unslash( $_POST['timezone'] ?? wp_timezone_string() ),
			'date_format'           => wp_unslash( $_POST['date_format'] ?? get_option( 'date_format' ) ),
			'default_history_range' => wp_unslash( $_POST['default_history_range'] ?? 90 ),
			'custom_css'            => wp_unslash( $_POST['custom_css'] ?? '' ),
			'included_groups'       => array_map( 'absint', (array) ( $_POST['included_groups'] ?? array() ) ),
			'included_services'     => array_map( 'absint', (array) ( $_POST['included_services'] ?? array() ) ),
			'settings'              => array(
				'show_header'           => ! empty( $_POST['show_header'] ),
				'live_refresh_interval' => absint( wp_unslash( $_POST['live_refresh_interval'] ?? 0 ) ),
				'theme_default'         => in_array( $_POST['theme_default'] ?? 'system', array( 'system', 'light', 'dark' ), true ) ? $_POST['theme_default'] : 'system',
			),
		);

		if ( ! empty( $_POST['password'] ) ) {
			$data['password'] = wp_unslash( $_POST['password'] );
		}

		$result = $id ? StatusPageManager::update_page( $id, $data ) : StatusPageManager::create_page( $data );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'status-pages', $result->get_error_message(), 'error' );
		}

		$this->redirect_with_notice( 'status-pages', __( 'Status page saved.', 'service-status-manager' ) );
	}

	public function handle_save_webhook_out() {
		$this->guard( 'ssm_save_webhook_out', Capabilities::MANAGE_INTEGRATIONS );

		global $wpdb;
		$id     = absint( $_POST['id'] ?? 0 );
		$name   = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$url    = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		$events = array_map( 'sanitize_key', (array) ( $_POST['events'] ?? array() ) );

		if ( '' === $name || ! $url ) {
			$this->redirect_with_notice( 'settings', __( 'A name and URL are required.', 'service-status-manager' ), 'error' );
		}

		$fields = array(
			'name'            => $name,
			'url'             => $url,
			'events'          => wp_json_encode( $events ),
			'is_active'       => empty( $_POST['is_active'] ) ? 0 : 1,
			'timeout_seconds' => min( 30, max( 1, absint( wp_unslash( $_POST['timeout_seconds'] ?? 10 ) ) ) ),
			'updated_at'      => ssm_now(),
		);

		if ( ! empty( $_POST['secret'] ) ) {
			$fields['secret'] = Encryption::encrypt( sanitize_text_field( wp_unslash( $_POST['secret'] ) ) );
		}

		if ( $id ) {
			$wpdb->update( ssm_table( 'webhooks_outgoing' ), $fields, array( 'id' => $id ) );
		} else {
			if ( empty( $fields['secret'] ) ) {
				$fields['secret'] = Encryption::encrypt( ssm_generate_token( 24 ) );
			}
			$fields['created_at'] = ssm_now();
			$wpdb->insert( ssm_table( 'webhooks_outgoing' ), $fields );
			$id = (int) $wpdb->insert_id;
		}

		if ( isset( $_POST['send_test'] ) ) {
			WebhookDispatcher::send_test( $id );
		}

		AuditLog::record( 'webhook_outgoing_saved', 'webhook', $id );
		$this->redirect_with_notice( 'settings', __( 'Webhook saved.', 'service-status-manager' ) );
	}

	public function handle_delete_webhook_out() {
		$this->guard( 'ssm_delete_webhook_out', Capabilities::MANAGE_INTEGRATIONS );
		global $wpdb;
		$id = absint( $_POST['id'] ?? 0 );
		$wpdb->delete( ssm_table( 'webhooks_outgoing' ), array( 'id' => $id ) );
		AuditLog::record( 'webhook_outgoing_deleted', 'webhook', $id );
		$this->redirect_with_notice( 'settings', __( 'Webhook deleted.', 'service-status-manager' ) );
	}

	public function handle_save_webhook_in() {
		$this->guard( 'ssm_save_webhook_in', Capabilities::MANAGE_INTEGRATIONS );

		global $wpdb;
		$id   = absint( $_POST['id'] ?? 0 );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		if ( '' === $name ) {
			$this->redirect_with_notice( 'settings', __( 'A name is required.', 'service-status-manager' ), 'error' );
		}

		$fields = array(
			'name'        => $name,
			'allowed_ips' => sanitize_textarea_field( wp_unslash( $_POST['allowed_ips'] ?? '' ) ),
			'is_active'   => empty( $_POST['is_active'] ) ? 0 : 1,
			'updated_at'  => ssm_now(),
		);

		if ( $id ) {
			$wpdb->update( ssm_table( 'webhooks_incoming' ), $fields, array( 'id' => $id ) );
			$secret_notice = '';
		} else {
			$secret               = ssm_generate_token( 24 );
			$fields['secret']     = Encryption::encrypt( $secret );
			$fields['created_at'] = ssm_now();
			$wpdb->insert( ssm_table( 'webhooks_incoming' ), $fields );
			$id            = (int) $wpdb->insert_id;
			$secret_notice = ' ' . sprintf(
				/* translators: %s: webhook secret, shown only once */
				__( 'Secret (copy this now, it will not be shown again): %s', 'service-status-manager' ),
				$secret
			);
		}

		AuditLog::record( 'webhook_incoming_saved', 'webhook', $id );
		$this->redirect_with_notice( 'settings', __( 'Incoming webhook saved.', 'service-status-manager' ) . $secret_notice );
	}

	public function handle_delete_webhook_in() {
		$this->guard( 'ssm_delete_webhook_in', Capabilities::MANAGE_INTEGRATIONS );
		global $wpdb;
		$id = absint( $_POST['id'] ?? 0 );
		$wpdb->delete( ssm_table( 'webhooks_incoming' ), array( 'id' => $id ) );
		AuditLog::record( 'webhook_incoming_deleted', 'webhook', $id );
		$this->redirect_with_notice( 'settings', __( 'Incoming webhook deleted.', 'service-status-manager' ) );
	}

	public function handle_export_report() {
		$this->guard( 'ssm_export_report', Capabilities::VIEW );

		$report = sanitize_key( wp_unslash( $_POST['report'] ?? '' ) );
		$rows   = \ServiceStatusManager\Reports::get_report_rows( $report );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ssm-' . $report . '-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! empty( $rows ) ) {
			fputcsv( $out, array_keys( (array) $rows[0] ) );
			foreach ( $rows as $row ) {
				fputcsv( $out, (array) $row );
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		AuditLog::record( 'report_exported', 'report', null, null, array( 'report' => $report ) );
		exit;
	}

	public function handle_download_logs() {
		$this->guard( 'ssm_download_logs', Capabilities::MANAGE );

		global $wpdb;
		$table = ssm_table( 'logs' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 5000" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ssm-logs-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'created_at', 'level', 'message', 'context' ) );
		foreach ( $rows as $row ) {
			fputcsv( $out, array( $row->created_at, $row->level, $row->message, $row->context ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		AuditLog::record( 'logs_downloaded', 'logs' );
		exit;
	}

	public function handle_uninstall_setting() {
		$this->guard( 'ssm_uninstall_setting', Capabilities::MANAGE_SETTINGS );

		$delete = ! empty( $_POST['delete_on_uninstall'] );
		update_option( 'ssm_uninstall_delete_data', $delete, false );

		AuditLog::record( 'uninstall_setting_changed', 'settings', null, null, array( 'delete_on_uninstall' => $delete ) );
		$this->redirect_with_notice( 'tools', __( 'Uninstall preference saved.', 'service-status-manager' ) );
	}
}
