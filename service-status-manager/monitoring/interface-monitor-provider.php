<?php
/**
 * Contract every monitor type (built-in or third-party) must implement.
 *
 * Third-party monitor types (DNS, SMTP, Microsoft 365, generic API,
 * NinjaOne, PRTG, Auvik, Veeam, WatchGuard, or a custom webhook-based
 * check) register themselves by:
 *
 *  1. Adding their type slug via the `ssm_monitor_provider_types` filter.
 *  2. Returning an instance of this interface from the
 *     `ssm_monitor_provider_instance` filter when asked for their type.
 *  3. Optionally sanitising their own settings via
 *     `ssm_sanitize_monitor_settings`.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Monitoring;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface MonitorProviderInterface {

	/**
	 * Executes a single check for the given monitor.
	 *
	 * @param object $monitor Monitor row, with a decoded `settings` property
	 *                        (see MonitorManager::decorate()).
	 * @return CheckResult
	 */
	public function check( $monitor );
}
