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

class TwilioSmsProvider extends SmsProvider {

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
