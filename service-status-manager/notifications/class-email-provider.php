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
		$body    = apply_filters( 'ssm_email_body', $this->wrap_html( $message['body_html'] ?? '', $message ), $message );
		$text    = apply_filters( 'ssm_email_alt_body', $this->build_text_body( $message ), $message );

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

	/**
	 * Wraps a message body fragment in the shared branded email template.
	 *
	 * @param string $body_html Message-specific HTML.
	 * @param array  $message   Full message context.
	 * @return string
	 */
	private function wrap_html( $body_html, array $message ) {
		$settings   = \ssm_get_settings();
		$logo       = apply_filters( 'ssm_email_logo_url', '' );
		$site_name  = get_bloginfo( 'name' );
		$footer     = sprintf(
			/* translators: %s: site name */
			__( 'You are receiving this email because you subscribed to status notifications from %s.', 'service-status-manager' ),
			$site_name
		);
		$manage_url = $message['manage_url'] ?? '';
		$unsub_url  = $message['unsubscribe_url'] ?? '';

		$is_incident_event = in_array( $message['event_type'] ?? '', array( 'incident_created', 'incident_updated', 'incident_resolved' ), true );
		$severity_colors    = array(
			'informational' => '#6b7280',
			'minor'         => '#eab308',
			'major'         => '#f97316',
			'critical'      => '#dc2626',
		);
		$severity       = $message['severity'] ?? '';
		$severity_color = $severity_colors[ $severity ] ?? '#2563eb';

		ob_start();
		?>
		<div style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #0f172a;">
			<?php if ( $logo ) : ?>
				<p style="text-align:center;"><img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $site_name ); ?>" style="max-height:48px;" /></p>
			<?php endif; ?>
			<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:24px;">
				<?php if ( ! empty( $message['notice_label'] ) ) : ?>
					<p style="margin:0 0 4px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#2563eb;"><?php echo esc_html( $message['notice_label'] ); ?></p>
				<?php endif; ?>
				<?php if ( ( $is_incident_event && $severity ) || ! empty( $message['status_label'] ) ) : ?>
					<p style="margin:0 0 16px;">
						<?php if ( $is_incident_event && $severity ) : ?>
							<span style="display:inline-block;background:<?php echo esc_attr( $severity_color ); ?>;color:#ffffff;font-size:12px;font-weight:600;padding:3px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.03em;"><?php echo esc_html( ucfirst( $severity ) ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $message['status_label'] ) ) : ?>
							<span style="display:inline-block;margin-left:8px;color:#475569;font-size:13px;font-weight:600;"><?php echo esc_html( $message['status_label'] ); ?></span>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $message['affected_services'] ) ) : ?>
					<p style="margin:0 0 8px;font-size:13px;color:#475569;"><strong><?php esc_html_e( 'Affected services:', 'service-status-manager' ); ?></strong> <?php echo esc_html( $message['affected_services'] ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $message['schedule_label'] ) ) : ?>
					<p style="margin:0 0 8px;font-size:13px;color:#475569;"><strong><?php esc_html_e( 'Scheduled window:', 'service-status-manager' ); ?></strong> <?php echo esc_html( $message['schedule_label'] ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $message['started_at'] ) ) : ?>
					<p style="margin:0 0 16px;font-size:13px;color:#475569;"><strong><?php esc_html_e( 'Started:', 'service-status-manager' ); ?></strong> <?php echo esc_html( $message['started_at'] ); ?></p>
				<?php endif; ?>

				<?php echo $body_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php if ( ! empty( $message['url'] ) ) : ?>
					<p style="margin:20px 0 0;">
						<a href="<?php echo esc_url( $message['url'] ); ?>" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;font-size:14px;font-weight:600;"><?php esc_html_e( 'View on status page', 'service-status-manager' ); ?></a>
					</p>
				<?php endif; ?>
			</div>
			<p style="font-size:12px;color:#64748b;margin-top:16px;">
				<?php echo esc_html( $footer ); ?>
				<?php if ( $manage_url ) : ?>
					<br /><a href="<?php echo esc_url( $manage_url ); ?>"><?php esc_html_e( 'Manage your subscription', 'service-status-manager' ); ?></a>
				<?php endif; ?>
				<?php if ( $unsub_url ) : ?>
					&nbsp;|&nbsp;<a href="<?php echo esc_url( $unsub_url ); ?>"><?php esc_html_e( 'Unsubscribe', 'service-status-manager' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Builds the plain-text alternative body, mirroring the detail shown
	 * in the HTML version (severity/status, affected services, schedule
	 * window, the message itself, a link, and the manage/unsubscribe
	 * links) for mail clients that display AltBody instead of HTML.
	 *
	 * @param array $message Message context.
	 * @return string
	 */
	private function build_text_body( array $message ) {
		$is_incident_event = in_array( $message['event_type'] ?? '', array( 'incident_created', 'incident_updated', 'incident_resolved' ), true );
		$lines = array();

		if ( ! empty( $message['notice_label'] ) ) {
			$lines[] = strtoupper( $message['notice_label'] );
		}

		$header_bits = array();
		if ( $is_incident_event && ! empty( $message['severity'] ) ) {
			$header_bits[] = strtoupper( $message['severity'] );
		}
		if ( ! empty( $message['status_label'] ) ) {
			$header_bits[] = $message['status_label'];
		}
		if ( $header_bits ) {
			$lines[] = implode( ' - ', $header_bits );
		}

		if ( ! empty( $message['affected_services'] ) ) {
			$lines[] = sprintf( '%s %s', __( 'Affected services:', 'service-status-manager' ), $message['affected_services'] );
		}
		if ( ! empty( $message['schedule_label'] ) ) {
			$lines[] = sprintf( '%s %s', __( 'Scheduled window:', 'service-status-manager' ), $message['schedule_label'] );
		}
		if ( ! empty( $message['started_at'] ) ) {
			$lines[] = sprintf( '%s %s', __( 'Started:', 'service-status-manager' ), $message['started_at'] );
		}

		if ( $lines ) {
			$lines[] = '';
		}

		$lines[] = $message['body_text'] ?? wp_strip_all_tags( $message['body_html'] ?? '' );

		if ( ! empty( $message['url'] ) ) {
			$lines[] = '';
			$lines[] = sprintf( '%s %s', __( 'View on status page:', 'service-status-manager' ), $message['url'] );
		}

		$lines[] = '';
		if ( ! empty( $message['manage_url'] ) ) {
			$lines[] = sprintf( '%s %s', __( 'Manage your subscription:', 'service-status-manager' ), $message['manage_url'] );
		}
		if ( ! empty( $message['unsubscribe_url'] ) ) {
			$lines[] = sprintf( '%s %s', __( 'Unsubscribe:', 'service-status-manager' ), $message['unsubscribe_url'] );
		}

		return implode( "\n", $lines );
	}
}
