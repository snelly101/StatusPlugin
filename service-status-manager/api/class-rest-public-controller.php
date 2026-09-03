<?php
/**
 * Public, read-only REST endpoints: overall status, services, incidents,
 * maintenance, and uptime summaries. No authentication required, and no
 * subscriber personal data is ever included in a response from this
 * controller. Internal incident notes and private services/monitors are
 * excluded at the query level, not just filtered from output.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Api;

use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\MonitorManager;
use ServiceStatusManager\IncidentManager;
use ServiceStatusManager\MaintenanceManager;
use ServiceStatusManager\UptimeAggregator;
use ServiceStatusManager\StatusPageManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestPublicController {

	/**
	 * Registers this controller's routes.
	 */
	public function register_routes() {
		$ns = RestApi::NAMESPACE_V1;

		register_rest_route( $ns, '/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_status' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/services', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_services' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/incidents', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_incidents' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/incidents/history', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_incident_history' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'count' => array( 'default' => 20, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( $ns, '/maintenance', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_maintenance' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/maintenance/history', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_maintenance_history' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'count' => array( 'default' => 20, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( $ns, '/uptime/(?P<monitor_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_uptime' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'days' => array( 'default' => 90, 'sanitize_callback' => 'absint' ),
			),
		) );
	}

	/**
	 * GET /status
	 *
	 * @return \WP_REST_Response
	 */
	public function get_status( \WP_REST_Request $request ) {
		$overall = ServiceManager::get_overall_status();
		// The overall-status hero uses its own fuller wording ("All Systems
		// Operational") rather than ssm_get_status_definition()'s plain
		// per-service label ("Operational") - using that here previously
		// meant the live-refresh script silently swapped the server-
		// rendered hero title for the shorter per-service one on every
		// background refresh.
		$def = ssm_get_overall_status_definition( $overall );

		return rest_ensure_response(
			array(
				'status'      => $overall,
				'label'       => $def['title'],
				'description' => $def['description'],
				'updated_at'  => ssm_now(),
			)
		);
	}

	/**
	 * GET /services
	 *
	 * @return \WP_REST_Response
	 */
	public function get_services( \WP_REST_Request $request ) {
		$services = ServiceManager::get_services( array( 'visibility' => 'public', 'show_on_status_page' => 1 ) );
		$groups   = wp_list_pluck( ServiceManager::get_groups(), 'name', 'id' );

		$data = array_map(
			function ( $service ) use ( $groups ) {
				$def = ssm_get_status_definition( $service->status );

				$monitors = array_values(
					array_map(
						function ( $monitor ) {
							$mdef = ssm_get_status_definition( $monitor->current_state );
							return array(
								'id'     => (int) $monitor->id,
								'name'   => $monitor->name,
								'type'   => $monitor->type,
								'status' => $monitor->current_state,
								'label'  => $mdef['label'],
							);
						},
						array_filter( MonitorManager::get_monitors_for_service( $service->id ), fn( $m ) => $m->is_public )
					)
				);

				$payload = array(
					'id'          => (int) $service->id,
					'name'        => $service->name,
					'slug'        => $service->slug,
					'description' => $service->description,
					'group'       => $service->group_id ? ( $groups[ $service->group_id ] ?? null ) : null,
					'status'      => $service->status,
					'label'       => $def['label'],
					'monitors'    => $monitors,
				);

				/**
				 * Filters a single service's public REST API representation.
				 *
				 * @param array  $payload Service data about to be returned.
				 * @param object $service Raw service row.
				 */
				return apply_filters( 'ssm_public_service_data', $payload, $service );
			},
			$services
		);

		return rest_ensure_response( $data );
	}

	/**
	 * GET /incidents (active only)
	 *
	 * @return \WP_REST_Response
	 */
	public function get_incidents( \WP_REST_Request $request ) {
		return rest_ensure_response( array_map( array( $this, 'format_incident' ), IncidentManager::get_active_incidents() ) );
	}

	/**
	 * GET /incidents/history
	 *
	 * @return \WP_REST_Response
	 */
	public function get_incident_history( \WP_REST_Request $request ) {
		$count = min( 100, max( 1, (int) $request->get_param( 'count' ) ) );
		return rest_ensure_response( array_map( array( $this, 'format_incident' ), IncidentManager::get_recent_resolved( $count ) ) );
	}

	/**
	 * Formats an incident row for public REST output. Internal notes are
	 * never included.
	 *
	 * @param object $incident Incident row.
	 * @return array
	 */
	private function format_incident( $incident ) {
		return array(
			'id'        => (int) $incident->id,
			'title'     => $incident->title,
			'slug'      => $incident->slug,
			'severity'  => $incident->severity,
			'status'    => $incident->status,
			'starts_at' => $incident->starts_at,
			'ends_at'   => $incident->ends_at,
			'services'  => wp_list_pluck( IncidentManager::get_services_for_incident( $incident->id ), 'name' ),
			'updates'   => array_map(
				function ( $update ) {
					return array(
						'status'      => $update->status,
						'message'     => $update->message,
						'author_name' => $update->author_name,
						'created_at'  => $update->created_at,
					);
				},
				IncidentManager::get_public_updates( $incident->id )
			),
		);
	}

	/**
	 * GET /maintenance (upcoming only)
	 *
	 * @return \WP_REST_Response
	 */
	public function get_maintenance( \WP_REST_Request $request ) {
		return rest_ensure_response( array_map( array( $this, 'format_maintenance' ), MaintenanceManager::get_upcoming() ) );
	}

	/**
	 * GET /maintenance/history
	 *
	 * @return \WP_REST_Response
	 */
	public function get_maintenance_history( \WP_REST_Request $request ) {
		$count = min( 100, max( 1, (int) $request->get_param( 'count' ) ) );
		return rest_ensure_response( array_map( array( $this, 'format_maintenance' ), MaintenanceManager::get_recent_completed( $count ) ) );
	}

	/**
	 * Formats a maintenance row for public REST output.
	 *
	 * @param object $event Maintenance row.
	 * @return array
	 */
	private function format_maintenance( $event ) {
		return array(
			'id'              => (int) $event->id,
			'title'           => $event->title,
			'slug'            => $event->slug,
			'status'          => $event->status,
			'impact'          => $event->impact,
			'scheduled_start' => $event->scheduled_start,
			'scheduled_end'   => $event->scheduled_end,
			'services'        => wp_list_pluck( MaintenanceManager::get_services_for_maintenance( $event->id ), 'name' ),
			'updates'         => array_map(
				function ( $update ) {
					return array(
						'status'      => $update->status,
						'message'     => $update->message,
						'author_name' => $update->author_name,
						'created_at'  => $update->created_at,
					);
				},
				MaintenanceManager::get_public_updates( $event->id )
			),
		);
	}

	/**
	 * GET /uptime/{monitor_id}
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_uptime( \WP_REST_Request $request ) {
		$monitor = MonitorManager::get_monitor( (int) $request->get_param( 'monitor_id' ) );

		if ( ! $monitor || ! $monitor->is_public ) {
			return new \WP_Error( 'ssm_not_found', __( 'Monitor not found.', 'service-status-manager' ), array( 'status' => 404 ) );
		}

		$days = min( 365, max( 1, (int) $request->get_param( 'days' ) ) );

		return rest_ensure_response(
			array(
				'monitor_id'  => (int) $monitor->id,
				'days'        => $days,
				'uptime_pct'  => UptimeAggregator::get_monitor_uptime_percentage( $monitor->id, $days ),
				'history'     => UptimeAggregator::get_daily_history( $monitor->id, $days ),
			)
		);
	}
}
