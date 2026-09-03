<?php
/**
 * Optional companion to NotificationProviderInterface: a provider that can
 * perform a lightweight, low-cost check of its own configuration/
 * connectivity (valid credentials, endpoint reachable) without actually
 * sending a notification - used by the "Provider health" admin/CLI surface.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface HealthCheckableProviderInterface {

	/**
	 * @return array{healthy: bool, message: string} Whether the provider
	 *         appears usable right now, and a short human-readable reason.
	 */
	public function health_check();
}
