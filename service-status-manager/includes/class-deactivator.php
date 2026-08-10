<?php
/**
 * Runs when the plugin is deactivated. Never deletes data - only stops
 * scheduled tasks so a deactivated plugin does not keep running checks or
 * sending notifications in the background.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	/**
	 * Unschedules all plugin cron events.
	 */
	public static function deactivate() {
		Cron::unschedule_events();
	}
}
