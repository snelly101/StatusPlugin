<?php
/**
 * Repository for status page records (branding, included services, and
 * visibility for one or more public/private status pages).
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StatusPageManager {

	/**
	 * Returns every configured status page.
	 *
	 * @return array
	 */
	public static function get_pages() {
		global $wpdb;
		$table = ssm_table( 'status_pages' );
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $id Status page ID.
	 * @return object|null
	 */
	public static function get_page( $id ) {
		global $wpdb;
		$table = ssm_table( 'status_pages' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Retrieves a status page by slug, falling back to the first
	 * available page (typically "main") if the slug is not found.
	 *
	 * @param string $slug Status page slug.
	 * @return object|null
	 */
	public static function get_page_by_slug( $slug = 'main' ) {
		global $wpdb;
		$table = ssm_table( 'status_pages' );
		$page  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", sanitize_title( $slug ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $page ) {
			$page = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $page;
	}

	/**
	 * Creates a status page.
	 *
	 * @param array $data Raw input.
	 * @return int|\WP_Error
	 */
	public static function create_page( array $data ) {
		global $wpdb;

		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( '' === $name ) {
			return new \WP_Error( 'ssm_invalid_status_page', __( 'A status page name is required.', 'service-status-manager' ) );
		}

		$slug = self::unique_slug( sanitize_title( $data['slug'] ?? $name ) );

		$wpdb->insert(
			ssm_table( 'status_pages' ),
			self::prepare_fields( $data, $slug ) + array(
				'created_at' => ssm_now(),
				'updated_at' => ssm_now(),
			)
		);

		$id = (int) $wpdb->insert_id;
		AuditLog::record( 'status_page_created', 'status_page', $id, null, $data );

		return $id;
	}

	/**
	 * Updates a status page.
	 *
	 * @param int   $id   Status page ID.
	 * @param array $data Raw input.
	 * @return bool|\WP_Error
	 */
	public static function update_page( $id, array $data ) {
		global $wpdb;

		$id       = absint( $id );
		$existing = self::get_page( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'ssm_not_found', __( 'Status page not found.', 'service-status-manager' ) );
		}

		$slug = $existing->slug;
		if ( ! empty( $data['slug'] ) && sanitize_title( $data['slug'] ) !== $existing->slug ) {
			$slug = self::unique_slug( sanitize_title( $data['slug'] ), $id );
		}

		$fields               = self::prepare_fields( $data, $slug, $existing );
		$fields['updated_at'] = ssm_now();

		$wpdb->update( ssm_table( 'status_pages' ), $fields, array( 'id' => $id ) );
		AuditLog::record( 'status_page_updated', 'status_page', $id, $existing, $data );

		return true;
	}

	/**
	 * Builds the column => value array shared by create/update, applying
	 * sane defaults from the existing row when updating.
	 *
	 * @param array       $data     Raw input.
	 * @param string      $slug     Resolved unique slug.
	 * @param object|null $existing Existing row, when updating.
	 * @return array
	 */
	private static function prepare_fields( array $data, $slug, $existing = null ) {
		$get = function ( $key, $default = '' ) use ( $data, $existing ) {
			if ( array_key_exists( $key, $data ) ) {
				return $data[ $key ];
			}
			return $existing->$key ?? $default;
		};

		return array(
			'name'                  => sanitize_text_field( $get( 'name' ) ),
			'slug'                  => $slug,
			'logo_url'              => esc_url_raw( $get( 'logo_url' ) ),
			'primary_color'         => sanitize_hex_color( $get( 'primary_color' ) ) ?: null,
			'secondary_color'       => sanitize_hex_color( $get( 'secondary_color' ) ) ?: null,
			'included_groups'       => wp_json_encode( array_map( 'absint', (array) $get( 'included_groups', array() ) ) ),
			'included_services'     => wp_json_encode( array_map( 'absint', (array) $get( 'included_services', array() ) ) ),
			'visibility'            => in_array( $get( 'visibility', 'public' ), array( 'public', 'private' ), true ) ? $get( 'visibility', 'public' ) : 'public',
			'password_hash'         => ! empty( $data['password'] ) ? wp_hash_password( $data['password'] ) : ( $existing->password_hash ?? null ),
			'page_title'            => sanitize_text_field( $get( 'page_title' ) ),
			'intro_text'            => wp_kses_post( $get( 'intro_text' ) ),
			'support_url'           => esc_url_raw( $get( 'support_url' ) ),
			'privacy_url'           => esc_url_raw( $get( 'privacy_url' ) ),
			'terms_url'             => esc_url_raw( $get( 'terms_url' ) ),
			'timezone'              => sanitize_text_field( $get( 'timezone', wp_timezone_string() ) ),
			'date_format'           => sanitize_text_field( $get( 'date_format', get_option( 'date_format' ) ) ),
			'default_history_range' => absint( $get( 'default_history_range', 90 ) ),
			'custom_css'            => current_user_can( Capabilities::MANAGE_SETTINGS ) ? self::sanitize_css( $get( 'custom_css' ) ) : ( $existing->custom_css ?? '' ),
			'settings'              => wp_json_encode( (array) $get( 'settings', array() ) ),
		);
	}

	/**
	 * Strips anything that isn't plain CSS syntax (no closing style tags,
	 * script injection, @import of remote resources is left to admin
	 * discretion since this is an administrator-only field, but tags are
	 * still stripped defensively).
	 *
	 * @param string $css Raw CSS.
	 * @return string
	 */
	private static function sanitize_css( $css ) {
		return wp_strip_all_tags( (string) $css );
	}

	/**
	 * Ensures a status page slug is unique.
	 *
	 * @param string   $slug       Candidate slug.
	 * @param int|null $exclude_id ID to exclude.
	 * @return string
	 */
	private static function unique_slug( $slug, $exclude_id = null ) {
		global $wpdb;
		$table = ssm_table( 'status_pages' );

		$base  = $slug;
		$index = 1;

		while ( true ) {
			$sql    = "SELECT id FROM {$table} WHERE slug = %s";
			$params = array( $slug );
			if ( $exclude_id ) {
				$sql     .= ' AND id != %d';
				$params[] = $exclude_id;
			}
			$found = $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( ! $found ) {
				return $slug;
			}

			++$index;
			$slug = $base . '-' . $index;
		}
	}
}
