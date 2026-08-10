<?php
/**
 * Records administrative activity for accountability and troubleshooting.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AuditLog {

	/**
	 * Writes an audit log entry. Secrets must be scrubbed by the caller
	 * before old/new values reach this method - see Encryption::mask()
	 * and ssm_mask_secrets_array().
	 *
	 * @param string     $action      Short machine-readable action key, e.g. "incident_created".
	 * @param string     $object_type Object type, e.g. "incident", "monitor", "settings".
	 * @param int|null   $object_id   Related object ID, if any.
	 * @param mixed|null $old_value   Previous value (will be JSON encoded).
	 * @param mixed|null $new_value   New value (will be JSON encoded).
	 */
	public static function record( $action, $object_type = '', $object_id = null, $old_value = null, $new_value = null ) {
		global $wpdb;

		$user_id = get_current_user_id();

		$wpdb->insert(
			ssm_table( 'audit_log' ),
			array(
				'user_id'     => $user_id ? $user_id : null,
				'action'      => sanitize_key( $action ),
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => $object_id ? absint( $object_id ) : null,
				'old_value'   => null !== $old_value ? wp_json_encode( self::scrub( $old_value ) ) : null,
				'new_value'   => null !== $new_value ? wp_json_encode( self::scrub( $new_value ) ) : null,
				'ip_address'  => self::get_ip(),
				'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
				'created_at'  => ssm_now(),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Removes obviously sensitive keys from a value before persisting it.
	 *
	 * @param mixed $value Value to scrub.
	 * @return mixed
	 */
	private static function scrub( $value ) {
		if ( is_array( $value ) ) {
			return ssm_mask_secrets_array( $value );
		}
		if ( is_string( $value ) ) {
			return ssm_mask_secrets( $value );
		}
		return $value;
	}

	/**
	 * Retrieves the requester's IP address, respecting the site's setting
	 * for whether IP addresses should be retained at all.
	 *
	 * @return string|null
	 */
	private static function get_ip() {
		if ( ! ssm_get_setting( 'retain_ip_addresses', true ) ) {
			return null;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return $ip ? substr( $ip, 0, 45 ) : null;
	}

	/**
	 * Fetches a page of audit log entries for the admin Logs screen.
	 *
	 * @param array $args Query args: per_page, paged, object_type, user_id.
	 * @return array{items: array, total: int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'per_page'    => 50,
			'paged'       => 1,
			'object_type' => '',
			'user_id'     => 0,
		);
		$args     = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$params[] = $args['object_type'];
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}

		$where_sql = implode( ' AND ', $where );
		$table     = ssm_table( 'audit_log' );
		$per_page  = max( 1, (int) $args['per_page'] );
		$offset    = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$sql          = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, array( $per_page, $offset ) );
		$items        = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
