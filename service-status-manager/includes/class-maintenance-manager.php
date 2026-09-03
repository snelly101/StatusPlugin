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
	 * Returns only the public (non-internal) updates for a maintenance
	 * window, in chronological order, for use on the front end.
	 *
	 * @param int $maintenance_id Maintenance ID.
	 * @return array
	 */
	public static function get_public_updates( $maintenance_id ) {
		global $wpdb;
		$table = ssm_table( 'maintenance_updates' );
		$sql   = "SELECT * FROM {$table} WHERE maintenance_id = %d AND is_internal = 0 ORDER BY created_at ASC";
		return $wpdb->get_results( $wpdb->prepare( $sql, absint( $maintenance_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns every update (including internal notes) for the admin edit
	 * screen. Internal notes must never leave this code path.
	 *
	 * @param int $maintenance_id Maintenance ID.
	 * @return array
	 */
	public static function get_all_updates( $maintenance_id ) {
		global $wpdb;
		$table = ssm_table( 'maintenance_updates' );
		$sql   = "SELECT * FROM {$table} WHERE maintenance_id = %d ORDER BY created_at ASC";
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

		$reminder_hours  = array_values( array_filter( array_map( 'absint', (array) ( $data['reminder_hours'] ?? ssm_get_setting( 'maintenance_reminder_hours', array( 24, 1 ) ) ) ) ) );
		$notify_settings = array(
			'on_announce' => ! empty( $data['notify_on_announce'] ),
			'on_start'    => ! empty( $data['notify_on_start'] ),
			'on_complete' => ! empty( $data['notify_on_complete'] ),
			'on_extend'   => ! empty( $data['notify_on_extend'] ),
			'on_cancel'   => ! empty( $data['notify_on_cancel'] ),
			'on_update'   => ! empty( $data['notify_on_update'] ),
			'reminder_hours' => $reminder_hours,
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
				// Pre-mark any lead time the window is already too close to
				// honour (e.g. a "1 hour before" reminder on a window
				// starting in 30 minutes) as sent, without actually sending
				// it - otherwise the next cron tick sees its fire time
				// already in the past and fires it immediately, which reads
				// as "reminder: this already started" rather than a useful
				// heads-up.
				'reminders_sent'  => wp_json_encode( self::unreachable_reminder_keys( $data['scheduled_start'], $reminder_hours ) ),
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

		// The edit form always submits every notify_on_* checkbox together
		// (checked or not), so only rebuild notify_settings wholesale when
		// at least one of them is actually present - this was previously
		// never persisted on edit at all (only set at creation), so
		// changing these checkboxes on an existing event had no effect.
		$notify_keys    = array( 'notify_on_announce', 'notify_on_start', 'notify_on_complete', 'notify_on_extend', 'notify_on_cancel', 'notify_on_update' );
		$reminder_hours = json_decode( (string) $existing->notify_settings, true )['reminder_hours'] ?? array();

		if ( array_intersect( $notify_keys, array_keys( $data ) ) ) {
			$reminder_hours = isset( $data['reminder_hours'] ) ? array_values( array_filter( array_map( 'absint', (array) $data['reminder_hours'] ) ) ) : $reminder_hours;

			$fields['notify_settings'] = wp_json_encode(
				array(
					'on_announce'    => ! empty( $data['notify_on_announce'] ),
					'on_start'       => ! empty( $data['notify_on_start'] ),
					'on_complete'    => ! empty( $data['notify_on_complete'] ),
					'on_extend'      => ! empty( $data['notify_on_extend'] ),
					'on_cancel'      => ! empty( $data['notify_on_cancel'] ),
					'on_update'      => ! empty( $data['notify_on_update'] ),
					'reminder_hours' => $reminder_hours,
				)
			);
		}

		// If the schedule moved (or the reminder lead times changed),
		// re-suppress whichever configured reminders the new start time no
		// longer leaves room for - same reasoning as create_maintenance():
		// without this, rescheduling something to start sooner than a
		// reminder's lead time would fire that reminder immediately on the
		// next cron tick instead of not sending it. Only ever adds to
		// reminders_sent, never removes, so an already-sent reminder can't
		// be re-triggered by a later reschedule.
		if ( $fields['scheduled_start'] !== $existing->scheduled_start || isset( $data['reminder_hours'] ) ) {
			$already_sent = json_decode( (string) $existing->reminders_sent, true ) ?: array();
			$newly_unreachable = self::unreachable_reminder_keys( $fields['scheduled_start'], $reminder_hours );
			$fields['reminders_sent'] = wp_json_encode( array_values( array_unique( array_merge( $already_sent, $newly_unreachable ) ) ) );
		}

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
	 * Cancels a scheduled or in-progress maintenance event.
	 *
	 * @param int $id Maintenance ID.
	 * @return int|\WP_Error New update ID.
	 */
	public static function cancel_maintenance( $id ) {
		return self::add_update( $id, 'cancelled', '' );
	}

	/**
	 * Deletes a maintenance event, its timeline, and its associations.
	 *
	 * @param int $id Maintenance ID.
	 */
	public static function delete_maintenance( $id ) {
		global $wpdb;
		$id = absint( $id );

		$wpdb->delete( ssm_table( 'maintenance_updates' ), array( 'maintenance_id' => $id ) );
		$wpdb->delete( ssm_table( 'maintenance_services' ), array( 'maintenance_id' => $id ) );
		$wpdb->delete( ssm_table( 'maintenance_monitors' ), array( 'maintenance_id' => $id ) );
		$wpdb->delete( ssm_table( 'maintenance' ), array( 'id' => $id ) );

		AuditLog::record( 'maintenance_deleted', 'maintenance', $id );
	}

	/**
	 * Adds a new timeline update to a maintenance window - the single path
	 * used both for a manually posted admin update (which may or may not
	 * change the status) and for the cron-driven automatic
	 * scheduled -> in_progress -> completed transitions (see
	 * process_transitions()), so both produce a consistent public timeline
	 * and apply the same service-status side effects.
	 *
	 * @param int    $maintenance_id Maintenance ID.
	 * @param string $status         New status (one of self::STATUSES). If it
	 *                                matches the current status, this is a
	 *                                plain progress note with no transition.
	 * @param string $message        Update message. Falls back to the
	 *                                maintenance's own description when blank,
	 *                                same convention as an incident's first update.
	 * @param bool   $is_internal    Internal-only note - never notified or shown publicly.
	 * @param string $author_name    Optional public author name override.
	 * @return int|\WP_Error New update ID.
	 */
	public static function add_update( $maintenance_id, $status, $message = '', $is_internal = false, $author_name = '' ) {
		global $wpdb;

		$maintenance = self::get_maintenance( $maintenance_id );
		if ( ! $maintenance ) {
			return new \WP_Error( 'ssm_not_found', __( 'Maintenance event not found.', 'service-status-manager' ) );
		}

		$status  = in_array( $status, self::STATUSES, true ) ? $status : $maintenance->status;
		$message = '' !== trim( wp_strip_all_tags( (string) $message ) ) ? $message : (string) $maintenance->description;

		$update_id = self::insert_update( $maintenance_id, $status, $message, $is_internal, $author_name );

		$old_status     = $maintenance->status;
		$status_changed = $old_status !== $status;

		if ( $status_changed ) {
			$fields = array(
				'status'     => $status,
				'updated_at' => ssm_now(),
			);
			if ( 'completed' === $status ) {
				$fields['actual_end'] = ssm_now();
			}
			$wpdb->update( ssm_table( 'maintenance' ), $fields, array( 'id' => $maintenance_id ) );

			do_action( 'ssm_maintenance_status_changed', $maintenance_id, $old_status, $status );
			self::apply_transition_side_effects( $maintenance_id, $status, $maintenance->impact );
		}

		AuditLog::record(
			'maintenance_update_added',
			'maintenance',
			$maintenance_id,
			array( 'status' => $old_status ),
			array( 'status' => $status, 'is_internal' => $is_internal )
		);

		if ( $is_internal ) {
			return $update_id;
		}

		$updated_maintenance = self::get_maintenance( $maintenance_id );
		$settings            = json_decode( (string) $updated_maintenance->notify_settings, true ) ?: array();
		$update              = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ssm_table( 'maintenance_updates' ) . ' WHERE id = %d', $update_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$event_map = array(
			'in_progress' => array( 'ssm_maintenance_started', 'on_start' ),
			'completed'   => array( 'ssm_maintenance_completed', 'on_complete' ),
			'cancelled'   => array( 'ssm_maintenance_cancelled', 'on_cancel' ),
		);

		if ( $status_changed && isset( $event_map[ $status ] ) ) {
			list( $action, $flag ) = $event_map[ $status ];
			if ( ! empty( $settings[ $flag ] ) ) {
				do_action( $action, $updated_maintenance, $update );
			}
		} elseif ( ! $status_changed && ! empty( $settings['on_update'] ) ) {
			/**
			 * Fires when a progress note is added without changing status
			 * (e.g. a comment posted while still "in_progress").
			 *
			 * @param object $maintenance The maintenance row.
			 * @param object $update      The new update row.
			 */
			do_action( 'ssm_maintenance_updated', $updated_maintenance, $update );
		}

		return $update_id;
	}

	/**
	 * Inserts a raw maintenance timeline update row.
	 *
	 * @param int    $maintenance_id Maintenance ID.
	 * @param string $status         Update status.
	 * @param string $message        Message body.
	 * @param bool   $is_internal    Internal-only flag.
	 * @param string $author_name    Optional author name override.
	 * @return int New update ID.
	 */
	private static function insert_update( $maintenance_id, $status, $message, $is_internal = false, $author_name = '' ) {
		global $wpdb;

		if ( '' === $author_name ) {
			$user        = wp_get_current_user();
			$author_name = $user && $user->exists() ? $user->display_name : ssm_get_setting( 'public_team_name' );
		}

		$wpdb->insert(
			ssm_table( 'maintenance_updates' ),
			array(
				'maintenance_id' => absint( $maintenance_id ),
				'status'         => $status,
				'message'        => wp_kses_post( $message ),
				'author_name'    => sanitize_text_field( $author_name ),
				'is_internal'    => $is_internal ? 1 : 0,
				'created_by'     => get_current_user_id() ?: null,
				'created_at'     => ssm_now(),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Applies the same service-status side effects regardless of whether a
	 * transition was triggered manually or by process_transitions(): puts
	 * automatic-mode affected services into "maintenance" while in
	 * progress, and lets them fall back out (recalculated, or reset to
	 * operational for manual-mode services) once finished.
	 *
	 * @param int    $maintenance_id Maintenance ID.
	 * @param string $new_status     The status just transitioned to.
	 * @param string $impact         The maintenance's configured impact level.
	 */
	private static function apply_transition_side_effects( $maintenance_id, $new_status, $impact ) {
		$services = self::get_services_for_maintenance( $maintenance_id );

		if ( 'in_progress' === $new_status ) {
			foreach ( $services as $service ) {
				if ( 'automatic' === $service->status_mode && 'none' !== $impact ) {
					ServiceManager::set_status( $service->id, 'maintenance' );
				}
			}
		} elseif ( in_array( $new_status, array( 'completed', 'cancelled' ), true ) ) {
			foreach ( $services as $service ) {
				ServiceManager::recalculate_status( $service->id );

				// recalculate_status() is a deliberate no-op for manual-mode
				// services, and also for automatic-mode services with no
				// active monitors to compute a status from (see its own
				// docblock) - either way, nothing above just moved the
				// service off "maintenance", so it would otherwise stay
				// stuck showing "under maintenance" forever once the window
				// ends. Re-fetch (rather than trusting the pre-loop $service
				// snapshot) since an automatic-mode service *with* monitors
				// may have just been legitimately recalculated to something
				// else (e.g. still degraded), which must not be overridden.
				$current = ServiceManager::get_service( $service->id );
				if ( $current && 'maintenance' === $current->status ) {
					ServiceManager::set_status( $service->id, 'operational' );
				}
			}
		}
	}

	/**
	 * Cron entry point (runs every five minutes): moves scheduled events
	 * into "in_progress" and "completed" as their windows arrive (each via
	 * add_update(), so the automatic transition also produces a timeline
	 * entry and respects the same on_start/on_complete notify settings a
	 * manual update would), and sends configured reminder notifications
	 * ahead of the start time.
	 */
	public static function process_transitions() {
		global $wpdb;
		$table = ssm_table( 'maintenance' );
		$now   = ssm_now();

		$starting = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'scheduled' AND scheduled_start <= %s", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $starting as $row ) {
			self::add_update( $row->id, 'in_progress', '', false, ssm_get_setting( 'public_team_name' ) );
		}

		$ending = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'in_progress' AND scheduled_end <= %s", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $ending as $row ) {
			self::add_update( $row->id, 'completed', '', false, ssm_get_setting( 'public_team_name' ) );
		}

		self::send_due_reminders();
	}

	/**
	 * Finds which of the given reminder lead times are already unreachable
	 * for a maintenance window starting at $scheduled_start - i.e. the
	 * window starts sooner than that reminder's lead time allows for, so
	 * there's no meaningful "N hours before" moment left to send it at.
	 * Uses the exact same due-check send_due_reminders() uses, since
	 * "unreachable right now" and "due right now" are the same condition -
	 * that's precisely why an unhandled one fires immediately on the next
	 * cron tick instead of being silently skipped.
	 *
	 * @param string $scheduled_start MySQL datetime, UTC.
	 * @param array  $reminder_hours  Configured lead times in hours.
	 * @return string[] Reminder keys (e.g. "h24") to mark as already sent.
	 */
	private static function unreachable_reminder_keys( $scheduled_start, array $reminder_hours ) {
		$start_ts = strtotime( $scheduled_start . ' UTC' );
		$keys     = array();

		foreach ( $reminder_hours as $lead_hours ) {
			$fire_at = $start_ts - ( (int) $lead_hours * HOUR_IN_SECONDS );
			if ( time() >= $fire_at ) {
				$keys[] = 'h' . $lead_hours;
			}
		}

		return $keys;
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

			$due_now = array_diff( self::unreachable_reminder_keys( $event->scheduled_start, $hours ), $sent );

			foreach ( $due_now as $reminder_key ) {
				$lead_hours = (int) substr( $reminder_key, 1 );
				do_action( 'ssm_maintenance_reminder', self::get_maintenance( $event->id ), $lead_hours );
				$sent[] = $reminder_key;
				$wpdb->update( $table, array( 'reminders_sent' => wp_json_encode( $sent ) ), array( 'id' => $event->id ) );
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
