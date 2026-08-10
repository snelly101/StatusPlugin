<?php
/**
 * Database schema definition and installer.
 *
 * All plugin data that is high-volume or relational lives in custom tables
 * rather than as WordPress posts/postmeta, per the performance targets in
 * the specification (hundreds of services, thousands of monitors, six-figure
 * subscriber counts). Referential integrity is enforced in the application
 * layer (repositories/managers) rather than with MySQL foreign keys, to
 * avoid engine/storage compatibility issues across hosts.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database {

	/**
	 * Builds the full CREATE TABLE statements for dbDelta().
	 *
	 * @return string[] Table short-name => CREATE TABLE SQL.
	 */
	public static function get_schema() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix . 'ssm_';

		$schema = array();

		$schema['status_pages'] = "CREATE TABLE {$p}status_pages (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			slug VARCHAR(190) NOT NULL,
			logo_url VARCHAR(500) DEFAULT NULL,
			primary_color VARCHAR(20) DEFAULT NULL,
			secondary_color VARCHAR(20) DEFAULT NULL,
			included_groups LONGTEXT DEFAULT NULL,
			included_services LONGTEXT DEFAULT NULL,
			visibility VARCHAR(20) NOT NULL DEFAULT 'public',
			password_hash VARCHAR(255) DEFAULT NULL,
			page_title VARCHAR(190) DEFAULT NULL,
			intro_text LONGTEXT DEFAULT NULL,
			support_url VARCHAR(500) DEFAULT NULL,
			privacy_url VARCHAR(500) DEFAULT NULL,
			terms_url VARCHAR(500) DEFAULT NULL,
			timezone VARCHAR(64) DEFAULT NULL,
			date_format VARCHAR(50) DEFAULT NULL,
			default_history_range SMALLINT UNSIGNED NOT NULL DEFAULT 90,
			custom_css LONGTEXT DEFAULT NULL,
			settings LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		$schema['service_groups'] = "CREATE TABLE {$p}service_groups (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			status_page_id BIGINT UNSIGNED DEFAULT NULL,
			name VARCHAR(190) NOT NULL,
			slug VARCHAR(190) NOT NULL,
			description LONGTEXT DEFAULT NULL,
			display_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status_page_id (status_page_id)
		) {$charset_collate};";

		$schema['services'] = "CREATE TABLE {$p}services (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED DEFAULT NULL,
			name VARCHAR(190) NOT NULL,
			slug VARCHAR(190) NOT NULL,
			description LONGTEXT DEFAULT NULL,
			visibility VARCHAR(20) NOT NULL DEFAULT 'public',
			display_order INT NOT NULL DEFAULT 0,
			icon VARCHAR(190) DEFAULT NULL,
			image_url VARCHAR(500) DEFAULT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'operational',
			status_mode VARCHAR(20) NOT NULL DEFAULT 'automatic',
			allow_subscriptions TINYINT(1) NOT NULL DEFAULT 1,
			show_on_status_page TINYINT(1) NOT NULL DEFAULT 1,
			exclude_from_overall TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY group_id (group_id),
			KEY status (status)
		) {$charset_collate};";

		$schema['monitors'] = "CREATE TABLE {$p}monitors (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			service_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			type VARCHAR(40) NOT NULL DEFAULT 'manual',
			config LONGTEXT DEFAULT NULL,
			secrets LONGTEXT DEFAULT NULL,
			check_frequency INT UNSIGNED NOT NULL DEFAULT 300,
			timeout_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 10,
			failure_threshold SMALLINT UNSIGNED NOT NULL DEFAULT 3,
			recovery_threshold SMALLINT UNSIGNED NOT NULL DEFAULT 2,
			auto_incident TINYINT(1) NOT NULL DEFAULT 1,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			is_public TINYINT(1) NOT NULL DEFAULT 1,
			display_order INT NOT NULL DEFAULT 0,
			current_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
			consecutive_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			consecutive_successes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_checked_at DATETIME DEFAULT NULL,
			next_check_at DATETIME DEFAULT NULL,
			last_response_time_ms INT UNSIGNED DEFAULT NULL,
			last_error VARCHAR(500) DEFAULT NULL,
			warning_response_ms INT UNSIGNED DEFAULT NULL,
			critical_response_ms INT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY service_id (service_id),
			KEY next_check_at (next_check_at),
			KEY is_active (is_active)
		) {$charset_collate};";

		$schema['monitor_checks'] = "CREATE TABLE {$p}monitor_checks (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			monitor_id BIGINT UNSIGNED NOT NULL,
			checked_at DATETIME NOT NULL,
			result VARCHAR(20) NOT NULL,
			response_time_ms INT UNSIGNED DEFAULT NULL,
			http_status SMALLINT UNSIGNED DEFAULT NULL,
			error_message VARCHAR(500) DEFAULT NULL,
			ssl_expiry_days INT DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY monitor_checked (monitor_id, checked_at)
		) {$charset_collate};";

		$schema['monitor_aggregates'] = "CREATE TABLE {$p}monitor_aggregates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			monitor_id BIGINT UNSIGNED NOT NULL,
			period_type VARCHAR(10) NOT NULL,
			period_start DATETIME NOT NULL,
			checks_total INT UNSIGNED NOT NULL DEFAULT 0,
			checks_up INT UNSIGNED NOT NULL DEFAULT 0,
			uptime_pct DECIMAL(7,4) NOT NULL DEFAULT 0,
			avg_response_time_ms INT UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY monitor_period (monitor_id, period_type, period_start)
		) {$charset_collate};";

		$schema['incidents'] = "CREATE TABLE {$p}incidents (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(190) NOT NULL,
			slug VARCHAR(190) NOT NULL,
			description LONGTEXT DEFAULT NULL,
			internal_notes LONGTEXT DEFAULT NULL,
			severity VARCHAR(20) NOT NULL DEFAULT 'minor',
			status VARCHAR(20) NOT NULL DEFAULT 'investigating',
			starts_at DATETIME NOT NULL,
			ends_at DATETIME DEFAULT NULL,
			scheduled_publish_at DATETIME DEFAULT NULL,
			is_public TINYINT(1) NOT NULL DEFAULT 1,
			notify TINYINT(1) NOT NULL DEFAULT 1,
			is_pinned TINYINT(1) NOT NULL DEFAULT 0,
			auto_created TINYINT(1) NOT NULL DEFAULT 0,
			dedup_key VARCHAR(190) DEFAULT NULL,
			root_cause LONGTEXT DEFAULT NULL,
			resolution LONGTEXT DEFAULT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			updated_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status (status),
			KEY dedup_key (dedup_key)
		) {$charset_collate};";

		$schema['incident_updates'] = "CREATE TABLE {$p}incident_updates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			incident_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL,
			message LONGTEXT NOT NULL,
			author_name VARCHAR(190) DEFAULT NULL,
			is_internal TINYINT(1) NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY incident_id (incident_id)
		) {$charset_collate};";

		$schema['incident_services'] = "CREATE TABLE {$p}incident_services (
			incident_id BIGINT UNSIGNED NOT NULL,
			service_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (incident_id, service_id),
			KEY service_id (service_id)
		) {$charset_collate};";

		$schema['incident_monitors'] = "CREATE TABLE {$p}incident_monitors (
			incident_id BIGINT UNSIGNED NOT NULL,
			monitor_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (incident_id, monitor_id),
			KEY monitor_id (monitor_id)
		) {$charset_collate};";

		$schema['maintenance'] = "CREATE TABLE {$p}maintenance (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(190) NOT NULL,
			slug VARCHAR(190) NOT NULL,
			description LONGTEXT DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
			scheduled_start DATETIME NOT NULL,
			scheduled_end DATETIME NOT NULL,
			actual_end DATETIME DEFAULT NULL,
			timezone VARCHAR(64) DEFAULT NULL,
			impact VARCHAR(20) NOT NULL DEFAULT 'none',
			is_public TINYINT(1) NOT NULL DEFAULT 1,
			notify_settings LONGTEXT DEFAULT NULL,
			reminders_sent LONGTEXT DEFAULT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status (status),
			KEY scheduled_start (scheduled_start)
		) {$charset_collate};";

		$schema['maintenance_services'] = "CREATE TABLE {$p}maintenance_services (
			maintenance_id BIGINT UNSIGNED NOT NULL,
			service_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (maintenance_id, service_id),
			KEY service_id (service_id)
		) {$charset_collate};";

		$schema['maintenance_monitors'] = "CREATE TABLE {$p}maintenance_monitors (
			maintenance_id BIGINT UNSIGNED NOT NULL,
			monitor_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (maintenance_id, monitor_id),
			KEY monitor_id (monitor_id)
		) {$charset_collate};";

		$schema['subscribers'] = "CREATE TABLE {$p}subscribers (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid VARCHAR(36) NOT NULL,
			name VARCHAR(190) DEFAULT NULL,
			email VARCHAR(190) DEFAULT NULL,
			email_hash CHAR(64) DEFAULT NULL,
			phone VARCHAR(40) DEFAULT NULL,
			phone_hash CHAR(64) DEFAULT NULL,
			teams_webhook LONGTEXT DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			min_severity VARCHAR(20) NOT NULL DEFAULT 'informational',
			maintenance_notifications TINYINT(1) NOT NULL DEFAULT 1,
			consent_timestamp DATETIME DEFAULT NULL,
			consent_version VARCHAR(20) DEFAULT NULL,
			consent_source VARCHAR(190) DEFAULT NULL,
			ip_address VARCHAR(45) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY email_hash (email_hash),
			KEY phone_hash (phone_hash),
			KEY status (status)
		) {$charset_collate};";

		$schema['subscriber_channels'] = "CREATE TABLE {$p}subscriber_channels (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subscriber_id BIGINT UNSIGNED NOT NULL,
			channel VARCHAR(20) NOT NULL,
			verified TINYINT(1) NOT NULL DEFAULT 0,
			verified_at DATETIME DEFAULT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY subscriber_channel (subscriber_id, channel)
		) {$charset_collate};";

		$schema['subscriber_selections'] = "CREATE TABLE {$p}subscriber_selections (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subscriber_id BIGINT UNSIGNED NOT NULL,
			scope_type VARCHAR(20) NOT NULL,
			scope_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY subscriber_scope (subscriber_id, scope_type, scope_id)
		) {$charset_collate};";

		$schema['verification_tokens'] = "CREATE TABLE {$p}verification_tokens (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subscriber_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(30) NOT NULL,
			token_hash CHAR(64) NOT NULL,
			expires_at DATETIME NOT NULL,
			used_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY subscriber_type (subscriber_id, type)
		) {$charset_collate};";

		$schema['notification_queue'] = "CREATE TABLE {$p}notification_queue (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			dedup_key VARCHAR(190) NOT NULL,
			subscriber_id BIGINT UNSIGNED NOT NULL,
			channel VARCHAR(20) NOT NULL,
			event_type VARCHAR(60) NOT NULL,
			reference_type VARCHAR(30) DEFAULT NULL,
			reference_id BIGINT UNSIGNED DEFAULT NULL,
			payload LONGTEXT DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
			next_attempt_at DATETIME NOT NULL,
			last_error VARCHAR(500) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			sent_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedup_key (dedup_key),
			KEY status_next (status, next_attempt_at),
			KEY subscriber_id (subscriber_id),
			KEY reference (reference_type, reference_id)
		) {$charset_collate};";

		$schema['notification_log'] = "CREATE TABLE {$p}notification_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			queue_id BIGINT UNSIGNED DEFAULT NULL,
			subscriber_id BIGINT UNSIGNED DEFAULT NULL,
			channel VARCHAR(20) NOT NULL,
			event_type VARCHAR(60) DEFAULT NULL,
			success TINYINT(1) NOT NULL DEFAULT 0,
			provider_response LONGTEXT DEFAULT NULL,
			error_message VARCHAR(500) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY queue_id (queue_id),
			KEY subscriber_id (subscriber_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		$schema['audit_log'] = "CREATE TABLE {$p}audit_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			action VARCHAR(60) NOT NULL,
			object_type VARCHAR(40) DEFAULT NULL,
			object_id BIGINT UNSIGNED DEFAULT NULL,
			old_value LONGTEXT DEFAULT NULL,
			new_value LONGTEXT DEFAULT NULL,
			ip_address VARCHAR(45) DEFAULT NULL,
			user_agent VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY object (object_type, object_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		$schema['webhooks_outgoing'] = "CREATE TABLE {$p}webhooks_outgoing (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			url VARCHAR(500) NOT NULL,
			secret LONGTEXT DEFAULT NULL,
			events LONGTEXT DEFAULT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			timeout_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 10,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		$schema['webhooks_incoming'] = "CREATE TABLE {$p}webhooks_incoming (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			secret LONGTEXT DEFAULT NULL,
			allowed_ips LONGTEXT DEFAULT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		$schema['webhook_delivery_log'] = "CREATE TABLE {$p}webhook_delivery_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			webhook_id BIGINT UNSIGNED DEFAULT NULL,
			direction VARCHAR(10) NOT NULL,
			event_type VARCHAR(60) DEFAULT NULL,
			request_payload LONGTEXT DEFAULT NULL,
			response_code SMALLINT DEFAULT NULL,
			response_body LONGTEXT DEFAULT NULL,
			success TINYINT(1) NOT NULL DEFAULT 0,
			idempotency_key VARCHAR(190) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY webhook_id (webhook_id),
			KEY idempotency_key (idempotency_key)
		) {$charset_collate};";

		$schema['logs'] = "CREATE TABLE {$p}logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(10) NOT NULL,
			message LONGTEXT NOT NULL,
			context LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY created_at (created_at)
		) {$charset_collate};";

		/**
		 * Filters the full database schema before installation, allowing
		 * add-ons to register their own tables using the same versioned
		 * install/migration mechanism.
		 *
		 * @param array $schema Table short-name => CREATE TABLE SQL.
		 */
		return apply_filters( 'ssm_database_schema', $schema );
	}

	/**
	 * Creates or updates every plugin table using dbDelta (idempotent).
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::get_schema() as $sql ) {
			dbDelta( $sql );
		}

		update_option( 'ssm_db_version', SSM_DB_VERSION, false );
	}

	/**
	 * Drops every plugin table. Only ever called from uninstall.php, and
	 * only when the administrator has explicitly opted into data deletion.
	 */
	public static function drop_all_tables() {
		global $wpdb;

		$p = $wpdb->prefix . 'ssm_';

		foreach ( array_keys( self::get_schema() ) as $short_name ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$p}{$short_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
