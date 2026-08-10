<?php
/**
 * Repository/service layer for subscribers: the public subscription
 * workflow (double opt-in, channel verification), self-service
 * management (pause/unsubscribe/update selections), and the UK
 * GDPR-oriented data export/erasure tools.
 *
 * A guiding rule throughout this class: never reveal whether a given
 * email address or phone number is already registered. Every public-
 * facing entry point returns the same generic outcome regardless of
 * whether a matching subscriber existed, to prevent subscriber
 * enumeration (see handle_public_subscription() and
 * resend_confirmation()).
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

use ServiceStatusManager\Notifications\NotificationQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SubscriberManager {

	const TOKEN_TTL_CONFIRM = 2 * DAY_IN_SECONDS;
	const TOKEN_TTL_MANAGE  = 30 * DAY_IN_SECONDS;

	/**
	 * Handles a public subscription form submission. Always returns a
	 * generic success/error result that gives no signal about whether the
	 * supplied contact details were already registered.
	 *
	 * @param array $post Raw, unslashed $_POST data.
	 * @return true|\WP_Error
	 */
	public static function handle_public_subscription( array $post ) {
		// Honeypot: a real visitor never fills in this hidden field.
		if ( ! empty( $post['website'] ) ) {
			return true;
		}

		if ( empty( $post['consent'] ) ) {
			return new \WP_Error( 'ssm_consent_required', __( 'Please confirm your consent to subscribe.', 'service-status-manager' ) );
		}

		$channels = array_intersect( (array) ( $post['channels'] ?? array() ), array( 'email', 'sms', 'teams' ) );
		$email    = sanitize_email( $post['email'] ?? '' );
		$phone    = self::sanitize_phone( $post['phone'] ?? '' );
		$teams    = esc_url_raw( $post['teams_webhook'] ?? '' );

		if ( in_array( 'email', $channels, true ) && ! is_email( $email ) ) {
			return new \WP_Error( 'ssm_invalid_email', __( 'Please enter a valid email address.', 'service-status-manager' ) );
		}
		if ( in_array( 'sms', $channels, true ) && '' === $phone ) {
			return new \WP_Error( 'ssm_invalid_phone', __( 'Please enter a valid mobile number.', 'service-status-manager' ) );
		}
		if ( in_array( 'teams', $channels, true ) && ! self::is_valid_teams_webhook( $teams ) ) {
			return new \WP_Error( 'ssm_invalid_teams', __( 'Please enter a valid Microsoft Teams webhook URL.', 'service-status-manager' ) );
		}
		if ( empty( $channels ) ) {
			return new \WP_Error( 'ssm_no_channel', __( 'Please select at least one notification channel.', 'service-status-manager' ) );
		}

		$subscriber_id = self::find_or_create_subscriber(
			array(
				'name'  => sanitize_text_field( $post['name'] ?? '' ),
				'email' => in_array( 'email', $channels, true ) ? $email : '',
				'phone' => in_array( 'sms', $channels, true ) ? $phone : '',
				'teams' => in_array( 'teams', $channels, true ) ? $teams : '',
			),
			array(
				'consent_timestamp' => ssm_now(),
				'consent_version'   => sanitize_text_field( $post['consent_version'] ?? '' ),
				'consent_source'    => esc_url_raw( $post['consent_source'] ?? '' ),
				'ip_address'        => ssm_get_setting( 'retain_ip_addresses', true ) ? ( $_SERVER['REMOTE_ADDR'] ?? '' ) : '',
			)
		);

		self::set_preferences(
			$subscriber_id,
			array(
				'min_severity'              => sanitize_key( $post['min_severity'] ?? 'informational' ),
				'maintenance_notifications' => ! empty( $post['maintenance_notifications'] ),
			)
		);

		self::replace_selections(
			$subscriber_id,
			array(
				'group'   => array_map( 'absint', (array) ( $post['groups'] ?? array() ) ),
				'service' => array_map( 'absint', (array) ( $post['services'] ?? array() ) ),
				'monitor' => array_map( 'absint', (array) ( $post['monitors'] ?? array() ) ),
			)
		);

		foreach ( $channels as $channel ) {
			self::ensure_channel_row( $subscriber_id, $channel );
			self::send_verification( $subscriber_id, $channel );
		}

		AuditLog::record( 'subscriber_public_signup', 'subscriber', $subscriber_id, null, array( 'channels' => $channels ) );

		return true;
	}

	/**
	 * Finds an existing subscriber by email/phone hash, or creates a new
	 * one, without ever revealing to the caller which happened.
	 *
	 * @param array $contact {name, email, phone, teams}.
	 * @param array $consent {consent_timestamp, consent_version, consent_source, ip_address}.
	 * @return int Subscriber ID.
	 */
	private static function find_or_create_subscriber( array $contact, array $consent ) {
		global $wpdb;
		$table = ssm_table( 'subscribers' );

		$email_hash = $contact['email'] ? hash( 'sha256', strtolower( $contact['email'] ) ) : null;
		$phone_hash = $contact['phone'] ? hash( 'sha256', $contact['phone'] ) : null;

		$existing_id = null;
		if ( $email_hash ) {
			$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email_hash = %s", $email_hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( ! $existing_id && $phone_hash ) {
			$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE phone_hash = %s", $phone_hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$fields = array(
			'name'              => $contact['name'],
			'consent_timestamp' => $consent['consent_timestamp'],
			'consent_version'   => $consent['consent_version'],
			'consent_source'    => $consent['consent_source'],
			'ip_address'        => $consent['ip_address'] ? substr( sanitize_text_field( $consent['ip_address'] ), 0, 45 ) : null,
			'updated_at'        => ssm_now(),
		);
		if ( $contact['email'] ) {
			$fields['email']      = $contact['email'];
			$fields['email_hash'] = $email_hash;
		}
		if ( $contact['phone'] ) {
			$fields['phone']      = $contact['phone'];
			$fields['phone_hash'] = $phone_hash;
		}
		if ( $contact['teams'] ) {
			$fields['teams_webhook'] = Encryption::encrypt( $contact['teams'] );
		}

		if ( $existing_id ) {
			$wpdb->update( $table, $fields, array( 'id' => $existing_id ) );
			return (int) $existing_id;
		}

		$fields['uuid']       = wp_generate_uuid4();
		$fields['status']     = 'pending';
		$fields['created_at'] = ssm_now();

		$wpdb->insert( $table, $fields );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Ensures a subscriber_channels row exists for a given channel.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $channel       "email", "sms", or "teams".
	 */
	private static function ensure_channel_row( $subscriber_id, $channel ) {
		global $wpdb;
		$table = ssm_table( 'subscriber_channels' );

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE subscriber_id = %d AND channel = %s", $subscriber_id, $channel ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $exists ) {
			$wpdb->update( $table, array( 'is_active' => 1, 'updated_at' => ssm_now() ), array( 'id' => $exists ) );
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'subscriber_id' => $subscriber_id,
				'channel'       => $channel,
				'verified'      => 0,
				'is_active'     => 1,
				'created_at'    => ssm_now(),
				'updated_at'    => ssm_now(),
			)
		);
	}

	/**
	 * Generates a verification token for a channel and dispatches the
	 * confirmation message via the notification queue.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $channel       Channel being verified.
	 */
	private static function send_verification( $subscriber_id, $channel ) {
		$token_type = 'teams' === $channel ? 'teams_verify' : 'confirm_' . $channel;
		$token      = self::generate_token( $subscriber_id, $token_type, self::TOKEN_TTL_CONFIRM );

		/**
		 * Fires when a subscriber verification message needs to be sent.
		 * The email/SMS/Teams notification providers listen for this to
		 * enqueue the actual confirmation message.
		 *
		 * @param int    $subscriber_id Subscriber ID.
		 * @param string $channel       Channel being verified.
		 * @param string $token         Raw (unhashed) verification token.
		 */
		do_action( 'ssm_subscriber_verification_needed', $subscriber_id, $channel, $token );
	}

	/**
	 * Resends a confirmation link for a (possibly non-existent) email
	 * address. Always succeeds silently from the caller's perspective.
	 *
	 * @param string $email Email address.
	 */
	public static function resend_confirmation( $email ) {
		if ( ! is_email( $email ) ) {
			return;
		}

		global $wpdb;
		$table = ssm_table( 'subscribers' );
		$hash  = hash( 'sha256', strtolower( $email ) );
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $id ) {
			return;
		}

		$channel_table = ssm_table( 'subscriber_channels' );
		$verified      = $wpdb->get_var( $wpdb->prepare( "SELECT verified FROM {$channel_table} WHERE subscriber_id = %d AND channel = 'email'", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( '1' === $verified || 1 === (int) $verified ) {
			// Already verified - send a management link instead of a confirmation link.
			$token = self::generate_token( $id, 'manage', self::TOKEN_TTL_MANAGE );
			do_action( 'ssm_subscriber_management_link_requested', (int) $id, $token );
			return;
		}

		self::send_verification( (int) $id, 'email' );
	}

	/**
	 * Handles a confirm/unsubscribe/manage/teams_verify link.
	 *
	 * @param string $action        One of confirm, unsubscribe, manage, teams_verify.
	 * @param int    $subscriber_id Subscriber ID from the link.
	 * @param string $token         Raw token from the link.
	 * @return array|\WP_Error
	 */
	public static function handle_token_action( $action, $subscriber_id, $token ) {
		$subscriber = self::get_subscriber( $subscriber_id );
		if ( ! $subscriber ) {
			return new \WP_Error( 'ssm_invalid_link', __( 'This link is invalid or has expired.', 'service-status-manager' ) );
		}

		switch ( $action ) {
			case 'confirm':
				return self::confirm_any_channel( $subscriber_id, $token );
			case 'unsubscribe':
				if ( ! self::consume_token( $subscriber_id, 'unsubscribe', $token, false ) && ! self::verify_any_confirm_or_manage_token( $subscriber_id, $token ) ) {
					return new \WP_Error( 'ssm_invalid_link', __( 'This link is invalid or has expired.', 'service-status-manager' ) );
				}
				self::unsubscribe_completely( $subscriber_id );
				return array( 'subscriber' => self::get_subscriber( $subscriber_id ) );
			case 'manage':
				if ( ! self::verify_token( $subscriber_id, 'manage', $token ) ) {
					return new \WP_Error( 'ssm_invalid_link', __( 'This link is invalid or has expired. Please request a new one.', 'service-status-manager' ) );
				}
				return array(
					'subscriber' => self::get_subscriber( $subscriber_id ),
					'selections' => self::get_selections( $subscriber_id ),
					'token'      => $token,
				);
			case 'teams_verify':
				if ( ! self::consume_token( $subscriber_id, 'teams_verify', $token ) ) {
					return new \WP_Error( 'ssm_invalid_link', __( 'This link is invalid or has expired.', 'service-status-manager' ) );
				}
				self::mark_channel_verified( $subscriber_id, 'teams' );
				return array( 'subscriber' => self::get_subscriber( $subscriber_id ) );
		}

		return new \WP_Error( 'ssm_invalid_action', __( 'Unknown action.', 'service-status-manager' ) );
	}

	/**
	 * Confirms whichever email/sms confirmation token matches.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $token         Raw token.
	 * @return array|\WP_Error
	 */
	private static function confirm_any_channel( $subscriber_id, $token ) {
		foreach ( array( 'email', 'sms' ) as $channel ) {
			if ( self::consume_token( $subscriber_id, 'confirm_' . $channel, $token ) ) {
				self::mark_channel_verified( $subscriber_id, $channel );
				return array( 'subscriber' => self::get_subscriber( $subscriber_id ) );
			}
		}

		return new \WP_Error( 'ssm_invalid_link', __( 'This link is invalid or has expired.', 'service-status-manager' ) );
	}

	/**
	 * Marks a channel verified and activates the subscriber overall if
	 * this is their first verified channel.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $channel       Channel.
	 */
	private static function mark_channel_verified( $subscriber_id, $channel ) {
		global $wpdb;

		$wpdb->update(
			ssm_table( 'subscriber_channels' ),
			array( 'verified' => 1, 'verified_at' => ssm_now(), 'updated_at' => ssm_now() ),
			array( 'subscriber_id' => $subscriber_id, 'channel' => $channel )
		);

		$subscriber = self::get_subscriber( $subscriber_id );
		if ( $subscriber && 'pending' === $subscriber->status ) {
			$wpdb->update( ssm_table( 'subscribers' ), array( 'status' => 'active', 'updated_at' => ssm_now() ), array( 'id' => $subscriber_id ) );
			do_action( 'ssm_subscriber_confirmed', $subscriber_id );
		}
	}

	/**
	 * Applies changes submitted from the self-service "manage
	 * subscription" form: name, channel/service selections, severity,
	 * pause state, or one of unsubscribe/export/erase.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $token         Management token from the form.
	 * @param array  $post          Raw, unslashed $_POST data.
	 */
	public static function update_subscription_from_management_form( $subscriber_id, $token, array $post ) {
		if ( ! self::verify_token( $subscriber_id, 'manage', $token ) ) {
			return;
		}

		$submit_action = sanitize_key( $post['submit_action'] ?? 'save' );

		if ( 'unsubscribe' === $submit_action ) {
			self::unsubscribe_completely( $subscriber_id );
			return;
		}
		if ( 'export' === $submit_action ) {
			$export = self::export_subscriber_data( $subscriber_id );
			do_action( 'ssm_subscriber_export_requested', $subscriber_id, $export );
			return;
		}
		if ( 'erase' === $submit_action ) {
			self::erase_subscriber_data( $subscriber_id );
			return;
		}

		global $wpdb;
		$wpdb->update(
			ssm_table( 'subscribers' ),
			array(
				'name'       => sanitize_text_field( $post['name'] ?? '' ),
				'status'     => ! empty( $post['paused'] ) ? 'paused' : 'active',
				'updated_at' => ssm_now(),
			),
			array( 'id' => $subscriber_id )
		);

		self::set_preferences(
			$subscriber_id,
			array(
				'min_severity'              => sanitize_key( $post['min_severity'] ?? 'informational' ),
				'maintenance_notifications' => ! empty( $post['maintenance_notifications'] ),
			)
		);

		self::replace_selections(
			$subscriber_id,
			array(
				'group'   => array_map( 'absint', (array) ( $post['groups'] ?? array() ) ),
				'service' => array_map( 'absint', (array) ( $post['services'] ?? array() ) ),
				'monitor' => array_map( 'absint', (array) ( $post['monitors'] ?? array() ) ),
			)
		);

		AuditLog::record( 'subscriber_self_updated', 'subscriber', $subscriber_id );
	}

	/**
	 * Fully unsubscribes a subscriber: deactivates every channel and sets
	 * status to unsubscribed. Historical notification logs are kept for
	 * audit purposes but no further notifications will ever be queued.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 */
	public static function unsubscribe_completely( $subscriber_id ) {
		global $wpdb;

		$wpdb->update( ssm_table( 'subscribers' ), array( 'status' => 'unsubscribed', 'updated_at' => ssm_now() ), array( 'id' => $subscriber_id ) );
		$wpdb->update( ssm_table( 'subscriber_channels' ), array( 'is_active' => 0, 'updated_at' => ssm_now() ), array( 'subscriber_id' => $subscriber_id ) );

		do_action( 'ssm_subscriber_unsubscribed', $subscriber_id );
		AuditLog::record( 'subscriber_unsubscribed', 'subscriber', $subscriber_id );
	}

	/**
	 * Replaces a subscriber's scope selections (groups/services/monitors).
	 *
	 * @param int   $subscriber_id Subscriber ID.
	 * @param array $selections    {group: int[], service: int[], monitor: int[]}.
	 */
	private static function replace_selections( $subscriber_id, array $selections ) {
		global $wpdb;
		$table = ssm_table( 'subscriber_selections' );

		$wpdb->delete( $table, array( 'subscriber_id' => $subscriber_id ) );

		foreach ( $selections as $scope_type => $ids ) {
			foreach ( array_unique( array_filter( $ids ) ) as $id ) {
				$wpdb->insert(
					$table,
					array(
						'subscriber_id' => $subscriber_id,
						'scope_type'    => $scope_type,
						'scope_id'      => $id,
						'created_at'    => ssm_now(),
					)
				);
			}
		}
	}

	/**
	 * @param int $subscriber_id Subscriber ID.
	 * @return array Selection rows.
	 */
	public static function get_selections( $subscriber_id ) {
		global $wpdb;
		$table = ssm_table( 'subscriber_selections' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE subscriber_id = %d", absint( $subscriber_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Updates a subscriber's notification preferences.
	 *
	 * @param int   $subscriber_id Subscriber ID.
	 * @param array $prefs         {min_severity, maintenance_notifications}.
	 */
	private static function set_preferences( $subscriber_id, array $prefs ) {
		global $wpdb;

		$severities = array( 'informational', 'minor', 'major', 'critical' );

		$wpdb->update(
			ssm_table( 'subscribers' ),
			array(
				'min_severity'              => in_array( $prefs['min_severity'] ?? '', $severities, true ) ? $prefs['min_severity'] : 'informational',
				'maintenance_notifications' => empty( $prefs['maintenance_notifications'] ) ? 0 : 1,
				'updated_at'                => ssm_now(),
			),
			array( 'id' => $subscriber_id )
		);
	}

	/**
	 * Generates and stores (hashed) a verification/management token.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $type          Token type.
	 * @param int    $ttl           Time-to-live in seconds.
	 * @return string Raw token (only returned once - never stored in plain text).
	 */
	public static function generate_token( $subscriber_id, $type, $ttl ) {
		global $wpdb;

		$token = ssm_generate_token( 32 );

		$wpdb->insert(
			ssm_table( 'verification_tokens' ),
			array(
				'subscriber_id' => absint( $subscriber_id ),
				'type'          => sanitize_key( $type ),
				'token_hash'    => ssm_hash_token( $token ),
				'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
				'created_at'    => ssm_now(),
			)
		);

		return $token;
	}

	/**
	 * Verifies a token without consuming it (safe to call repeatedly,
	 * used for the reusable "manage subscription" link).
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $type          Token type.
	 * @param string $token         Raw token.
	 * @return bool
	 */
	public static function verify_token( $subscriber_id, $type, $token ) {
		global $wpdb;
		$table = ssm_table( 'verification_tokens' );

		$hash = ssm_hash_token( $token );
		$sql  = "SELECT id FROM {$table} WHERE subscriber_id = %d AND type = %s AND token_hash = %s AND expires_at > %s";
		$id   = $wpdb->get_var( $wpdb->prepare( $sql, absint( $subscriber_id ), $type, $hash, ssm_now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return (bool) $id;
	}

	/**
	 * Verifies and marks a single-use token as consumed.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $type          Token type.
	 * @param string $token         Raw token.
	 * @param bool   $require_unused Whether the token must not already be used.
	 * @return bool
	 */
	private static function consume_token( $subscriber_id, $type, $token, $require_unused = true ) {
		global $wpdb;
		$table = ssm_table( 'verification_tokens' );

		$hash = ssm_hash_token( $token );
		$sql  = "SELECT id FROM {$table} WHERE subscriber_id = %d AND type = %s AND token_hash = %s AND expires_at > %s";
		if ( $require_unused ) {
			$sql .= ' AND used_at IS NULL';
		}
		$id = $wpdb->get_var( $wpdb->prepare( $sql, absint( $subscriber_id ), $type, $hash, ssm_now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $id ) {
			return false;
		}

		$wpdb->update( $table, array( 'used_at' => ssm_now() ), array( 'id' => $id ) );
		return true;
	}

	/**
	 * Fallback used by the unsubscribe link so a still-valid confirm or
	 * manage token also works as an unsubscribe credential (a subscriber
	 * should always be able to unsubscribe with any link they were sent).
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $token         Raw token.
	 * @return bool
	 */
	private static function verify_any_confirm_or_manage_token( $subscriber_id, $token ) {
		foreach ( array( 'confirm_email', 'confirm_sms', 'manage', 'teams_verify' ) as $type ) {
			if ( self::verify_token( $subscriber_id, $type, $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param int $id Subscriber ID.
	 * @return object|null
	 */
	public static function get_subscriber( $id ) {
		global $wpdb;
		$table = ssm_table( 'subscribers' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $subscriber_id Subscriber ID.
	 * @return array Channel rows.
	 */
	public static function get_channels( $subscriber_id ) {
		global $wpdb;
		$table = ssm_table( 'subscriber_channels' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE subscriber_id = %d", absint( $subscriber_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Paginated subscriber list for the admin screen.
	 *
	 * @param array $args per_page, paged, search, status.
	 * @return array{items: array, total: int}
	 */
	public static function query_for_admin( array $args = array() ) {
		global $wpdb;
		$table = ssm_table( 'subscribers' );

		$args = wp_parse_args( $args, array( 'per_page' => 20, 'paged' => 1, 'search' => '', 'status' => '' ) );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(name LIKE %s OR email LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

		$sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params = array_merge( $params, array( $per_page, $offset ) );

		return array(
			'items' => $wpdb->get_results( $wpdb->prepare( $sql, $params ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'total' => $total,
		);
	}

	/**
	 * Administrator-initiated subscriber deletion (full erasure).
	 *
	 * @param int $id Subscriber ID.
	 */
	public static function admin_delete_subscriber( $id ) {
		self::erase_subscriber_data( $id );
		AuditLog::record( 'subscriber_deleted_by_admin', 'subscriber', $id );
	}

	/**
	 * Administrator-initiated subscriber update (status/preferences only).
	 *
	 * @param int   $id   Subscriber ID.
	 * @param array $data Raw input.
	 * @return bool|\WP_Error
	 */
	public static function admin_update_subscriber( $id, array $data ) {
		global $wpdb;
		$existing = self::get_subscriber( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'ssm_not_found', __( 'Subscriber not found.', 'service-status-manager' ) );
		}

		$statuses = array( 'pending', 'active', 'paused', 'unsubscribed' );

		$wpdb->update(
			ssm_table( 'subscribers' ),
			array(
				'name'       => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : $existing->name,
				'status'     => isset( $data['status'] ) && in_array( $data['status'], $statuses, true ) ? $data['status'] : $existing->status,
				'updated_at' => ssm_now(),
			),
			array( 'id' => $id )
		);

		AuditLog::record( 'subscriber_updated_by_admin', 'subscriber', $id );

		return true;
	}

	/**
	 * Builds a UK GDPR-style data export for a subscriber: their profile,
	 * channels, selections, and consent record. Used by both the
	 * self-service "export my data" action and the WordPress Personal
	 * Data Exporter (see class-privacy.php).
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return array
	 */
	public static function export_subscriber_data( $subscriber_id ) {
		$subscriber = self::get_subscriber( $subscriber_id );
		if ( ! $subscriber ) {
			return array();
		}

		return array(
			'profile'    => array(
				'name'                      => $subscriber->name,
				'email'                     => $subscriber->email,
				'phone'                     => $subscriber->phone,
				'teams_webhook_configured'  => ! empty( $subscriber->teams_webhook ),
				'status'                    => $subscriber->status,
				'min_severity'              => $subscriber->min_severity,
				'maintenance_notifications' => (bool) $subscriber->maintenance_notifications,
				'consent_timestamp'         => $subscriber->consent_timestamp,
				'consent_version'           => $subscriber->consent_version,
				'consent_source'            => $subscriber->consent_source,
				'created_at'                => $subscriber->created_at,
			),
			'channels'   => self::get_channels( $subscriber_id ),
			'selections' => self::get_selections( $subscriber_id ),
		);
	}

	/**
	 * Permanently erases a subscriber's personal data: profile, channels,
	 * selections, tokens, and pending queue entries. Historical
	 * notification_log rows referencing this subscriber ID are retained
	 * for audit purposes but no longer resolve to identifying data once
	 * the subscribers row itself is gone.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 */
	public static function erase_subscriber_data( $subscriber_id ) {
		global $wpdb;
		$subscriber_id = absint( $subscriber_id );

		$wpdb->delete( ssm_table( 'subscriber_channels' ), array( 'subscriber_id' => $subscriber_id ) );
		$wpdb->delete( ssm_table( 'subscriber_selections' ), array( 'subscriber_id' => $subscriber_id ) );
		$wpdb->delete( ssm_table( 'verification_tokens' ), array( 'subscriber_id' => $subscriber_id ) );
		$wpdb->delete( ssm_table( 'notification_queue' ), array( 'subscriber_id' => $subscriber_id, 'status' => 'pending' ) );
		$wpdb->delete( ssm_table( 'subscribers' ), array( 'id' => $subscriber_id ) );

		do_action( 'ssm_subscriber_erased', $subscriber_id );
	}

	/**
	 * Normalises a phone number to a conservative E.164-ish digit string
	 * for storage/hashing (keeps a leading + if present).
	 *
	 * @param string $phone Raw phone number.
	 * @return string
	 */
	public static function sanitize_phone( $phone ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
		return $phone && strlen( preg_replace( '/\D/', '', $phone ) ) >= 7 ? $phone : '';
	}

	/**
	 * Validates that a URL looks like a genuine Microsoft Teams (or
	 * Office/Power Automate) incoming webhook endpoint, to reduce the risk
	 * of the field being used to make the server POST to an arbitrary
	 * third-party URL.
	 *
	 * @param string $url Candidate webhook URL.
	 * @return bool
	 */
	public static function is_valid_teams_webhook( $url ) {
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return false;
		}

		$allowed_suffixes = array( '.webhook.office.com', '.logic.azure.com', '.powerautomate.com', '.webhook.office365.com' );
		foreach ( $allowed_suffixes as $suffix ) {
			if ( $host === ltrim( $suffix, '.' ) || substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		/**
		 * Filters whether a given Teams destination host should be
		 * accepted, in case Microsoft changes the supported webhook
		 * domain (see notifications/class-teams-provider.php).
		 *
		 * @param bool   $valid Whether the host is currently accepted.
		 * @param string $host  Lower-cased hostname from the submitted URL.
		 */
		return apply_filters( 'ssm_valid_teams_webhook_host', false, $host );
	}
}
