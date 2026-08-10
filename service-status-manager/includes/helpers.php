<?php
/**
 * Standalone helper functions used throughout the plugin.
 *
 * Deliberately declared in the global namespace (not ServiceStatusManager)
 * so that both namespaced class files and non-namespaced template files
 * (public/templates/*.php, and any theme overrides of them) can call these
 * functions unqualified. PHP's namespace-to-global function fallback only
 * works one level - from a sub-namespace back to the true global namespace -
 * so these have to live there directly for that fallback to help templates.
 * The ssm_ prefix keeps them out of collisions with WordPress core/other
 * plugins.
 *
 * @package ServiceStatusManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical definition of every service/monitor status.
 *
 * Centralising this means the CSS class, colour, icon and accessible label
 * for a given status only ever have to be defined once.
 *
 * @return array<string,array<string,string>>
 */
function ssm_get_status_definitions() {
	static $statuses = null;

	if ( null !== $statuses ) {
		return $statuses;
	}

	$statuses = array(
		'operational' => array(
			'label'       => __( 'Operational', 'service-status-manager' ),
			'css_class'   => 'ssm-status-operational',
			'color'       => '#16a34a',
			'icon'        => 'dashicons-yes-alt',
			'description' => __( 'This service is operating normally.', 'service-status-manager' ),
			'severity'    => 10,
		),
		'degraded'    => array(
			'label'       => __( 'Degraded Performance', 'service-status-manager' ),
			'css_class'   => 'ssm-status-degraded',
			'color'       => '#eab308',
			'icon'        => 'dashicons-warning',
			'description' => __( 'This service is online but experiencing performance issues.', 'service-status-manager' ),
			'severity'    => 40,
		),
		'partial_outage' => array(
			'label'       => __( 'Partial Outage', 'service-status-manager' ),
			'css_class'   => 'ssm-status-partial-outage',
			'color'       => '#f97316',
			'icon'        => 'dashicons-flag',
			'description' => __( 'Some functionality of this service is unavailable.', 'service-status-manager' ),
			'severity'    => 70,
		),
		'major_outage' => array(
			'label'       => __( 'Major Outage', 'service-status-manager' ),
			'css_class'   => 'ssm-status-major-outage',
			'color'       => '#dc2626',
			'icon'        => 'dashicons-dismiss',
			'description' => __( 'This service is unavailable.', 'service-status-manager' ),
			'severity'    => 90,
		),
		'maintenance' => array(
			'label'       => __( 'Under Maintenance', 'service-status-manager' ),
			'css_class'   => 'ssm-status-maintenance',
			'color'       => '#2563eb',
			'icon'        => 'dashicons-admin-tools',
			'description' => __( 'This service is currently undergoing planned maintenance.', 'service-status-manager' ),
			'severity'    => 30,
		),
		'unknown'     => array(
			'label'       => __( 'Unknown', 'service-status-manager' ),
			'css_class'   => 'ssm-status-unknown',
			'color'       => '#6b7280',
			'icon'        => 'dashicons-editor-help',
			'description' => __( 'The status of this service could not be determined.', 'service-status-manager' ),
			'severity'    => 20,
		),
	);

	/**
	 * Filters the full list of available statuses and their presentation.
	 *
	 * @param array $statuses Status definitions keyed by status slug.
	 */
	return apply_filters( 'ssm_status_definitions', $statuses );
}

/**
 * Returns the default priority order used to roll monitor/service statuses
 * up into the overall platform status. Highest priority (most severe) first.
 *
 * @return string[]
 */
function ssm_get_status_priority_order() {
	$order = array( 'major_outage', 'partial_outage', 'degraded', 'maintenance', 'unknown', 'operational' );

	/**
	 * Filters the status priority order used for overall status roll-up.
	 *
	 * @param string[] $order Ordered list of status slugs, most severe first.
	 */
	return apply_filters( 'ssm_status_priority_order', $order );
}

