<?php
/**
 * SMS notifications via the SMTP2GO SMS API (https://developers.smtp2go.com/),
 * as an alternative to Twilio. Requires an SMTP2GO account on a paid plan
 * with SMS enabled (third-party service - not provided by this plugin) and
 * an API key - the same key already used for the SMTP2GO email provider
 * (Smtp2goApiProvider), since it is one API key per SMTP2GO account that
 * covers every product (email, SMS). Configuring this provider never
 * requires a second key.
 *
 * API contract used here (verified against SMTP2GO's own documentation,
 * developers.smtp2go.com, at implementation time - not guessed at):
 *   POST https://api.smtp2go.com/v3/sms/send
 *   Auth: X-Smtp2go-Api-Key header (same key as the email provider).
 *   Required fields: destination (array of E.164-ish numbers, max 100),
 *         content (message text). sender is optional - the account's
 *         default sender is used when omitted.
 *   Response (HTTP 200): {request_id, data: {statuses, total_sent,
 *         messages: [{destination, message_id, status}]}}. SMTP2GO
 *         documents no separate top-level success flag - a 200 only
 *         confirms the request was accepted, so each message's own
 *         `status` must still be checked for "failed"/"rejected".
 *   Failure (HTTP 400 etc.): {request_id, data: {error, error_code}}.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

use ServiceStatusManager\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Smtp2goSmsProvider extends SmsProvider implements ConcurrentSendProviderInterface, RateLimitedProviderInterface, HealthCheckableProviderInterface {

	/**
	 * Statuses SMTP2GO can return for an individual message that mean the
	 * message was rejected outright rather than merely queued/in transit -
	 * everything else (queued, processing, enroute, submitted, processed,
	 * delivered, held, expired, cancelled) is treated as "accepted", the
	 * same immediate-acceptance-only contract Twilio's provider uses.
	 */
	const FAILED_STATUSES = array( 'failed', 'rejected' );

	/**
	 * {@inheritDoc}
	 */
	protected function send_raw( $to, $message ) {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new SendResult( false, '', __( 'SMTP2GO is not configured (missing API key).', 'service-status-manager' ) );
		}

		$response = wp_remote_post(
			$this->get_endpoint(),
			array(
				'timeout' => (int) \ssm_get_setting( 'smtp2go_sms_timeout', 15 ),
				'headers' => $this->build_headers( $api_key ),
				'body'    => wp_json_encode( $this->build_body( array( $to ), $message ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new SendResult( false, '', $response->get_error_message() );
		}

		return $this->interpret_response(
			wp_remote_retrieve_response_code( $response ),
			wp_remote_retrieve_body( $response )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * SMTP2GO's /sms/send accepts up to 100 destinations in a single call,
	 * but a batch here is still sent as one concurrent request per
	 * recipient (like TwilioSmsProvider::send_many()) rather than grouped
	 * into true multi-destination calls - a claimed batch can span several
	 * different events/messages with different text, and SMTP2GO's batch
	 * endpoint applies one `content` value to every destination in the
	 * call, so grouping by identical message text would be required first.
	 * One request per recipient still gets the real throughput win (many
	 * requests in flight at once, via ConcurrentHttpClient) without that
	 * added complexity.
	 */
	public function send_many( array $items ) {
		if ( empty( $items ) ) {
			return array();
		}

		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			$error = new SendResult( false, '', __( 'SMTP2GO is not configured (missing API key).', 'service-status-manager' ) );
			return array_fill_keys( array_keys( $items ), $error );
		}

		$headers  = $this->build_headers( $api_key );
		$endpoint = $this->get_endpoint();

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
				'headers' => $headers,
				'body'    => wp_json_encode( $this->build_body( array( $subscriber->phone ), $this->build_text( $item['payload'] ) ) ),
			);
		}

		if ( empty( $requests ) ) {
			return $results;
		}

		$http_results = ConcurrentHttpClient::send_many( $requests, $this->get_max_concurrency(), (int) \ssm_get_setting( 'smtp2go_sms_timeout', 15 ) );

		foreach ( $requests as $key => $request ) {
			$http_result = $http_results[ $key ] ?? new HttpResult( false, null, '', __( 'Request did not complete.', 'service-status-manager' ) );

			if ( ! $http_result->completed ) {
				$results[ $key ] = new SendResult( false, '', $http_result->error ?: __( 'Connection to SMTP2GO failed.', 'service-status-manager' ) );
				continue;
			}

			$result = $this->interpret_response( $http_result->http_code, $http_result->body );

			if ( $result->success ) {
				$this->increment_daily_count( $items[ $key ]['subscriber']->id );
			}

			$results[ $key ] = $result;
		}

		return $results;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_rate_limit_per_second() {
		return (int) apply_filters( 'ssm_smtp2go_sms_rate_limit_per_second', \ssm_get_setting( 'smtp2go_sms_rate_limit_per_second', 10 ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Uses /sms/summary - a read-only, low-cost endpoint that requires
	 * nothing but valid authentication, so a successful call confirms both
	 * "the API key works" and "SMTP2GO is reachable" without sending
	 * anything or spending SMS send quota. The same API key as the email
	 * provider's health check, just a different endpoint.
	 */
	public function health_check() {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return array( 'healthy' => false, 'message' => __( 'No API key configured. The SMTP2GO SMS provider uses the same API key as the SMTP2GO email provider, above.', 'service-status-manager' ) );
		}

		$response = wp_remote_post(
			'https://api.smtp2go.com/v3/sms/summary',
			array(
				'timeout' => 10,
				'headers' => $this->build_headers( $api_key ),
				'body'    => '{}',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'healthy' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return array( 'healthy' => true, 'message' => __( 'API key valid and SMTP2GO reachable.', 'service-status-manager' ) );
		}

		if ( 401 === $code ) {
			return array( 'healthy' => false, 'message' => __( 'API key rejected (401 Unauthorized).', 'service-status-manager' ) );
		}

		return array(
			'healthy' => false,
			/* translators: %d: HTTP status code */
			'message' => sprintf( __( 'SMTP2GO returned HTTP %d.', 'service-status-manager' ), $code ),
		);
	}

	/**
	 * @param string $api_key Decrypted SMTP2GO API key.
	 * @return array
	 */
	private function build_headers( $api_key ) {
		return array(
			'Content-Type'      => 'application/json',
			'Accept'            => 'application/json',
			'X-Smtp2go-Api-Key' => $api_key,
		);
	}

	/**
	 * @param string[] $destinations One or more E.164-ish numbers.
	 * @param string   $content      Message text.
	 * @return array
	 */
	private function build_body( array $destinations, $content ) {
		$body = array(
			'destination' => array_values( $destinations ),
			'content'     => $content,
		);

		$sender = \ssm_get_setting( 'sms_sender', '' );
		if ( '' !== $sender ) {
			$body['sender'] = $sender;
		}

		return $body;
	}

	/**
	 * Interprets an /sms/send response (shared by send_raw() and
	 * send_many(), whose HTTP transports differ but whose response bodies
	 * are identical) into a SendResult. A 200 only means the request was
	 * accepted - the single message's own `status` still has to be
	 * checked, since SMTP2GO can accept the call but reject an individual
	 * destination (see FAILED_STATUSES).
	 *
	 * @param int|null $http_code
	 * @param string   $raw_body
	 * @return SendResult
	 */
	private function interpret_response( $http_code, $raw_body ) {
		$decoded = json_decode( (string) $raw_body, true );

		if ( $http_code >= 200 && $http_code < 300 ) {
			$message = is_array( $decoded ) ? ( $decoded['data']['messages'][0] ?? array() ) : array();
			$status  = $message['status'] ?? '';

			if ( in_array( $status, self::FAILED_STATUSES, true ) ) {
				/* translators: %s: SMTP2GO message status, e.g. "rejected" */
				return new SendResult( false, '', sprintf( __( 'SMTP2GO %s the message.', 'service-status-manager' ), $status ), $http_code );
			}

			$message_id = $message['message_id'] ?? ( $decoded['request_id'] ?? 'unknown' );
			return new SendResult( true, 'smtp2go_sms:' . $message_id );
		}

		$error = is_array( $decoded ) ? ( $decoded['data']['error'] ?? '' ) : '';
		if ( ! $error ) {
			/* translators: %d: HTTP status code */
			$error = sprintf( __( 'SMTP2GO returned HTTP %d.', 'service-status-manager' ), (int) $http_code );
		}

		return new SendResult( false, '', $error, $http_code );
	}

	/**
	 * @return string
	 */
	private function get_endpoint() {
		return (string) \ssm_get_setting( 'smtp2go_sms_endpoint', 'https://api.smtp2go.com/v3/sms/send' );
	}

	/**
	 * @return int Max requests in flight at once for a send_many() batch.
	 */
	private function get_max_concurrency() {
		return max( 1, (int) apply_filters( 'ssm_smtp2go_sms_max_concurrency', \ssm_get_setting( 'smtp2go_sms_max_concurrency', 10 ) ) );
	}

	/**
	 * Decrypts the stored SMTP2GO API key - the same setting
	 * Smtp2goApiProvider (email) uses, since SMTP2GO issues one API key
	 * per account covering every product.
	 *
	 * @return string
	 */
	private function get_api_key() {
		$encrypted = \ssm_get_setting( 'smtp2go_api_key_encrypted', '' );
		if ( '' === $encrypted ) {
			return '';
		}

		return (string) Encryption::decrypt( $encrypted );
	}
}
