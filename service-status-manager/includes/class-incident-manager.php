<?php
/**
 * Repository/service layer for incidents and their public update timeline.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IncidentManager {

	const SEVERITIES = array( 'informational', 'minor', 'major', 'critical' );
	const STATUSES    = array( 'investigating', 'identified', 'monitoring', 'resolved' );

	/**
	 * Returns every non-resolved, public incident whose scheduled
	 * publication time (if any) has passed.
	 *
	 * @return array
	 */
	public static function get_active_incidents() {
		global $wpdb;
		$table = ssm_table( 'incidents' );

		$sql = "SELECT * FROM {$table}
			WHERE status != 'resolved' AND is_public = 1
			AND (scheduled_publish_at IS NULL OR scheduled_publish_at <= %s)
			ORDER BY is_pinned DESC, starts_at DESC";

		return $wpdb->get_results( $wpdb->prepare( $sql, ssm_now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Maps an incident severity to the service status it should imply
	 * while the incident is active. Informational incidents deliberately
	 * have no entry - they represent no customer-facing impact, so they
	 * don't move a service off "operational".
	 */
	const SEVERITY_SERVICE_STATUS = array(
		'critical' => 'major_outage',
		'major'    => 'partial_outage',
		'minor'    => 'degraded',
	);

	/**
	 * The service status implied by the most severe active (non-resolved,
	 * public, already-published) incident affecting a given service, for
	 * ServiceManager::recalculate_status() to combine with that service's
	 * own monitor-derived status. A manually-created incident has no
	 * monitor of its own, so without this an incident against a
	 * monitor-less service could never move it off "operational".
	 *
	 * @param int $service_id Service ID.
	 * @return string|null Status slug, or null if no active incident implies one.
	 */
	public static function get_active_incident_status_for_service( $service_id ) {
		global $wpdb;

		$sql = 'SELECT i.severity FROM ' . ssm_table( 'incidents' ) . ' i
			INNER JOIN ' . ssm_table( 'incident_services' ) . ' isvc ON isvc.incident_id = i.id
			WHERE isvc.service_id = %d AND i.status != %s AND i.is_public = 1
			AND (i.scheduled_publish_at IS NULL OR i.scheduled_publish_at <= %s)';

		$severities = $wpdb->get_col( $wpdb->prepare( $sql, absint( $service_id ), 'resolved', ssm_now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$worst_severity = null;
		$worst_rank     = -1;

		foreach ( $severities as $severity ) {
			$rank = array_search( $severity, self::SEVERITIES, true );
			if ( false !== $rank && $rank > $worst_rank ) {
				$worst_rank     = $rank;
				$worst_severity = $severity;
			}
		}

		return self::SEVERITY_SERVICE_STATUS[ $worst_severity ] ?? null;
	}

	/**
	 * Returns recently resolved, public incidents.
	 *
	 * @param int $limit Maximum number to return.
	 * @return array
	 */
	public static function get_recent_resolved( $limit = 5 ) {
		global $wpdb;
		$table = ssm_table( 'incidents' );

		$sql = "SELECT * FROM {$table}
			WHERE status = 'resolved' AND is_public = 1
			AND (scheduled_publish_at IS NULL OR scheduled_publish_at <= %s)
			ORDER BY is_pinned DESC, ends_at DESC LIMIT %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, ssm_now(), max( 1, absint( $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns incidents for the admin list screen, including private and
	 * unpublished ones.
	 *
	 * @param array $args per_page, paged, status filter.
	 * @return array{items: array, total: int}
	 */
	public static function query_for_admin( array $args = array() ) {
		global $wpdb;
		$table = ssm_table( 'incidents' );

		$defaults = array(
			'per_page' => 20,
			'paged'    => 1,
			'status'   => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}
		$where_sql = implode( ' AND ', $where );

		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

		$sql          = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY starts_at DESC LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, array( $per_page, $offset ) );

		return array(
			'items' => $wpdb->get_results( $wpdb->prepare( $sql, $query_params ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'total' => $total,
		);
	}

	/**
	 * @param int $id Incident ID.
	 * @return object|null
	 */
	public static function get_incident( $id ) {
		global $wpdb;
		$table = ssm_table( 'incidents' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Finds an open (non-resolved) incident by its de-duplication key, used
	 * to avoid creating duplicate incidents for the same ongoing monitor
	 * failure across repeated cron runs.
	 *
	 * @param string $dedup_key De-duplication key.
	 * @return object|null
	 */
	public static function get_open_incident_by_dedup_key( $dedup_key ) {
		global $wpdb;
		$table = ssm_table( 'incidents' );
		$sql   = "SELECT * FROM {$table} WHERE dedup_key = %s AND status != 'resolved' ORDER BY id DESC LIMIT 1";
		return $wpdb->get_row( $wpdb->prepare( $sql, $dedup_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns only the public (non-internal) updates for an incident, in
	 * chronological order, for use on the front end.
	 *
	 * @param int $incident_id Incident ID.
	 * @return array
	 */
	public static function get_public_updates( $incident_id ) {
		global $wpdb;
		$table = ssm_table( 'incident_updates' );
		$sql   = "SELECT * FROM {$table} WHERE incident_id = %d AND is_internal = 0 ORDER BY created_at ASC";
		return $wpdb->get_results( $wpdb->prepare( $sql, absint( $incident_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns every update (including internal notes) for the admin edit
	 * screen. Internal notes must never leave this code path.
	 *
	 * @param int $incident_id Incident ID.
	 * @return array
	 */
	public static function get_all_updates( $incident_id ) {
		global $wpdb;
		$table = ssm_table( 'incident_updates' );
		$sql   = "SELECT * FROM {$table} WHERE incident_id = %d ORDER BY created_at ASC";
		return $wpdb->get_results( $wpdb->prepare( $sql, absint( $incident_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $incident_id Incident ID.
	 * @return array List of service rows.
	 */
	public static function get_services_for_incident( $incident_id ) {
		global $wpdb;
		$sql = 'SELECT s.* FROM ' . ssm_table( 'services' ) . ' s
			INNER JOIN ' . ssm_table( 'incident_services' ) . ' i ON i.service_id = s.id
			WHERE i.incident_id = %d ORDER BY s.name ASC';
		return $wpdb->get_results( $wpdb->prepare( $sql, absint( $incident_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $incident_id Incident ID.
	 * @return array List of monitor rows.
	 */
	public static function get_monitors_for_incident( $incident_id ) {
		global $wpdb;
		$sql = 'SELECT m.* FROM ' . ssm_table( 'monitors' ) . ' m
			INNER JOIN ' . ssm_table( 'incident_monitors' ) . ' i ON i.monitor_id = m.id
			WHERE i.incident_id = %d ORDER BY m.name ASC';
		return $wpdb->get_results( $wpdb->prepare( $sql, absint( $incident_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Creates an incident (manually or automatically) together with its
	 * initial update and service/monitor associations.
	 *
	 * @param array $data {
	 *     @type string $title, description, internal_notes, severity, status, root_cause, resolution
	 *     @type string $starts_at, scheduled_publish_at (MySQL datetimes, optional)
	 *     @type bool   $is_public, notify, is_pinned, auto_created
	 *     @type int[]  $service_ids, monitor_ids
	 *     @type string $initial_message Optional first public update message.
	 *     @type string $dedup_key       Optional de-duplication key for automatic incidents.
	 * }
	 * @return int|\WP_Error New incident ID.
	 */
	public static function create_incident( array $data ) {
		global $wpdb;

		$title = sanitize_text_field( $data['title'] ?? '' );
		if ( '' === $title ) {
			return new \WP_Error( 'ssm_invalid_incident', __( 'An incident title is required.', 'service-status-manager' ) );
		}

		$service_ids = array_values( array_filter( array_map( 'absint', (array) ( $data['service_ids'] ?? array() ) ) ) );
		if ( empty( $service_ids ) ) {
			return new \WP_Error( 'ssm_invalid_incident', __( 'At least one affected service is required.', 'service-status-manager' ) );
		}

		$severity = in_array( $data['severity'] ?? 'minor', self::SEVERITIES, true ) ? $data['severity'] : 'minor';
		$status   = in_array( $data['status'] ?? 'investigating', self::STATUSES, true ) ? $data['status'] : 'investigating';
		$slug     = self::unique_slug( sanitize_title( $title ) );

		$wpdb->insert(
			ssm_table( 'incidents' ),
			array(
				'title'                => $title,
				'slug'                 => $slug,
				'description'          => wp_kses_post( $data['description'] ?? '' ),
				'internal_notes'       => wp_kses_post( $data['internal_notes'] ?? '' ),
				'severity'             => $severity,
				'status'               => $status,
				'starts_at'            => ! empty( $data['starts_at'] ) ? $data['starts_at'] : ssm_now(),
				'ends_at'              => 'resolved' === $status ? ssm_now() : null,
				'scheduled_publish_at' => ! empty( $data['scheduled_publish_at'] ) ? $data['scheduled_publish_at'] : null,
				'is_public'            => isset( $data['is_public'] ) && ! $data['is_public'] ? 0 : 1,
				'notify'               => isset( $data['notify'] ) && ! $data['notify'] ? 0 : 1,
				'is_pinned'            => empty( $data['is_pinned'] ) ? 0 : 1,
				'auto_created'         => empty( $data['auto_created'] ) ? 0 : 1,
				'dedup_key'            => ! empty( $data['dedup_key'] ) ? sanitize_text_field( $data['dedup_key'] ) : null,
				'root_cause'           => wp_kses_post( $data['root_cause'] ?? '' ),
				'resolution'           => wp_kses_post( $data['resolution'] ?? '' ),
				'created_by'           => get_current_user_id() ?: null,
				'updated_by'           => get_current_user_id() ?: null,
				'created_at'           => ssm_now(),
				'updated_at'           => ssm_now(),
			)
		);

		$incident_id = (int) $wpdb->insert_id;

		foreach ( $service_ids as $service_id ) {
			$wpdb->insert( ssm_table( 'incident_services' ), array( 'incident_id' => $incident_id, 'service_id' => $service_id ) );
		}
		foreach ( (array) ( $data['monitor_ids'] ?? array() ) as $monitor_id ) {
			$wpdb->insert( ssm_table( 'incident_monitors' ), array( 'incident_id' => $incident_id, 'monitor_id' => absint( $monitor_id ) ) );
		}

		foreach ( self::get_services_for_incident( $incident_id ) as $service ) {
			ServiceManager::recalculate_status( $service->id );
		}

		$message = ! empty( $data['initial_message'] ) ? $data['initial_message'] : $data['description'] ?? '';
		if ( '' !== trim( wp_strip_all_tags( $message ) ) ) {
			self::insert_update( $incident_id, $status, $message, false );
		}

		AuditLog::record( 'incident_created', 'incident', $incident_id, null, array_merge( $data, array( 'internal_notes' => '[redacted]' ) ) );

		$incident = self::get_incident( $incident_id );

		/**
		 * Fires after an incident (manual or automatic) has been created.
		 *
		 * @param object $incident The newly created incident row.
		 */
		do_action( 'ssm_incident_created', $incident );

		return $incident_id;
	}

	/**
	 * Updates an incident's editable fields (not its timeline - see
	 * add_update()).
	 *
	 * @param int   $id   Incident ID.
	 * @param array $data Raw input.
	 * @return bool|\WP_Error
	 */
	public static function update_incident( $id, array $data ) {
		global $wpdb;

		$id       = absint( $id );
		$existing = self::get_incident( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'ssm_not_found', __( 'Incident not found.', 'service-status-manager' ) );
		}

		if ( isset( $data['service_ids'] ) && empty( array_filter( array_map( 'absint', (array) $data['service_ids'] ) ) ) ) {
			return new \WP_Error( 'ssm_invalid_incident', __( 'At least one affected service is required.', 'service-status-manager' ) );
		}

		$fields = array(
			'title'                => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : $existing->title,
			'description'          => array_key_exists( 'description', $data ) ? wp_kses_post( $data['description'] ) : $existing->description,
			'internal_notes'       => array_key_exists( 'internal_notes', $data ) ? wp_kses_post( $data['internal_notes'] ) : $existing->internal_notes,
			'severity'             => isset( $data['severity'] ) && in_array( $data['severity'], self::SEVERITIES, true ) ? $data['severity'] : $existing->severity,
			'is_public'            => isset( $data['is_public'] ) ? ( empty( $data['is_public'] ) ? 0 : 1 ) : $existing->is_public,
			'notify'               => isset( $data['notify'] ) ? ( empty( $data['notify'] ) ? 0 : 1 ) : $existing->notify,
			'is_pinned'            => isset( $data['is_pinned'] ) ? ( empty( $data['is_pinned'] ) ? 0 : 1 ) : $existing->is_pinned,
			'scheduled_publish_at' => array_key_exists( 'scheduled_publish_at', $data ) ? ( $data['scheduled_publish_at'] ?: null ) : $existing->scheduled_publish_at,
			'root_cause'           => array_key_exists( 'root_cause', $data ) ? wp_kses_post( $data['root_cause'] ) : $existing->root_cause,
			'resolution'           => array_key_exists( 'resolution', $data ) ? wp_kses_post( $data['resolution'] ) : $existing->resolution,
			'updated_by'           => get_current_user_id() ?: null,
			'updated_at'           => ssm_now(),
		);

		// Captured before service_ids is applied below, so a service moved
		// *off* this incident still gets recalculated (otherwise it would
		// be left showing whatever status the incident implied forever,
		// since nothing else would ever re-check it).
		$previously_affected = wp_list_pluck( self::get_services_for_incident( $id ), 'id' );

		$wpdb->update( ssm_table( 'incidents' ), $fields, array( 'id' => $id ) );

		if ( isset( $data['service_ids'] ) ) {
			$wpdb->delete( ssm_table( 'incident_services' ), array( 'incident_id' => $id ) );
			foreach ( (array) $data['service_ids'] as $service_id ) {
				$wpdb->insert( ssm_table( 'incident_services' ), array( 'incident_id' => $id, 'service_id' => absint( $service_id ) ) );
			}
		}
		if ( isset( $data['monitor_ids'] ) ) {
			$wpdb->delete( ssm_table( 'incident_monitors' ), array( 'incident_id' => $id ) );
			foreach ( (array) $data['monitor_ids'] as $monitor_id ) {
				$wpdb->insert( ssm_table( 'incident_monitors' ), array( 'incident_id' => $id, 'monitor_id' => absint( $monitor_id ) ) );
			}
		}

		// Re-derive status for every service that was affected either
		// before or after this save (a plain union - recalculating one
		// that didn't actually change is harmless) - covers a service
		// being added or removed from service_ids, and the severity or
		// is_public flag changing for services that stayed affected
		// throughout.
		$currently_affected = wp_list_pluck( self::get_services_for_incident( $id ), 'id' );
		foreach ( array_unique( array_merge( $previously_affected, $currently_affected ) ) as $service_id ) {
			ServiceManager::recalculate_status( $service_id );
		}

		AuditLog::record( 'incident_updated', 'incident', $id, array( 'title' => $existing->title ), array( 'title' => $fields['title'] ) );

		return true;
	}

	/**
	 * Adds a new update to an incident's timeline. When the update's
	 * status is "resolved", the incident is closed, ends_at is set, and
	 * the affected services' automatic-mode status is recalculated.
	 *
	 * @param int    $incident_id Incident ID.
	 * @param string $status      New incident status.
	 * @param string $message     Public (or internal) update message.
	 * @param bool   $is_internal Whether this update is an internal-only note.
	 * @param string $author_name Optional public author name override.
	 * @return int|\WP_Error New update ID.
	 */
	public static function add_update( $incident_id, $status, $message, $is_internal = false, $author_name = '' ) {
		$incident = self::get_incident( $incident_id );
		if ( ! $incident ) {
			return new \WP_Error( 'ssm_not_found', __( 'Incident not found.', 'service-status-manager' ) );
		}

		$status = in_array( $status, self::STATUSES, true ) ? $status : $incident->status;

		$update_id = self::insert_update( $incident_id, $status, $message, $is_internal, $author_name );

		global $wpdb;
		$fields = array(
			'status'     => $status,
			'updated_by' => get_current_user_id() ?: null,
			'updated_at' => ssm_now(),
		);
		if ( 'resolved' === $status && ! $incident->ends_at ) {
			$fields['ends_at'] = ssm_now();
		}
		$wpdb->update( ssm_table( 'incidents' ), $fields, array( 'id' => $incident_id ) );

		// Whether this posting resolves the incident or reopens a
		// previously-resolved one, either transition changes whether it
		// still counts as "active" for get_active_incident_status_for_service()
		// - recalculate every affected service either way.
		if ( $status !== $incident->status ) {
			foreach ( self::get_services_for_incident( $incident_id ) as $service ) {
				ServiceManager::recalculate_status( $service->id );
			}
		}

		AuditLog::record( 'incident_update_added', 'incident', $incident_id, null, array( 'status' => $status, 'is_internal' => $is_internal ) );

		$update = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ssm_table( 'incident_updates' ) . ' WHERE id = %d', $update_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $is_internal ) {
			$updated_incident = self::get_incident( $incident_id );

			if ( 'resolved' === $status ) {
				/**
				 * Fires when an incident is resolved.
				 *
				 * @param object $incident The resolved incident row.
				 */
				do_action( 'ssm_incident_resolved', $updated_incident );
			} else {
				/**
				 * Fires when a new public update is added to an incident.
				 *
				 * @param object $incident The incident row.
				 * @param object $update   The new update row.
				 */
				do_action( 'ssm_incident_updated', $updated_incident, $update );
			}
		}

		return $update_id;
	}

	/**
	 * Inserts a raw update row.
	 *
	 * @param int    $incident_id Incident ID.
	 * @param string $status      Update status.
	 * @param string $message     Message body.
	 * @param bool   $is_internal Internal-only flag.
	 * @param string $author_name Optional author name override.
	 * @return int New update ID.
	 */
	private static function insert_update( $incident_id, $status, $message, $is_internal = false, $author_name = '' ) {
		global $wpdb;

		if ( '' === $author_name ) {
			$user        = wp_get_current_user();
			$author_name = $user && $user->exists() ? $user->display_name : ssm_get_setting( 'public_team_name' );
		}

		$wpdb->insert(
			ssm_table( 'incident_updates' ),
			array(
				'incident_id' => absint( $incident_id ),
				'status'      => $status,
				'message'     => wp_kses_post( $message ),
				'author_name' => sanitize_text_field( $author_name ),
				'is_internal' => $is_internal ? 1 : 0,
				'created_by'  => get_current_user_id() ?: null,
				'created_at'  => ssm_now(),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Deletes an incident and its updates/associations.
	 *
	 * @param int $id Incident ID.
	 */
	public static function delete_incident( $id ) {
		global $wpdb;
		$id = absint( $id );

		// Captured before the incident_services rows are deleted below, so
		// a service this incident was elevating gets recalculated back to
		// normal instead of being left stuck at whatever status it implied.
		$affected_service_ids = wp_list_pluck( self::get_services_for_incident( $id ), 'id' );

		$wpdb->delete( ssm_table( 'incident_updates' ), array( 'incident_id' => $id ) );
		$wpdb->delete( ssm_table( 'incident_services' ), array( 'incident_id' => $id ) );
		$wpdb->delete( ssm_table( 'incident_monitors' ), array( 'incident_id' => $id ) );
		$wpdb->delete( ssm_table( 'incidents' ), array( 'id' => $id ) );

		foreach ( $affected_service_ids as $service_id ) {
			ServiceManager::recalculate_status( $service_id );
		}

		AuditLog::record( 'incident_deleted', 'incident', $id );
	}

	/**
	 * Ensures an incident slug is unique.
	 *
	 * @param string $slug Candidate slug.
	 * @return string
	 */
	private static function unique_slug( $slug ) {
		global $wpdb;
		$table = ssm_table( 'incidents' );

		$base  = $slug;
		$index = 1;

		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			++$index;
			$slug = $base . '-' . $index;
		}

		return $slug;
	}
}