/**
 * Fetches a single status definition, falling back to "unknown".
 *
 * @param string $status Status slug.
 * @return array<string,string>
 */
function ssm_get_status_definition( $status ) {
	$all = ssm_get_status_definitions();
	return $all[ $status ] ?? $all['unknown'];
}

/**
 * Generates a cryptographically secure URL-safe random token.
 *
 * @param int $bytes Number of random bytes to generate.
 * @return string Hex-encoded token.
 */
function ssm_generate_token( $bytes = 32 ) {
	return bin2hex( random_bytes( $bytes ) );
}

/**
 * Hashes a token for storage. Raw tokens are never stored, only their hash,
 * so a database compromise does not expose usable unsubscribe/verification links.
 *
 * @param string $token Raw token.
 * @return string SHA-256 hash.
 */
function ssm_hash_token( $token ) {
	return hash( 'sha256', $token . wp_salt( 'auth' ) );
}

/**
 * Returns the configured time zone for the plugin (defaults to the site's).
 *
 * @return \DateTimeZone
 */
function ssm_get_timezone() {
	$tz = get_option( 'ssm_timezone' );

	if ( $tz ) {
		try {
			return new \DateTimeZone( $tz );
		} catch ( \Exception $e ) {
			// Fall through to site timezone.
		}
	}

	return wp_timezone();
}

/**
 * Converts a UTC MySQL datetime string into the configured display timezone.
 *
 * @param string $utc_datetime MySQL datetime string in UTC.
 * @param string $format       PHP date format. Defaults to site date + time format.
 * @return string
 */
