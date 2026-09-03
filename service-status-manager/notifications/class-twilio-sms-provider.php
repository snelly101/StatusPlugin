<?php
/**
 * SMS notifications via Twilio (https://www.twilio.com/), the reference
 * SMS provider implementation. Requires a Twilio account (paid,
 * third-party service - not provided by this plugin).
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

use ServiceStatusManager\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TwilioSmsProvider extends SmsProvider implements ConcurrentSendProviderInterface, RateLimitedProviderInterface {

	/**
	 * {@inheritDoc}
	 */
	protected function send_raw( $to, $message ) {
		$credentials = $this->get_credentials();

		if ( empty( $credentials['account_sid'] ) || empty( $credentials['auth_token'] ) ) {
			return new SendResult( false, '', __( 'Twilio is not configured (missing Account SID or Auth Token).', 'service-status-manager' ) );
		}

		$sender = \ssm_get_setting( 'sms_sender' );
		if ( empty( $sender ) ) {
			return new SendResult( false, '', __( 'No Twilio sender number is configured.', 'service-status-manager' ) );
		}

		$endpoint = sprintf( 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', rawurlencode( $credentials['account_sid'] ) );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $credentials['account_sid'] . ':' . $credentials['auth_token'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
				'body'    => array(
					'From' => $sender,
					'To'   => $to,
					'Body' => $message,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new SendResult( false, '', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return new SendResult( true, 'twilio_sid:' . ( $body['sid'] ?? 'unknown' ) );
		}

		$error_message = $body['message'] ?? sprintf( 'Twilio returned HTTP %d.', $code );
		return new SendResult( false, '', $error_message, $code );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Builds one Twilio API request per recipient (Twilio's Messages
	 * resource is inherently one message per call - there is no batch/
	 * multi-recipient endpoint) and fires them all through
	 * ConcurrentHttpClient instead of SmsProvider::send()'s implicit
	 * one-at-a-time loop.
	 */
	public function send_many( array $items ) {
		if ( empty( $items ) ) {
			return array();
		}

		$credentials = $this->get_credentials();

		if ( empty( $credentials['account_sid'] ) || empty( $credentials['auth_token'] ) ) {
			$error = new SendResult( false, '', __( 'Twilio is not configured (missing Account SID or Auth Token).', 'service-status-manager' ) );
			return array_fill_keys( array_keys( $items ), $error );
		}

		$sender = \ssm_get_setting( 'sms_sender' );
		if ( empty( $sender ) ) {
			$error = new SendResult( false, '', __( 'No Twilio sender number is configured.', 'service-status-manager' ) );
			return array_fill_keys( array_keys( $items ), $error );
		}

		$endpoint = sprintf( 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', rawurlencode( $credentials['account_sid'] ) );
		$auth     = 'Basic ' . base64_encode( $credentials['account_sid'] . ':' . $credentials['auth_token'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$requests = array();
		$results  = array();

		foreach ( $items as $key => $item ) {
			$subscriber = $item['subscriber'];

			if ( empty( $subscriber->phone ) ) {
				$results[ $key ] = new SendResult( false, '', __( 'Subscriber has no mobile number.', 'service-status-manager' ) );
				continue;
			}

			if ( ! $this->within_daily_limit( $subscriber->id ) ) {
				$results[ $key ] = new SendResult( false, '', __( 'Subscriber has reached their maximum SMS messages for today.', 'service-status-manager' ) );
				continue;
			}

			$requests[ $key ] = array(
				'url'     => $endpoint,
				'headers' => array(
					'Authorization' => $auth,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query(
					array(
						'From' => $sender,
						'To'   => $subscriber->phone,
						'Body' => $this->build_text( $item['payload'] ),
					)
				),
			);
		}

		if ( empty( $requests ) ) {
			return $results;
		}

		$http_results = ConcurrentHttpClient::send_many( $requests, $this->get_rate_limit_per_second() ?: 10, 15 );

		foreach ( $requests as $key => $request ) {
			$http_result = $http_results[ $key ] ?? new HttpResult( false, null, '', __( 'Request did not complete.', 'service-status-manager' ) );
			$result      = $this->interpret_http_result( $http_result );

			if ( $result->success ) {
				$this->increment_daily_count( $items[ $key ]['subscriber']->id );
			}

			$results[ $key ] = $result;
		}

		return $results;
	}

	/**
	 * @return int
	 */
	public function get_rate_limit_per_second() {
		return (int) apply_filters( 'ssm_twilio_rate_limit_per_second', 10 );
	}

	/**
	 * @param HttpResult $result Raw HTTP outcome from ConcurrentHttpClient.
	 * @return SendResult
	 */
	private function interpret_http_result( HttpResult $result ) {
		if ( ! $result->completed ) {
			return new SendResult( false, '', $result->error ?: __( 'Connection to Twilio failed.', 'service-status-manager' ) );
		}

		$body = json_decode( $result->body, true );

		if ( $result->http_code >= 200 && $result->http_code < 300 ) {
			return new SendResult( true, 'twilio_sid:' . ( $body['sid'] ?? 'unknown' ) );
		}

		$error_message = $body['message'] ?? sprintf( 'Twilio returned HTTP %d.', $result->http_code );
		return new SendResult( false, '', $error_message, $result->http_code );
	}

	/**
	 * Decrypts the stored Twilio credentials.
	 *
	 * @return array{account_sid: string, auth_token: string}
	 */
	private function get_credentials() {
		$encrypted = \ssm_get_setting( 'sms_credentials_encrypted', '' );
		if ( '' === $encrypted ) {
			return array();
		}

		$decrypted = Encryption::decrypt( $encrypted );
		$data      = $decrypted ? json_decode( $decrypted, true ) : array();

		return is_array( $data ) ? $data : array();
	}
}
