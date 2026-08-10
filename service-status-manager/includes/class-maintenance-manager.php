<?php
/**
 * Repository/service layer for scheduled maintenance, including the cron
 * job that transitions events between scheduled -> in_progress -> completed
 * and fires the configured reminder notifications.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MaintenanceManager {

	const STATUSES = array( 'scheduled', 'in_progress', 'completed', 'cancelled' );
	const IMPACTS   = array( 'none', 'minor', 'major' );

	/**
	 * @return array Public, scheduled or in-progress maintenance, soonest first.
	 */
	public static function get_upcoming() {
		global $wpdb;
		$table = ssm_table( 'maintenance' );
		$sql   = "SELECT * FROM {$table} WHERE status IN ('scheduled','in_progress') AND is_public = 1 ORDER BY scheduled_start ASC";
		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $limit Maximum number of events to return.
	 * @return array Recently completed public maintenance.
	 */
	public static function get_recent_completed( $limit = 5 ) {
		global $wpdb;
		$table = ssm_table( 'maintenance' );
		$sql   = "SELECT * FROM {$table} WHERE status = 'completed' AND is_public = 1 ORDER BY actual_end DESC LIMIT %d";
		return $wpdb->get_results( $wpdb->prepare( $sql, max( 1, absint( $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param array $args per_page, paged, status.
	 * @return array{items: array, total: int}
	 */
	public static function query_for_admin( array $args = array() ) {
		global $wpdb;
		$table = ssm_table( 'maintenance' );

		$args = wp_parse_args( $args, array( 'per_page' => 20, 'paged' => 1, 'status' => '' ) );

		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}
		$where_sql = implode( ' AND ', $where );

		$total = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

		$sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY scheduled_start DESC LIMIT %d OFFSET %d";
		$params = array_merge( $params, array( $per_page, $offset ) );

		return array(
			'items' => $wpdb->get_results( $wpdb->prepare( $sql, $params ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'total' => $total,
		);
	}

	/**
	 * @param int $id Maintenance ID.
	 * @return object|null
	 */
	public static function get_maintenance( $id ) {
		global $wpdb;
		$table = ssm_table( 'maintenance' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $maintenance_id Maintenance ID.
	 * @return array Service rows.
	 */
	public static function get_services_for_maintenance( $maintenance_id ) {
		global $wpdb;
		$sql = 'SELECT s.* FROM ' . ssm_table( 'services' ) . ' s
			INNER JOIN ' . ssm_table( 'maintenance_services' ) . ' m ON m.service_id = s.id
			WHERE m.maintenance_id = %d ORDER BY s.name ASC';
		return $wpdb->get_results( $wpdb->prepare( $sql, absint( $maintenance_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $maintenance_id Maintenance ID.
	 * @return array Monitor rows.
	 */
	public static function get_monitors_for_maintenance( $maintenance_id ) {
		global $wpdb;
		$sql = 'SELECT mo.* FROM ' . ssm_table( 'monitors' ) . ' mo
			INNER JOIN ' . ssm_table( 'maintenance_monitors' ) . ' mm ON mm.monitor_id = mo.id
			WHERE mm.maintenance_id = %d ORDER BY mo.name ASC';
		return $wpdb->get_results( $wpdb->prepare( $sql, absint( $maintenance_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Creates a scheduled maintenance event.
	 *
	 * @param array $data Raw input, including service_ids/monitor_ids and
	 *                     reminder_hours (array of ints, hours-before-start).
	 * @return int|\WP_Error
	 */
	public static function create_maintenance( array $data ) {
		global $wpdb;

		$title = sanitize_text_field( $data['title'] ?? '' );
		if ( '' === $title ) {
			return new \WP_Error( 'ssm_invalid_maintenance', __( 'A maintenance title is required.', 'service-status-manager' ) );
		}
		if ( empty( $data['scheduled_start'] ) || empty( $data['scheduled_end'] ) ) {
			return new \WP_Error( 'ssm_invalid_maintenance', __( 'A start and end time are required.', 'service-status-manager' ) );
		}

		$slug = self::unique_slug( sanitize_title( $title ) );

		$notify_settings = array(
			'on_announce' => ! empty( $data['notify_on_announce'] ),
			'on_start'    => ! empty( $data['notify_on_start'] ),
			'on_complete' => ! empty( $data['notify_on_complete'] ),
			'on_extend'   => ! empty( $data['notify_on_extend'] ),
			'on_cancel'   => ! empty( $data['notify_on_cancel'] ),
			'reminder_hours' => array_values( array_filter( array_map( 'absint', (array) ( $data['reminder_hours'] ?? ssm_get_setting( 'maintenance_reminder_hours', array( 24, 1 ) ) ) ) ) ),
		);

		$wpdb->insert(
			ssm_table( 'maintenance' ),
			array(
				'title'           => $title,
				'slug'            => $slug,
				'description'     => wp_kses_post( $data['description'] ?? '' ),
				'status'          => 'scheduled',
				'scheduled_start' => $data['scheduled_start'],
				'scheduled_end'   => $data['scheduled_end'],
				'timezone'        => sanitize_text_field( $data['timezone'] ?? wp_timezone_string() ),
				'impact'          => in_array( $data['impact'] ?? 'none', self::IMPACTS, true ) ? $data['impact'] : 'none',
				'is_public'       => isset( $data['is_public'] ) && ! $data['is_public'] ? 0 : 1,
				'notify_settings' => wp_json_encode( $notify_settings ),
				'reminders_sent'  => wp_json_encode( array() ),
				'created_by'      => get_current_user_id() ?: null,
				'created_at'      => ssm_now(),
				'updated_at'      => ssm_now(),
			)
		);

		$id = (int) $wpdb->insert_id;

		foreach ( (array) ( $data['service_ids'] ?? array() ) as $service_id ) {
			$wpdb->insert( ssm_table( 'maintenance_services' ), array( 'maintenance_id' => $id, 'service_id' => absint( $service_id ) ) );
		}
		foreach ( (array) ( $data['monitor_ids'] ?? array() ) as $monitor_id ) {
			$wpdb->insert( ssm_table( 'maintenance_monitors' ), array( 'maintenance_id' => $id, 'monitor_id' => absint( $monitor_id ) ) );
		}

		AuditLog::record( 'maintenance_created', 'maintenance', $id, null, $data );

		if ( $notify_settings['on_announce'] ) {
			do_action( 'ssm_maintenance_announced', self::get_maintenance( $id ) );
		}

		return $id;
	}

	/**
	 * Updates a maintenance event's fields. Extending scheduled_end on an
	 * in-progress or scheduled event fires the "extended" notification.
	 *
	 * @param int   $id   Maintenance ID.
	 * @param array $data Raw input.
	 * @return bool|\WP_Error
	 */
	public static function update_maintenance( $id, array $data ) {
		global $wpdb;

		$id       = absint( $id );
		$existing = self::get_maintenance( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'ssm_not_found', __( 'Maintenance event not found.', 'service-status-manager' ) );
		}

		$was_extended = ! empty( $data['scheduled_end'] ) && $data['scheduled_end'] > $existing->scheduled_end && 'completed' !== $existing->status;

		$fields = array(
			'title'           => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : $existing->title,
			'description'     => array_key_exists( 'description', $data ) ? wp_kses_post( $data['description'] ) : $existing->description,
			'scheduled_start' => ! empty( $data['scheduled_start'] ) ? $data['scheduled_start'] : $existing->scheduled_start,
			'scheduled_end'   => ! empty( $data['scheduled_end'] ) ? $data['scheduled_end'] : $existing->scheduled_end,
			'impact'          => isset( $data['impact'] ) && in_array( $data['impact'], self::IMPACTS, true ) ? $data['impact'] : $existing->impact,
			'is_public'       => isset( $data['is_public'] ) ? ( empty( $data['is_public'] ) ? 0 : 1 ) : $existing->is_public,
			'updated_at'      => ssm_now(),
		);

		$wpdb->update( ssm_table( 'maintenance' ), $fields, array( 'id' => $id ) );

		if ( isset( $data['service_ids'] ) ) {
			$wpdb->delete( ssm_table( 'maintenance_services' ), array( 'maintenance_id' => $id ) );
			foreach ( (array) $data['service_ids'] as $service_id ) {
				$wpdb->insert( ssm_table( 'maintenance_services' ), array( 'maintenance_id' => $id, 'service_id' => absint( $service_id ) ) );
			}
		}

		AuditLog::record( 'maintenance_updated', 'maintenance', $id, array( 'title' => $existing->title ), array( 'title' => $fields['title'] ) );

		if ( $was_extended ) {
			do_action( 'ssm_maintenance_extended', self::get_maintenance( $id ) );
		}

		return true;
	}

	/**
	 * Cancels a scheduled (not yet started) maintenance event.
	 *
	 * @param int $id Maintenance ID.
	 * @return bool|\WP_Error
	 */
	public static function cancel_maintenance( $id ) {
		$existing = self::get_maintenance( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'ssm_not_found', __( 'Maintenance event not found.', 'service-status-manager' ) );
		}

		self::set_status( $id, 'cancelled' );
		do_action( 'ssm_maintenance_cancelled', self::get_maintenance( $id ) );

		return true;
	}

	/**
	 * Deletes a maintenance event and its associations.
	 *
	 * @param int $id Maintenance ID.
	 */
	public static function delete_maintenance( $id ) {
		global $wpdb;
		$id = absint( $id );

		$wpdb->delete( ssm_table( 'maintenance_services' ), array( 'maintenance_id' => $id ) );
		$wpdb->delete( ssm_table( 'maintenance_monitors' ), array( 'maintenance_id' => $id ) );
		$wpdb->delete( ssm_table( 'maintenance' ), array( 'id' => $id ) );

		AuditLog::record( 'maintenance_deleted', 'maintenance', $id );
	}

	/**
	 * Updates a maintenance event's status, firing
	 * ssm_maintenance_status_changed when it actually changes.
	 *
	 * @param int    $id     Maintenance ID.
	 * @param string $status New status.
	 */
	private static function set_status( $id, $status ) {
		global $wpdb;

		$existing = self::get_maintenance( $id );
		if ( ! $existing || $existing->status === $status || ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}

		$fields = array(
			'status'     => $status,
			'updated_at' => ssm_now(),
		);
		if ( 'completed' === $status ) {
			$fields['actual_end'] = ssm_now();
		}

		$wpdb->update( ssm_table( 'maintenance' ), $fields, array( 'id' => $id ) );

		do_action( 'ssm_maintenance_status_changed', $id, $existing->status, $status );
	}

	/**
	 * Cron entry point (runs every five minutes): moves scheduled events
	 * into "in_progress" and "completed" as their windows arrive, applies
	 * a "maintenance" service status while active, and sends configured
	 * reminder notifications ahead of the start time.
	 */
	public static function process_transitions() {
		global $wpdb;
		$table = ssm_table( 'maintenance' );
		$now   = ssm_now();

		$starting = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'scheduled' AND scheduled_start <= %s", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $starting as $event ) {
			self::set_status( $event->id, 'in_progress' );

			foreach ( self::get_services_for_maintenance( $event->id ) as $service ) {
				if ( 'automatic' === $service->status_mode && 'none' !== $event->impact ) {
					ServiceManager::set_status( $service->id, 'maintenance' );
				}
			}

			$settings = json_decode( (string) $event->notify_settings, true ) ?: array();
			if ( ! empty( $settings['on_start'] ) ) {
				do_action( 'ssm_maintenance_started', self::get_maintenance( $event->id ) );
			}
		}

		$ending = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'in_progress' AND scheduled_end <= %s", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $ending as $event ) {
			self::set_status( $event->id, 'completed' );

			foreach ( self::get_services_for_maintenance( $event->id ) as $service ) {
				ServiceManager::recalculate_status( $service->id );
				if ( 'manual' === $service->status_mode && 'maintenance' === $service->status ) {
					ServiceManager::set_status( $service->id, 'operational' );
				}
			}

			$settings = json_decode( (string) $event->notify_settings, true ) ?: array();
			if ( ! empty( $settings['on_complete'] ) ) {
				do_action( 'ssm_maintenance_completed', self::get_maintenance( $event->id ) );
			}
		}

		self::send_due_reminders();
	}

	/**
	 * Sends reminder notifications for scheduled maintenance whose
	 * configured lead time has arrived, tracking which reminders have
	 * already fired in the `reminders_sent` column so repeated cron runs
	 * never send the same reminder twice.
	 */
	private static function send_due_reminders() {
		global $wpdb;
		$table = ssm_table( 'maintenance' );

		$events = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'scheduled'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $events as $event ) {
			$settings = json_decode( (string) $event->notify_settings, true ) ?: array();
			$sent     = json_decode( (string) $event->reminders_sent, true ) ?: array();
			$hours    = $settings['reminder_hours'] ?? array();

			$start_ts = strtotime( $event->scheduled_start . ' UTC' );

			foreach ( $hours as $lead_hours ) {
				$reminder_key = 'h' . $lead_hours;
				if ( in_array( $reminder_key, $sent, true ) ) {
					continue;
				}

				$fire_at = $start_ts - ( $lead_hours * HOUR_IN_SECONDS );
				if ( time() >= $fire_at ) {
					do_action( 'ssm_maintenance_reminder', self::get_maintenance( $event->id ), $lead_hours );
					$sent[] = $reminder_key;
					$wpdb->update( $table, array( 'reminders_sent' => wp_json_encode( $sent ) ), array( 'id' => $event->id ) );
				}
			}
		}
	}

	/**
	 * Ensures a maintenance slug is unique.
	 *
	 * @param string $slug Candidate slug.
	 * @return string
	 */
	private static function unique_slug( $slug ) {
		global $wpdb;
		$table = ssm_table( 'maintenance' );

		$base  = $slug;
		$index = 1;

		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			++$index;
			$slug = $base . '-' . $index;
		}

		return $slug;
	}
}
