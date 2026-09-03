<?php
/**
 * Microsoft Teams notification provider using Incoming Webhooks.
 *
 * Microsoft has signalled that "Office 365 Connectors" (the classic
 * Incoming Webhook mechanism used here, posting an O365 MessageCard JSON
 * payload) are being retired in favour of Workflows built on Power
 * Automate. At the time of writing, Incoming Webhooks remain the
 * documented, generally-available way to post into a Teams channel from
 * a third-party system without an Azure AD app registration, so this is
 * the method implemented here. It is isolated entirely behind
 * NotificationProviderInterface: if/when Microsoft finalises a
 * replacement, only this file needs to change - subscribers,
 * NotificationQueue, and every other caller are unaffected.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

use ServiceStatusManager\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TeamsProvider implements NotificationProviderInterface, ConcurrentSendProviderInterface {

	/**
	 * {@inheritDoc}
	 */
	public function send( $subscriber, array $message ) {
		if ( empty( $subscriber->teams_webhook ) ) {
			return new SendResult( false, '', __( 'Subscriber has no Microsoft Teams destination configured.', 'service-status-manager' ) );
		}

		$url = Encryption::decrypt( $subscriber->teams_webhook );
		if ( ! $url ) {
			return new SendResult( false, '', __( 'The stored Teams webhook URL could not be decrypted.', 'service-status-manager' ) );
		}

		return $this->post_card( $url, $message );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Each subscriber has their own webhook URL (there's no shared Teams
	 * endpoint to batch against), so this is many independent POSTs fired
	 * concurrently rather than any kind of provider-side batching.
	 */
	public function send_many( array $items ) {
		if ( empty( $items ) ) {
			return array();
		}

		$requests = array();
		$results  = array();

		foreach ( $items as $key => $item ) {
			$subscriber = $item['subscriber'];

			if ( empty( $subscriber->teams_webhook ) ) {
				$results[ $key ] = new SendResult( false, '', __( 'Subscriber has no Microsoft Teams destination configured.', 'service-status-manager' ) );
				continue;
			}

			$url = Encryption::decrypt( $subscriber->teams_webhook );
			if ( ! $url || ! wp_http_validate_url( $url ) ) {
				$results[ $key ] = new SendResult( false, '', __( 'Invalid Teams webhook URL.', 'service-status-manager' ) );
				continue;
			}

			$requests[ $key ] = array(
				'url'     => $url,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $this->build_card( $item['payload'] ) ),
			);
		}

		if ( empty( $requests ) ) {
			return $results;
		}

		$http_results = ConcurrentHttpClient::send_many( $requests, max( 1, (int) apply_filters( 'ssm_teams_max_concurrency', \ssm_get_setting( 'teams_max_concurrency', 10 ) ) ), 10 );

		foreach ( $requests as $key => $request ) {
			$http_result      = $http_results[ $key ] ?? new HttpResult( false, null, '', __( 'Request did not complete.', 'service-status-manager' ) );
			$results[ $key ]  = $this->interpret_http_result( $http_result );
		}

		return $results;
	}

	/**
	 * {@inheritDoc}
	 */
	public function send_test( $destination ) {
		return $this->post_card(
			$destination,
			array(
				'subject'    => __( 'Test notification', 'service-status-manager' ),
				'sms_summary' => __( 'This is a test message from Service Status Manager.', 'service-status-manager' ),
				'severity'   => 'informational',
				'event_type' => 'test',
			)
		);
	}

	/**
	 * Posts an Office 365 Connector "MessageCard" to the given webhook URL.
	 * Used by the single-recipient send() path; send_many() builds the same
	 * card via build_card() and posts it through ConcurrentHttpClient
	 * instead, sharing the card-building logic below.
	 *
	 * @param string $url     Teams incoming webhook URL.
	 * @param array  $message Message context.
	 * @return SendResult
	 */
	private function post_card( $url, array $message ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return new SendResult( false, '', __( 'Invalid Teams webhook URL.', 'service-status-manager' ) );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $this->build_card( $message ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new SendResult( false, '', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return new SendResult( true, 'http_' . $code );
		}

		return new SendResult( false, '', sprintf( 'Teams webhook returned HTTP %d.', $code ), $code );
	}

	/**
	 * Builds an Office 365 Connector "MessageCard" payload, with a
	 * plain-text fallback summary for clients that cannot render cards.
	 *
	 * @param array $message Message context.
	 * @return array
	 */
	private function build_card( array $message ) {
		$colors = array(
			'informational' => '6b7280',
			'minor'         => 'eab308',
			'major'         => 'f97316',
			'critical'      => 'dc2626',
		);
		$theme_color = $colors[ $message['severity'] ?? '' ] ?? '2563eb';

		$facts = array();
		if ( ! empty( $message['affected_services'] ) ) {
			$facts[] = array(
				'name'  => __( 'Affected services', 'service-status-manager' ),
				'value' => $message['affected_services'],
			);
		}
		if ( ! empty( $message['status_label'] ) ) {
			$facts[] = array(
				'name'  => __( 'Status', 'service-status-manager' ),
				'value' => $message['status_label'],
			);
		}

		$card = array(
			'@type'      => 'MessageCard',
			'@context'   => 'http://schema.org/extensions',
			'summary'    => wp_strip_all_tags( $message['subject'] ?? __( 'Service status update', 'service-status-manager' ) ),
			'themeColor' => $theme_color,
			'title'      => wp_strip_all_tags( $message['subject'] ?? '' ),
			'text'       => wp_strip_all_tags( $message['sms_summary'] ?? $message['body_text'] ?? '' ),
		);

		if ( $facts ) {
			$card['sections'] = array( array( 'facts' => $facts ) );
		}

		if ( ! empty( $message['url'] ) ) {
			$card['potentialAction'] = array(
				array(
					'@type'   => 'OpenUri',
					'name'    => __( 'View details', 'service-status-manager' ),
					'targets' => array( array( 'os' => 'default', 'uri' => $message['url'] ) ),
				),
			);
		}

		/**
		 * Filters the Teams card payload before it is sent, so sites can
		 * add their own branding fields.
		 *
		 * @param array $card    The MessageCard payload.
		 * @param array $message Original message context.
		 */
		return apply_filters( 'ssm_teams_card_payload', $card, $message );
	}

	/**
	 * @param HttpResult $result Raw HTTP outcome from ConcurrentHttpClient.
	 * @return SendResult
	 */
	private function interpret_http_result( HttpResult $result ) {
		if ( ! $result->completed ) {
			return new SendResult( false, '', $result->error ?: __( 'Connection to the Teams webhook failed.', 'service-status-manager' ) );
		}

		if ( $result->http_code >= 200 && $result->http_code < 300 ) {
			return new SendResult( true, 'http_' . $result->http_code );
		}

		return new SendResult( false, '', sprintf( 'Teams webhook returned HTTP %d.', $result->http_code ), $result->http_code );
	}
}
