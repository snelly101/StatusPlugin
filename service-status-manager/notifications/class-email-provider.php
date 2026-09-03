<?php
/**
 * Email notification provider, built entirely on wp_mail().
 *
 * Deliberately does not implement its own SMTP transport: any SMTP-
 * configuration plugin (WP Mail SMTP, Post SMTP, etc.) that hooks
 * `phpmailer_init`/`wp_mail` continues to control actual delivery, since
 * this class only ever calls the standard wp_mail() pluggable function.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EmailProvider implements NotificationProviderInterface {

	/**
	 * {@inheritDoc}
	 */
	public function send( $subscriber, array $message ) {
		if ( empty( $subscriber->email ) || ! is_email( $subscriber->email ) ) {
			return new SendResult( false, '', __( 'Subscriber has no valid email address.', 'service-status-manager' ) );
		}

		return $this->dispatch( $subscriber->email, $message );
	}

	/**
	 * {@inheritDoc}
	 */
	public function send_test( $destination ) {
		return $this->dispatch(
			$destination,
			array(
				'subject'   => __( '[Test] Service Status Manager notification', 'service-status-manager' ),
				'body_html' => '<p>' . esc_html__( 'This is a test email from Service Status Manager. If you received this, email notifications are configured correctly.', 'service-status-manager' ) . '</p>',
				'body_text' => __( 'This is a test email from Service Status Manager. If you received this, email notifications are configured correctly.', 'service-status-manager' ),
				'event_type' => 'test',
			)
		);
	}

	/**
	 * Sends the actual message via wp_mail(), with an HTML body and a
	 * plain-text alternative attached through phpmailer_init.
	 *
	 * @param string $to      Recipient email address.
	 * @param array  $message {subject, body_html, body_text, event_type}.
	 * @return SendResult
	 */
	private function dispatch( $to, array $message ) {
		$settings = \ssm_get_settings();

		$subject = apply_filters( 'ssm_email_subject', $message['subject'] ?? '', $message );
		$body    = apply_filters( 'ssm_email_body', EmailRenderer::wrap_html( $message['body_html'] ?? '', $message ), $message );
		$text    = apply_filters( 'ssm_email_alt_body', EmailRenderer::build_text_body( $message ), $message );

		$set_content_type = function () {
			return 'text/html';
		};
		$set_from_name = function () use ( $settings ) {
			return $settings['from_name'];
		};
		$set_from_email = function () use ( $settings ) {
			return sanitize_email( $settings['from_email'] );
		};
		$set_alt_body = function ( $phpmailer ) use ( $text ) {
			$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};

		$error = null;
		$capture_error = function ( $wp_error ) use ( &$error ) {
			$error = $wp_error;
		};

		add_filter( 'wp_mail_content_type', $set_content_type );
		add_filter( 'wp_mail_from_name', $set_from_name );
		if ( ! empty( $settings['from_email'] ) ) {
			add_filter( 'wp_mail_from', $set_from_email );
		}
		add_action( 'phpmailer_init', $set_alt_body );
		add_action( 'wp_mail_failed', $capture_error );

		$sent = wp_mail( $to, $subject, $body, array() );

		remove_filter( 'wp_mail_content_type', $set_content_type );
		remove_filter( 'wp_mail_from_name', $set_from_name );
		if ( ! empty( $settings['from_email'] ) ) {
			remove_filter( 'wp_mail_from', $set_from_email );
		}
		remove_action( 'phpmailer_init', $set_alt_body );
		remove_action( 'wp_mail_failed', $capture_error );

		if ( ! $sent ) {
			$reason = $error instanceof \WP_Error ? $error->get_error_message() : __( 'wp_mail() returned false.', 'service-status-manager' );
			return new SendResult( false, '', $reason );
		}

		return new SendResult( true, 'sent' );
	}

}
