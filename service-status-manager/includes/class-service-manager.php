<?php
/**
 * Repository/service layer for service groups and services.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ServiceManager {

	/**
	 * Returns every service group, ordered for display.
	 *
	 * @return array
	 */
	public static function get_groups() {
		global $wpdb;
		$table = ssm_table( 'service_groups' );
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY display_order ASC, name ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $id Group ID.
	 * @return object|null
	 */
	public static function get_group( $id ) {
		global $wpdb;
		$table = ssm_table( 'service_groups' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Creates a service group.
	 *
	 * @param array $data Raw input.
	 * @return int|\WP_Error New group ID.
	 */
	public static function create_group( array $data ) {
		global $wpdb;

		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( '' === $name ) {
			return new \WP_Error( 'ssm_invalid_group', __( 'A service group name is required.', 'service-status-manager' ) );
		}

		$slug = self::unique_group_slug( sanitize_title( $data['slug'] ?? $name ) );

		$wpdb->insert(
			ssm_table( 'service_groups' ),
			array(
				'status_page_id' => ! empty( $data['status_page_id'] ) ? absint( $data['status_page_id'] ) : null,
				'name'           => $name,
				'slug'           => $slug,
				'description'    => wp_kses_post( $data['description'] ?? '' ),
				'display_order'  => isset( $data['display_order'] ) ? absint( $data['display_order'] ) : 0,
				'created_at'     => ssm_now(),
				'updated_at'     => ssm_now(),
			)
		);

		$id = (int) $wpdb->insert_id;
		AuditLog::record( 'service_group_created', 'service_group', $id, null, $data );

		return $id;
	}

	/**
	 * Updates a service group.
	 *
	 * @param int   $id   Group ID.
	 * @param array $data Raw input.
	 * @return bool|\WP_Error
	 */
	public static function update_group( $id, array $data ) {
		global $wpdb;

		$id       = absint( $id );
		$existing = self::get_group( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'ssm_not_found', __( 'Service group not found.', 'service-status-manager' ) );
		}

		$name = sanitize_text_field( $data['name'] ?? $existing->name );

		$slug = $existing->slug;
		if ( ! empty( $data['slug'] ) && sanitize_title( $data['slug'] ) !== $existing->slug ) {
			$slug = self::unique_group_slug( sanitize_title( $data['slug'] ), $id );
		}

		$wpdb->update(
			ssm_table( 'service_groups' ),
			array(
				'name'          => $name,
				'slug'          => $slug,
				'description'   => wp_kses_post( $data['description'] ?? $existing->description ),
				'display_order' => isset( $data['display_order'] ) ? absint( $data['display_order'] ) : $existing->display_order,
				'updated_at'    => ssm_now(),
			),
			array( 'id' => $id )
		);

		AuditLog::record( 'service_group_updated', 'service_group', $id, $existing, $data );

		return true;
	}

	/**
	 * Deletes a service group. Services within it are not deleted, only
	 * detached (group_id set to NULL), so status history is preserved.
	 *
	 * @param int $id Group ID.
	 */
	public static function delete_group( $id ) {
		global $wpdb;
		$id = absint( $id );

		$wpdb->update( ssm_table( 'services' ), array( 'group_id' => null ), array( 'group_id' => $id ) );
		$wpdb->delete( ssm_table( 'service_groups' ), array( 'id' => $id ) );

		AuditLog::record( 'service_group_deleted', 'service_group', $id );
	}

	/**
	 * Ensures a group slug is unique.
	 *
	 * @param string   $slug       Candidate slug.
	 * @param int|null $exclude_id ID to exclude from the uniqueness check.
	 * @return string
	 */
	private static function unique_group_slug( $slug, $exclude_id = null ) {
		global $wpdb;
		$table = ssm_table( 'service_groups' );

		$base  = $slug;
		$index = 1;

		while ( true ) {
			$sql = "SELECT id FROM {$table} WHERE slug = %s";
			$params = array( $slug );
			if ( $exclude_id ) {
				$sql     .= ' AND id != %d';
				$params[] = $exclude_id;
			}
			$found = $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( ! $found ) {
				return $slug;
			}

			++$index;
			$slug = $base . '-' . $index;
		}
	}

	/**
	 * Returns every service, optionally filtered.
	 *
	 * @param array $args Optional filters: group_id, visibility, show_on_status_page.
	 * @return array
	 */
	public static function get_services( array $args = array() ) {
		global $wpdb;
		$table = ssm_table( 'services' );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['group_id'] ) ) {
			$where[]  = 'group_id = %d';
			$params[] = absint( $args['group_id'] );
		}
		if ( ! empty( $args['visibility'] ) ) {
			$where[]  = 'visibility = %s';
			$params[] = sanitize_key( $args['visibility'] );
		}
		if ( isset( $args['show_on_status_page'] ) ) {
			$where[]  = 'show_on_status_page = %d';
			$params[] = (int) $args['show_on_status_page'];
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY display_order ASC, name ASC';

		if ( $params ) {
			return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $id Service ID.
	 * @return object|null
	 */
	public static function get_service( $id ) {
		global $wpdb;
		$table = ssm_table( 'services' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param string $slug Service slug.
	 * @return object|null
	 */
	public static function get_service_by_slug( $slug ) {
		global $wpdb;
		$table = ssm_table( 'services' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", sanitize_title( $slug ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Creates a service.
	 *
	 * @param array $data Raw input.
	 * @return int|\WP_Error
	 */
	public static function create_service( array $data ) {
		global $wpdb;

		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( '' === $name ) {
			return new \WP_Error( 'ssm_invalid_service', __( 'A service name is required.', 'service-status-manager' ) );
		}

		$status = self::sanitize_status( $data['status'] ?? 'operational' );
		$slug   = self::unique_service_slug( sanitize_title( $data['slug'] ?? $name ) );

		$wpdb->insert(
			ssm_table( 'services' ),
			array(
				'group_id'              => ! empty( $data['group_id'] ) ? absint( $data['group_id'] ) : null,
				'name'                  => $name,
				'slug'                  => $slug,
				'description'           => wp_kses_post( $data['description'] ?? '' ),
				'visibility'            => in_array( $data['visibility'] ?? 'public', array( 'public', 'private' ), true ) ? $data['visibility'] : 'public',
				'display_order'         => isset( $data['display_order'] ) ? absint( $data['display_order'] ) : 0,
				'icon'                  => sanitize_text_field( $data['icon'] ?? '' ),
				'image_url'             => esc_url_raw( $data['image_url'] ?? '' ),
				'status'                => $status,
				'status_mode'           => in_array( $data['status_mode'] ?? 'automatic', array( 'automatic', 'manual' ), true ) ? $data['status_mode'] : 'automatic',
				'allow_subscriptions'   => empty( $data['allow_subscriptions'] ) ? 0 : 1,
				'show_on_status_page'   => empty( $data['show_on_status_page'] ) ? 0 : 1,
				'exclude_from_overall'  => empty( $data['exclude_from_overall'] ) ? 0 : 1,
				'created_at'            => ssm_now(),
				'updated_at'            => ssm_now(),
			)
		);

		$id = (int) $wpdb->insert_id;
		AuditLog::record( 'service_created', 'service', $id, null, $data );

		return $id;
	}

	/**
	 * Updates a service's configuration fields (not its live status - see
	 * set_status()).
	 *
	 * @param int   $id   Service ID.
	 * @param array $data Raw input.
	 * @return bool|\WP_Error
	 */
	public static function update_service( $id, array $data ) {
		global $wpdb;

		$id       = absint( $id );
		$existing = self::get_service( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'ssm_not_found', __( 'Service not found.', 'service-status-manager' ) );
		}

		$slug = $existing->slug;
		if ( ! empty( $data['slug'] ) && sanitize_title( $data['slug'] ) !== $existing->slug ) {
			$slug = self::unique_service_slug( sanitize_title( $data['slug'] ), $id );
		}

		$fields = array(
			'group_id'             => array_key_exists( 'group_id', $data ) ? ( $data['group_id'] ? absint( $data['group_id'] ) : null ) : $existing->group_id,
			'name'                 => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : $existing->name,
			'slug'                 => $slug,
			'description'          => array_key_exists( 'description', $data ) ? wp_kses_post( $data['description'] ) : $existing->description,
			'visibility'           => isset( $data['visibility'] ) && in_array( $data['visibility'], array( 'public', 'private' ), true ) ? $data['visibility'] : $existing->visibility,
			'display_order'        => isset( $data['display_order'] ) ? absint( $data['display_order'] ) : $existing->display_order,
			'icon'                 => array_key_exists( 'icon', $data ) ? sanitize_text_field( $data['icon'] ) : $existing->icon,
			'image_url'            => array_key_exists( 'image_url', $data ) ? esc_url_raw( $data['image_url'] ) : $existing->image_url,
			'status_mode'          => isset( $data['status_mode'] ) && in_array( $data['status_mode'], array( 'automatic', 'manual' ), true ) ? $data['status_mode'] : $existing->status_mode,
			'allow_subscriptions'  => isset( $data['allow_subscriptions'] ) ? ( empty( $data['allow_subscriptions'] ) ? 0 : 1 ) : $existing->allow_subscriptions,
			'show_on_status_page'  => isset( $data['show_on_status_page'] ) ? ( empty( $data['show_on_status_page'] ) ? 0 : 1 ) : $existing->show_on_status_page,
			'exclude_from_overall' => isset( $data['exclude_from_overall'] ) ? ( empty( $data['exclude_from_overall'] ) ? 0 : 1 ) : $existing->exclude_from_overall,
			'updated_at'           => ssm_now(),
		);

		$wpdb->update( ssm_table( 'services' ), $fields, array( 'id' => $id ) );

		if ( isset( $data['status'] ) && 'manual' === $fields['status_mode'] ) {
			self::set_status( $id, self::sanitize_status( $data['status'] ) );
		}

		AuditLog::record( 'service_updated', 'service', $id, $existing, $data );

		return true;
	}

	/**
	 * Deletes a service and its monitors.
	 *
	 * @param int $id Service ID.
	 */
	public static function delete_service( $id ) {
		global $wpdb;
		$id = absint( $id );

		$monitor_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . ssm_table( 'monitors' ) . ' WHERE service_id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $monitor_ids as $monitor_id ) {
			MonitorManager::delete_monitor( $monitor_id );
		}

		$wpdb->delete( ssm_table( 'services' ), array( 'id' => $id ) );
		AuditLog::record( 'service_deleted', 'service', $id );
	}

	/**
	 * Sets a service's live status, firing the ssm_service_status_changed
	 * action when it actually changes so notifications/webhooks can react.
	 *
	 * @param int    $id     Service ID.
	 * @param string $status New status slug.
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;

		$id      = absint( $id );
		$status  = self::sanitize_status( $status );
		$service = self::get_service( $id );

		if ( ! $service || $service->status === $status ) {
			return;
		}

		$old_status = $service->status;

		$wpdb->update(
			ssm_table( 'services' ),
			array(
				'status'     => $status,
				'updated_at' => ssm_now(),
			),
			array( 'id' => $id )
		);

		/**
		 * Fires when a service's status changes.
		 *
		 * @param int    $service_id Service ID.
		 * @param string $old_status Previous status slug.
		 * @param string $new_status New status slug.
		 */
		do_action( 'ssm_service_status_changed', $id, $old_status, $status );

		AuditLog::record( 'service_status_changed', 'service', $id, $old_status, $status );
	}

	/**
	 * Recalculates an automatic-mode service's status from its monitors.
	 *
	 * @param int $service_id Service ID.
	 */
	public static function recalculate_status( $service_id ) {
		$service = self::get_service( $service_id );

		if ( ! $service || 'automatic' !== $service->status_mode ) {
			return;
		}

		$monitors = MonitorManager::get_monitors_for_service( $service_id );
		$states   = wp_list_pluck( array_filter( $monitors, fn( $m ) => $m->is_active ), 'current_state' );

		self::set_status( $service_id, StatusCalculator::calculate_service_status( $states ) );
	}

	/**
	 * Ensures a status value is one of the known statuses.
	 *
	 * @param string $status Candidate status.
	 * @return string
	 */
	public static function sanitize_status( $status ) {
		$statuses = array_keys( ssm_get_status_definitions() );
		$status   = sanitize_key( $status );
		return in_array( $status, $statuses, true ) ? $status : 'unknown';
	}

	/**
	 * Ensures a service slug is unique.
	 *
	 * @param string   $slug       Candidate slug.
	 * @param int|null $exclude_id ID to exclude.
	 * @return string
	 */
	private static function unique_service_slug( $slug, $exclude_id = null ) {
		global $wpdb;
		$table = ssm_table( 'services' );

		$base  = $slug;
		$index = 1;

		while ( true ) {
			$sql    = "SELECT id FROM {$table} WHERE slug = %s";
			$params = array( $slug );
			if ( $exclude_id ) {
				$sql     .= ' AND id != %d';
				$params[] = $exclude_id;
			}
			$found = $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( ! $found ) {
				return $slug;
			}

			++$index;
			$slug = $base . '-' . $index;
		}
	}

	/**
	 * Calculates and returns the overall platform status across every
	 * public, non-excluded service.
	 *
	 * @return string
	 */
	public static function get_overall_status() {
		$services = self::get_services( array( 'show_on_status_page' => 1 ) );
		return StatusCalculator::calculate_overall_status( $services );
	}
}
