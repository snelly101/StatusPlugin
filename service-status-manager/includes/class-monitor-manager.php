<?php
/**
 * Repository/service layer for monitors.
 *
 * Monitor-type-specific configuration (URL, port, thresholds, etc.) is
 * stored as JSON in the `config` column. Anything that could be a secret
 * (custom HTTP headers, which may carry API keys or bearer tokens) is
 * stored separately in the encrypted `secrets` column and is never
 * returned to public API consumers or included in exports.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MonitorManager {

	/**
	 * Monitor types this build ships with. Additional types register
	 * themselves via the ssm_monitor_provider_types filter (see
	 * Monitoring\MonitorRunner::get_provider()).
	 *
	 * @return string[]
	 */
	public static function get_supported_types() {
		return apply_filters( 'ssm_monitor_provider_types', array( 'manual', 'http', 'tcp' ) );
	}

	/**
	 * @param int $service_id Service ID.
	 * @return array
	 */
	public static function get_monitors_for_service( $service_id ) {
		global $wpdb;
		$table = ssm_table( 'monitors' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE service_id = %d ORDER BY display_order ASC, name ASC", absint( $service_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'decorate' ), $rows );
	}

	/**
	 * @param int $id Monitor ID.
	 * @return object|null
	 */
	public static function get_monitor( $id ) {
		global $wpdb;
		$table = ssm_table( 'monitors' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $row ? self::decorate( $row ) : null;
	}

	/**
	 * Returns every active monitor whose next check is due.
	 *
	 * @param int $limit Maximum number of monitors to return (batching).
	 * @return array
	 */
	public static function get_due_monitors( $limit = 50 ) {
		global $wpdb;
		$table = ssm_table( 'monitors' );

		$sql  = "SELECT * FROM {$table} WHERE is_active = 1 AND type != 'manual' AND (next_check_at IS NULL OR next_check_at <= %s) ORDER BY next_check_at ASC LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ssm_now(), absint( $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'decorate' ), $rows );
	}

	/**
	 * Decodes the JSON config/secret columns into a merged `settings`
	 * property on the monitor object for convenient access.
	 *
	 * @param object $row Raw database row.
	 * @return object
	 */
	private static function decorate( $row ) {
		$config  = json_decode( (string) $row->config, true ) ?: array();
		$secrets = array();

		if ( ! empty( $row->secrets ) ) {
			$decrypted = Encryption::decrypt( $row->secrets );
			$secrets   = $decrypted ? ( json_decode( $decrypted, true ) ?: array() ) : array();
		}

		$row->settings = array_merge( $config, $secrets );

		return $row;
	}

	/**
	 * Splits a monitor settings array into its public config and secret
	 * portions before storage.
	 *
	 * @param array $settings Raw settings.
	 * @return array{config: array, secrets: array}
	 */
	private static function split_settings( array $settings ) {
		$secret_keys = array( 'headers' );

		$secrets = array();
		$config  = array();

		foreach ( $settings as $key => $value ) {
			if ( in_array( $key, $secret_keys, true ) ) {
				$secrets[ $key ] = $value;
			} else {
				$config[ $key ] = $value;
			}
		}

		return array(
			'config'  => $config,
			'secrets' => $secrets,
		);
	}

	/**
	 * Creates a monitor.
	 *
	 * @param array $data Raw input including a `settings` sub-array.
	 * @return int|\WP_Error
	 */
	public static function create_monitor( array $data ) {
		global $wpdb;

		$name = sanitize_text_field( $data['name'] ?? '' );
		$type = sanitize_key( $data['type'] ?? 'manual' );

		if ( '' === $name ) {
			return new \WP_Error( 'ssm_invalid_monitor', __( 'A monitor name is required.', 'service-status-manager' ) );
		}
		if ( ! in_array( $type, self::get_supported_types(), true ) ) {
			return new \WP_Error( 'ssm_invalid_monitor_type', __( 'Unsupported monitor type.', 'service-status-manager' ) );
		}
		if ( empty( $data['service_id'] ) ) {
			return new \WP_Error( 'ssm_invalid_monitor', __( 'A monitor must belong to a service.', 'service-status-manager' ) );
		}

		$settings_raw = self::sanitize_settings( $type, $data['settings'] ?? array() );
		$split        = self::split_settings( $settings_raw );

		$frequency = max( 60, absint( $data['check_frequency'] ?? 300 ) );

		$wpdb->insert(
			ssm_table( 'monitors' ),
			array(
				'service_id'            => absint( $data['service_id'] ),
				'name'                  => $name,
				'type'                  => $type,
				'config'                => wp_json_encode( $split['config'] ),
				'secrets'               => $split['secrets'] ? Encryption::encrypt( wp_json_encode( $split['secrets'] ) ) : null,
				'check_frequency'       => $frequency,
				'timeout_seconds'       => min( 60, max( 1, absint( $data['timeout_seconds'] ?? 10 ) ) ),
				'failure_threshold'     => max( 1, absint( $data['failure_threshold'] ?? 3 ) ),
				'recovery_threshold'    => max( 1, absint( $data['recovery_threshold'] ?? 2 ) ),
				'auto_incident'         => empty( $data['auto_incident'] ) ? 0 : 1,
				'is_active'             => isset( $data['is_active'] ) && ! $data['is_active'] ? 0 : 1,
				'is_public'             => isset( $data['is_public'] ) && ! $data['is_public'] ? 0 : 1,
				'display_order'         => absint( $data['display_order'] ?? 0 ),
				'current_state'         => 'unknown',
				'warning_response_ms'   => isset( $data['warning_response_ms'] ) && '' !== $data['warning_response_ms'] ? absint( $data['warning_response_ms'] ) : null,
				'critical_response_ms'  => isset( $data['critical_response_ms'] ) && '' !== $data['critical_response_ms'] ? absint( $data['critical_response_ms'] ) : null,
				'next_check_at'         => 'manual' === $type ? null : ssm_now(),
				'created_at'            => ssm_now(),
				'updated_at'            => ssm_now(),
			)
		);

		$id = (int) $wpdb->insert_id;
		AuditLog::record( 'monitor_created', 'monitor', $id, null, array_merge( $data, array( 'settings' => '[redacted]' ) ) );

		return $id;
	}

	/**
	 * Updates a monitor.
	 *
	 * @param int   $id   Monitor ID.
	 * @param array $data Raw input.
	 * @return bool|\WP_Error
	 */
	public static function update_monitor( $id, array $data ) {
		global $wpdb;

		$id       = absint( $id );
		$existing = self::get_monitor( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'ssm_not_found', __( 'Monitor not found.', 'service-status-manager' ) );
		}

		$type = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : $existing->type;
		if ( ! in_array( $type, self::get_supported_types(), true ) ) {
			return new \WP_Error( 'ssm_invalid_monitor_type', __( 'Unsupported monitor type.', 'service-status-manager' ) );
		}

		$merged_settings = isset( $data['settings'] ) ? self::sanitize_settings( $type, $data['settings'] ) : $existing->settings;
		$split           = self::split_settings( $merged_settings );

		$fields = array(
			'service_id'           => isset( $data['service_id'] ) ? absint( $data['service_id'] ) : $existing->service_id,
			'name'                 => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : $existing->name,
			'type'                 => $type,
			'config'               => wp_json_encode( $split['config'] ),
			'secrets'              => $split['secrets'] ? Encryption::encrypt( wp_json_encode( $split['secrets'] ) ) : null,
			'check_frequency'      => isset( $data['check_frequency'] ) ? max( 60, absint( $data['check_frequency'] ) ) : $existing->check_frequency,
			'timeout_seconds'      => isset( $data['timeout_seconds'] ) ? min( 60, max( 1, absint( $data['timeout_seconds'] ) ) ) : $existing->timeout_seconds,
			'failure_threshold'    => isset( $data['failure_threshold'] ) ? max( 1, absint( $data['failure_threshold'] ) ) : $existing->failure_threshold,
			'recovery_threshold'   => isset( $data['recovery_threshold'] ) ? max( 1, absint( $data['recovery_threshold'] ) ) : $existing->recovery_threshold,
			'auto_incident'        => isset( $data['auto_incident'] ) ? ( empty( $data['auto_incident'] ) ? 0 : 1 ) : $existing->auto_incident,
			'is_active'            => isset( $data['is_active'] ) ? ( empty( $data['is_active'] ) ? 0 : 1 ) : $existing->is_active,
			'is_public'            => isset( $data['is_public'] ) ? ( empty( $data['is_public'] ) ? 0 : 1 ) : $existing->is_public,
			'display_order'        => isset( $data['display_order'] ) ? absint( $data['display_order'] ) : $existing->display_order,
			'warning_response_ms'  => array_key_exists( 'warning_response_ms', $data ) ? ( '' !== $data['warning_response_ms'] ? absint( $data['warning_response_ms'] ) : null ) : $existing->warning_response_ms,
			'critical_response_ms' => array_key_exists( 'critical_response_ms', $data ) ? ( '' !== $data['critical_response_ms'] ? absint( $data['critical_response_ms'] ) : null ) : $existing->critical_response_ms,
			'updated_at'           => ssm_now(),
		);

		$wpdb->update( ssm_table( 'monitors' ), $fields, array( 'id' => $id ) );

		AuditLog::record( 'monitor_updated', 'monitor', $id, array( 'type' => $existing->type ), array( 'type' => $type ) );

		return true;
	}

	/**
	 * Deletes a monitor and its check history/aggregates.
	 *
	 * @param int $id Monitor ID.
	 */
	public static function delete_monitor( $id ) {
		global $wpdb;
		$id = absint( $id );

		$wpdb->delete( ssm_table( 'monitor_checks' ), array( 'monitor_id' => $id ) );
		$wpdb->delete( ssm_table( 'monitor_aggregates' ), array( 'monitor_id' => $id ) );
		$wpdb->delete( ssm_table( 'incident_monitors' ), array( 'monitor_id' => $id ) );
		$wpdb->delete( ssm_table( 'monitors' ), array( 'id' => $id ) );

		AuditLog::record( 'monitor_deleted', 'monitor', $id );
	}

	/**
	 * Manually sets a manual-type monitor's state, or forces state for any
	 * monitor type from the admin UI.
	 *
	 * @param int    $id    Monitor ID.
	 * @param string $state New state (matches a status slug).
	 */
	public static function set_manual_state( $id, $state ) {
		global $wpdb;
		$id    = absint( $id );
		$state = ServiceManager::sanitize_status( $state );

		$wpdb->update(
			ssm_table( 'monitors' ),
			array(
				'current_state'   => $state,
				'last_checked_at' => ssm_now(),
				'updated_at'      => ssm_now(),
			),
			array( 'id' => $id )
		);

		$monitor = self::get_monitor( $id );
		if ( $monitor ) {
			ServiceManager::recalculate_status( $monitor->service_id );
		}

		AuditLog::record( 'monitor_state_set', 'monitor', $id, null, $state );
	}

	/**
	 * Validates/sanitizes a monitor's type-specific settings array.
	 *
	 * @param string $type     Monitor type.
	 * @param array  $settings Raw settings.
	 * @return array
	 */
	public static function sanitize_settings( $type, array $settings ) {
		$clean = array();

		if ( 'http' === $type ) {
			$clean['url']              = esc_url_raw( $settings['url'] ?? '' );
			$clean['method']           = in_array( strtoupper( $settings['method'] ?? 'GET' ), array( 'GET', 'HEAD', 'POST' ), true ) ? strtoupper( $settings['method'] ) : 'GET';
			$clean['expected_status']  = isset( $settings['expected_status'] ) && '' !== $settings['expected_status'] ? absint( $settings['expected_status'] ) : 200;
			$clean['required_text']   = sanitize_text_field( $settings['required_text'] ?? '' );
			$clean['forbidden_text']  = sanitize_text_field( $settings['forbidden_text'] ?? '' );
			$clean['follow_redirects'] = empty( $settings['follow_redirects'] ) ? false : true;
			$clean['verify_ssl']      = ! isset( $settings['verify_ssl'] ) || $settings['verify_ssl'] ? true : false;
			$clean['allow_internal']  = empty( $settings['allow_internal'] ) ? false : true;

			$headers = array();
			if ( ! empty( $settings['headers'] ) && is_array( $settings['headers'] ) ) {
				foreach ( $settings['headers'] as $header ) {
					if ( empty( $header['name'] ) ) {
						continue;
					}
					$headers[ sanitize_text_field( $header['name'] ) ] = sanitize_text_field( $header['value'] ?? '' );
				}
			}
			$clean['headers'] = $headers;
		} elseif ( 'tcp' === $type ) {
			$clean['host'] = sanitize_text_field( $settings['host'] ?? '' );
			$clean['port'] = min( 65535, max( 1, absint( $settings['port'] ?? 443 ) ) );
			$clean['allow_internal'] = empty( $settings['allow_internal'] ) ? false : true;
		}

		/**
		 * Filters sanitized monitor settings, allowing third-party monitor
		 * types to validate their own configuration.
		 *
		 * @param array  $clean    Sanitized settings so far.
		 * @param string $type     Monitor type.
		 * @param array  $settings Raw input settings.
		 */
		return apply_filters( 'ssm_sanitize_monitor_settings', $clean, $type, $settings );
	}
}
