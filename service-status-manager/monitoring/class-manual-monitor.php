<?php
/**
 * Manual monitor "provider". Manual monitors are never scheduled for
 * automated checks (see MonitorManager::get_due_monitors(), which
 * excludes type = manual) - their state is set directly by an
 * administrator via the admin UI or REST API. This class exists purely so
 * the type still satisfies the common provider interface for any code
 * that iterates all monitor types generically.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Monitoring;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ManualMonitor implements MonitorProviderInterface {

	/**
	 * {@inheritDoc}
	 */
	public function check( $monitor ) {
		return new CheckResult( true, null, null, '', null );
	}
}
