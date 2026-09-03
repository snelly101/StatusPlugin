<?php
/**
 * Optional companion to NotificationProviderInterface: a provider that
 * knows its own configured throughput limit, so NotificationQueue can ask
 * the provider directly instead of relying only on the generic
 * `ssm_notification_rate_limit_per_second` filter.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface RateLimitedProviderInterface {

	/**
	 * @return int Maximum requests per second this provider should be sent
	 *             at. 0 or less means unlimited.
	 */
	public function get_rate_limit_per_second();
}