function ssm_format_datetime( $utc_datetime, $format = '' ) {
	if ( empty( $utc_datetime ) || '0000-00-00 00:00:00' === $utc_datetime ) {
		return '';
	}

	if ( '' === $format ) {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	try {
		$date = new \DateTime( $utc_datetime, new \DateTimeZone( 'UTC' ) );
		$date->setTimezone( ssm_get_timezone() );
		return $date->format( $format );
	} catch ( \Exception $e ) {
		return $utc_datetime;
	}
}

/**
 * Returns the current UTC time as a MySQL datetime string.
 *
 * @return string
 */
function ssm_now() {
	return gmdate( 'Y-m-d H:i:s' );
}

/**
 * Retrieves the merged plugin settings array (single autoload:no option).
 *
 * @return array
 */
function ssm_get_settings() {
	static $defaults = null;

	if ( null === $defaults ) {
		$defaults = array(
			'from_name'                  => get_bloginfo( 'name' ),
			'from_email'                 => get_option( 'admin_email' ),
			'public_team_name'           => get_bloginfo( 'name' ) . ' ' . __( 'Status Team', 'service-status-manager' ),
			'overall_status_excludes'    => array(),
			'raw_check_retention_days'   => 35,
			'hourly_aggregate_retention_days' => 400,
			'daily_aggregate_retention_days'  => 1825,
			'notification_log_retention_days' => 400,
			'audit_log_retention_days'   => 1825,
			'ssrf_allow_private_ips'     => false,
			'ssrf_allowlist'             => array(),
			'cron_secret'                => '',
			'quiet_hours_start'          => '',
			'quiet_hours_end'            => '',
			'quiet_hours_timezone'       => wp_timezone_string(),
			'max_sms_per_subscriber_per_day' => 5,
			'sms_provider'                => 'twilio',
			'sms_sender'                  => '',
			'sms_default_country'         => 'GB',
			'sms_credentials_encrypted'   => '',
			'sms_max_length'              => 160,
			'sms_truncate_mode'           => 'truncate',
			'sms_monthly_limit'           => 0,
			'sms_per_incident_limit'      => 0,
			'min_notify_severity'        => 'informational',
			'suppress_short_incident_recovery_minutes' => 0,
			'maintenance_reminder_hours' => array( 24, 1 ),
			'privacy_notice'             => __( 'By subscribing you agree to receive service status notifications via your selected channels. You can unsubscribe at any time.', 'service-status-manager' ),
			'consent_wording_version'    => '1.0',
			'retain_ip_addresses'        => true,
			'log_level'                  => 'error',
			'log_retention_days'         => 30,
		);
	}

	$stored = get_option( 'ssm_settings', array() );

	return wp_parse_args( $stored, $defaults );
}

/**
 * Retrieves a single plugin setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Fallback value if not present.
 * @return mixed
 */
function ssm_get_setting( $key, $default = null ) {
	$settings = ssm_get_settings();
	return $settings[ $key ] ?? $default;
}

/**
 * Updates the plugin settings array (merged with existing values).
 *
 * @param array $new_settings Key/value pairs to merge in.
 * @return bool
 */
function ssm_update_settings( array $new_settings ) {
	$settings = ssm_get_settings();
	$settings = array_merge( $settings, $new_settings );
	return update_option( 'ssm_settings', $settings, false );
}

/**
 * Returns the plugin's custom database table name, correctly prefixed.
 *
 * @param string $table Short table name, e.g. "services".
 * @return string
 */
function ssm_table( $table ) {
	global $wpdb;
	return $wpdb->prefix . 'ssm_' . $table;
}

/**
 * A tiny wrapper around error_log()/plugin logging that respects the
 * configured log level and masks known-sensitive substrings.
 *
 * @param string $message Log message.
 * @param string $level   One of error, warning, info, debug.
 * @param array  $context Optional structured context, will be JSON encoded.
 */
function ssm_log( $message, $level = 'info', $context = array() ) {
	$levels        = array(
		'error'   => 0,
		'warning' => 1,
		'info'    => 2,
		'debug'   => 3,
	);
	$configured    = ssm_get_setting( 'log_level', 'error' );
	$configured_ix = $levels[ $configured ] ?? 0;
	$message_ix    = $levels[ $level ] ?? 2;

	if ( $message_ix > $configured_ix ) {
		return;
	}

	$message = ssm_mask_secrets( $message );

	global $wpdb;
	$wpdb->insert(
		ssm_table( 'logs' ),
		array(
			'level'      => $level,
			'message'    => $message,
			'context'    => ! empty( $context ) ? wp_json_encode( ssm_mask_secrets_array( $context ) ) : null,
			'created_at' => ssm_now(),
		),
		array( '%s', '%s', '%s', '%s' )
	);
}

/**
 * Masks tokens, API keys, and webhook URLs that must never be persisted in
 * plain text logs.
 *
 * @param string $text Text to mask.
 * @return string
 */
function ssm_mask_secrets( $text ) {
	if ( ! is_string( $text ) ) {
		return $text;
	}

	$text = preg_replace( '/https:\/\/[^\s"\']*webhook[^\s"\']*/i', 'https://[masked-webhook-url]', $text );
	$text = preg_replace( '/(sk|key|token|secret|pwd|password|auth)[a-z0-9_]*["\']?\s*[:=]\s*["\']?[A-Za-z0-9\-_\.]{8,}/i', '$1=[masked]', $text );

	return $text;
}

/**
 * Recursively masks known-sensitive array keys.
 *
 * @param array $data Context data.
 * @return array
 */
function ssm_mask_secrets_array( array $data ) {
	$sensitive_keys = array( 'password', 'secret', 'token', 'api_key', 'apikey', 'auth_token', 'webhook_url', 'credentials', 'authorization' );

	foreach ( $data as $key => $value ) {
		if ( is_array( $value ) ) {
			$data[ $key ] = ssm_mask_secrets_array( $value );
		} elseif ( is_string( $key ) && in_array( strtolower( (string) $key ), $sensitive_keys, true ) ) {
			$data[ $key ] = '[masked]';
		} elseif ( is_string( $value ) ) {
			$data[ $key ] = ssm_mask_secrets( $value );
		}
	}

	return $data;
}
