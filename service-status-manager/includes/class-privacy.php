<?php
/**
 * Integrates subscriber data with WordPress' built-in Privacy Tools
 * (Tools > Export Personal Data / Erase Personal Data), so a site's
 * existing UK GDPR data-subject-request workflow also covers Service
 * Status Manager subscribers, not just WordPress user accounts.
 *
 * This plugin does not, by itself, make a site GDPR compliant - see
 * readme.txt "Privacy & UK GDPR" for what remains the site operator's
 * responsibility (lawful basis, provider contracts, retention policy).
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Privacy {

	/**
	 * Registers the exporter/eraser with WordPress.
	 */
	public static function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['service-status-manager-subscribers'] = array(
			'exporter_friendly_name' => __( 'Service Status Manager Subscribers', 'service-status-manager' ),
			'callback'                => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['service-status-manager-subscribers'] = array(
			'eraser_friendly_name' => __( 'Service Status Manager Subscribers', 'service-status-manager' ),
			'callback'              => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exports every subscriber record matching the requested email address.
	 *
	 * @param string $email_address Requested email address.
	 * @param int    $page          Page number (unused - subscriber counts per email are always small).
	 * @return array
	 */
	public static function export( $email_address, $page = 1 ) {
		global $wpdb;

		$hash  = hash( 'sha256', strtolower( $email_address ) );
		$table = ssm_table( 'subscribers' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE email_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$export_items = array();

		foreach ( $ids as $id ) {
			$data = SubscriberManager::export_subscriber_data( $id );
			if ( empty( $data ) ) {
				continue;
			}

			$fields = array();
			foreach ( $data['profile'] as $key => $value ) {
				$fields[] = array(
					'name'  => $key,
					'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
				);
			}

			$export_items[] = array(
				'group_id'    => 'ssm-subscriber',
				'group_label' => __( 'Service Status Subscription', 'service-status-manager' ),
				'item_id'     => 'ssm-subscriber-' . $id,
				'data'        => $fields,
			);
		}

		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * Erases every subscriber record matching the requested email address.
	 *
	 * @param string $email_address Requested email address.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public static function erase( $email_address, $page = 1 ) {
		global $wpdb;

		$hash  = hash( 'sha256', strtolower( $email_address ) );
		$table = ssm_table( 'subscribers' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE email_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$removed = false;
		foreach ( $ids as $id ) {
			SubscriberManager::erase_subscriber_data( $id );
			$removed = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
