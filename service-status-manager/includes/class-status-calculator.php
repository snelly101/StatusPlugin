<?php
/**
 * Rolls monitor statuses up into service statuses, and service statuses up
 * into a single overall platform status.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StatusCalculator {

	/**
	 * Determines a service's status from the statuses of its monitors, for
	 * services in "automatic" status mode. Services in "manual" mode keep
	 * whatever status an administrator has set directly.
	 *
	 * @param string[] $monitor_states List of monitor current_state values.
	 * @return string
	 */
	public static function calculate_service_status( array $monitor_states ) {
		if ( empty( $monitor_states ) ) {
			return 'unknown';
		}

		return self::highest_priority( $monitor_states );
	}

	/**
	 * Rolls up a list of service statuses into a single overall status,
	 * honouring the configured priority order and any services excluded
	 * from the overall calculation.
	 *
	 * @param array $services List of objects/arrays with "status" and
	 *                        "exclude_from_overall" keys.
	 * @return string
	 */
	public static function calculate_overall_status( array $services ) {
		$statuses = array();

		foreach ( $services as $service ) {
			$service = (array) $service;

			if ( ! empty( $service['exclude_from_overall'] ) ) {
				continue;
			}

			$statuses[] = $service['status'] ?? 'unknown';
		}

		if ( empty( $statuses ) ) {
			$overall = 'operational';
		} else {
			$overall = self::highest_priority( $statuses );
		}

		/**
		 * Filters the calculated overall status.
		 *
		 * @param string $overall  Calculated overall status slug.
		 * @param array  $services The services considered in the calculation.
		 */
		return apply_filters( 'ssm_overall_status', $overall, $services );
	}

	/**
	 * Returns the most severe status from a list, according to the
	 * configured priority order. Public so callers combining status
	 * signals from more than one source (e.g. ServiceManager combining a
	 * service's monitors with any active incidents affecting it) can reuse
	 * the same priority order rather than re-deriving it.
	 *
	 * @param string[] $statuses List of status slugs.
	 * @return string
	 */
	public static function highest_priority( array $statuses ) {
		$order = ssm_get_status_priority_order();

		$best      = null;
		$best_rank = PHP_INT_MAX;

		foreach ( $statuses as $status ) {
			$rank = array_search( $status, $order, true );
			if ( false === $rank ) {
				continue;
			}
			if ( $rank < $best_rank ) {
				$best_rank = $rank;
				$best      = $status;
			}
		}

		return $best ?? 'unknown';
	}
}
