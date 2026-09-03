<?php
/**
 * Email notifications via the SMTP2GO REST API (https://api.smtp2go.com/v3/),
 * as an alternative to wp_mail(). Requires an SMTP2GO account (paid,
 * third-party service - not provided by this plugin) and an API key.
 *
 * This is a genuinely separate transport from the existing Smtp class -
 * Smtp only configures PHPMailer to relay through SMTP2GO's SMTP server
 * (or any other SMTP server); this class calls SMTP2GO's HTTP API
 * directly, which is what makes concurrent sending (many messages at once,
 * see send_many()) possible in the first place - PHPMailer's SMTP
 * transport is inherently one message per connection.
 *
 * API contract used here (verified against SMTP2GO's own documentation,
 * developers.smtp2go.com, at implementation time - not guessed at):
 *   POST https://api.smtp2go.com/v3/email/send
 *   Auth: X-Smtp2go-Api-Key header (also documented as accepted in the
 *         JSON body as "api_key" - the header is used here since it keeps
 *         the key out of the logged/replayable request body).
 *   Required fields: sender, to (array), subject, and at least one of
 *         text_body/html_body/template_id.
 *   Reply-To is set via the custom_headers array, not a dedicated field.
 *   Standard HTTP status codes: 2xx success, 401 invalid/disabled API key,
 *         429 rate-limited (SMTP2GO has no fixed RPS limit but times out
 *         an IP that sends too many requests/errors), other 4xx validation
 *         errors, 5xx SMTP2GO-side errors - this maps directly onto
 *         NotificationRetryPolicy's default classification, no
 *         provider-specific override filter needed.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

use ServiceStatusManager\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Smtp2goApiProvider implements NotificationProviderInterface, ConcurrentSendProviderInterface, RateLimitedProviderInterface, HealthCheckableProviderInterface {

	const SLUG = 'smtp2go';

	/**
	 * {@inheritDoc}
	 */
	public function send( $subscriber, array $message ) {
		if ( empty( $subscriber->email ) || ! is_email( $subscriber->email ) ) {
			return new SendResult( false, '', __( 'Subscriber has no valid email address.', 'service-status-manager' ) );
		}

		$results = $this->send_many( array( 'single' => array( 'subscriber' => $subscriber, 'payload' => $message ) ) );

		return $results['single'] ?? new SendResult( false, '', __( 'SMTP2GO request did not complete.', 'service-status-manager' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function send_test( $destination ) {
		$fake_subscriber = (object) array( 'email' => $destination );

		return $this->send(
			$fake_subscriber,
			array(
				'subject'    => __( '[Test] Service Status Manager notification', 'service-status-manager' ),
				'body_html'  => '<p>' . esc_html__( 'This is a test email from Service Status Manager, sent via the SMTP2GO API. If you received this, SMTP2GO is configured correctly.', 'service-status-manager' ) . '</p>',
				'body_text'  => __( 'This is a test email from Service Status Manager, sent via the SMTP2GO API. If you received this, SMTP2GO is configured correctly.', 'service-status-manager' ),
				'event_type' => 'test',
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * v1 sends via one concurrent API call per recipient rather than
	 * SMTP2GO's multi-recipient `to` array in a single call - each
	 * recipient needs their own manage/unsubscribe links in the body, and
	 * personalising a single multi-recipient call isn't part of the v3
	 * `/email/send` contract. This still gets the throughput win: many
	 * individual calls in flight at once, instead of wp_mail()'s fully
	 * serial one-at-a-time sends.
	 */
	public function send_many( array $items ) {
		if ( empty( $items ) ) {
			return array();
		}

		$settings = \ssm_get_settings();
		$api_key  = $this->get_api_key();

		if ( empty( $api_key ) ) {
			$error = new SendResult( false, '', __( 'SMTP2GO is not configured (missing API key).', 'service-status-manager' ) );
			return array_fill_keys( array_keys( $items ), $error );
		}

		if ( empty( $settings['smtp2go_sender'] ) ) {
			$error = new SendResult( false, '', __( 'No SMTP2GO sender address is configured.', 'service-status-manager' ) );
			return array_fill_keys( array_keys( $items ), $error );
		}

		$requests = array();
		$skipped  = array();

		foreach ( $items as $key => $item ) {
			$subscriber = $item['subscriber'];
			$message    = $item['payload'];

			if ( empty( $subscriber->email ) || ! is_email( $subscriber->email ) ) {
				$skipped[ $key ] = new SendResult( false, '', __( 'Subscriber has no valid email address.', 'service-status-manager' ) );
				continue;
			}

			$requests[ $key ] = $this->build_request( $subscriber->email, $message, $settings, $api_key );
		}

		$http_results = empty( $requests ) ? array() : ConcurrentHttpClient::send_many(
			$requests,
			(int) ( $settings['smtp2go_max_concurrency'] ?? 10 ),
			(int) ( $settings['smtp2go_timeout'] ?? 15 )
		);

		$results = $skipped;

		foreach ( $requests as $key => $request ) {
			$http_result    = $http_results[ $key ] ?? new HttpResult( false, null, '', __( 'Request did not complete.', 'service-status-manager' ) );
			$results[ $key ] = $this->interpret_http_result( $http_result );
		}

		return $results;
	}

	/**
	 * @return int
	 */
	public function get_rate_limit_per_second() {
		return (int) \ssm_get_setting( 'smtp2go_rate_limit_per_second', 10 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Uses /stats/email_summary - a read-only, parameter-free, low-cost
	 * endpoint that requires nothing but valid authentication, so a
	 * successful call confirms both "the API key works" and "SMTP2GO is
	 * reachable" without sending anything or touching send quota.
	 */
	public function health_check() {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return array( 'healthy' => false, 'message' => __( 'No API key configured.', 'service-status-manager' ) );
		}

		$response = wp_remote_post(
			'https://api.smtp2go.com/v3/stats/email_summary',
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'       => 'application/json',
					'Accept'             => 'application/json',
					'X-Smtp2go-Api-Key'  => $api_key,
				),
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
	 * @param string $to       Recipient email address.
	 * @param array  $message  {subject, body_html, body_text, event_type, ...}.
	 * @param array  $settings Plugin settings.
	 * @param string $api_key  Decrypted SMTP2GO API key.
	 * @return array {url, headers, body} - shaped for ConcurrentHttpClient.
	 */
	private function build_request( $to, array $message, array $settings, $api_key ) {
		$subject = apply_filters( 'ssm_email_subject', $message['subject'] ?? '', $message );
		$html    = apply_filters( 'ssm_email_body', EmailRenderer::wrap_html( $message['body_html'] ?? '', $message ), $message );
		$text    = apply_filters( 'ssm_email_alt_body', EmailRenderer::build_text_body( $message ), $message );

		$sender     = $this->format_address( $settings['smtp2go_sender'], $settings['from_name'] ?? '' );
		$reply_to   = ! empty( $settings['smtp2go_reply_to'] ) ? $settings['smtp2go_reply_to'] : '';

		$custom_headers = array();
		if ( $reply_to ) {
			$custom_headers[] = array( 'header' => 'Reply-To', 'value' => $reply_to );
		}

		$body = array(
			'sender'    => $sender,
			'to'        => array( $to ),
			'subject'   => $subject,
			'html_body' => $html,
			'text_body' => $text,
		);

		if ( $custom_headers ) {
			$body['custom_headers'] = $custom_headers;
		}

		$endpoint = ! empty( $settings['smtp2go_endpoint'] ) ? $settings['smtp2go_endpoint'] : 'https://api.smtp2go.com/v3/email/send';

		return array(
			'url'     => $endpoint,
			'headers' => array(
				'Content-Type'      => 'application/json',
				'Accept'            => 'application/json',
				'X-Smtp2go-Api-Key' => $api_key,
			),
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * @param HttpResult $result Raw HTTP outcome from ConcurrentHttpClient.
	 * @return SendResult
	 */
	private function interpret_http_result( HttpResult $result ) {
		if ( ! $result->completed ) {
			return new SendResult( false, '', $result->error ?: __( 'Connection to SMTP2GO failed.', 'service-status-manager' ) );
		}

		if ( $result->http_code >= 200 && $result->http_code < 300 ) {
			$decoded    = json_decode( $result->body, true );
			$request_id = is_array( $decoded ) ? ( $decoded['request_id'] ?? '' ) : '';
			return new SendResult( true, 'smtp2go_request:' . $request_id );
		}

		$decoded = json_decode( $result->body, true );
		$error   = is_array( $decoded ) ? ( $decoded['error'] ?? '' ) : '';

		if ( ! $error ) {
			/* translators: %d: HTTP status code */
			$error = sprintf( __( 'SMTP2GO returned HTTP %d.', 'service-status-manager' ), $result->http_code );
		}

		return new SendResult( false, '', $error, $result->http_code );
	}

	/**
	 * @param string $address "Name <email>" or bare email.
	 * @param string $fallback_name Used only if $address has no name portion.
	 * @return string
	 */
	private function format_address( $address, $fallback_name ) {
		$address = trim( (string) $address );

		if ( '' === $address || false !== strpos( $address, '<' ) ) {
			return $address;
		}

		return $fallback_name ? sprintf( '%s <%s>', $fallback_name, $address ) : $address;
	}

	/**
	 * Decrypts the stored SMTP2GO API key.
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
