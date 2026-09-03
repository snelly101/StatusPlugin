<?php
/**
 * Builds the shared branded HTML/plain-text email bodies, factored out of
 * EmailProvider so every email transport (wp_mail today, the SMTP2GO API
 * alongside it) renders an identical-looking message rather than each
 * provider growing its own copy of this markup that drifts out of sync.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EmailRenderer {

	/**
	 * Wraps a message body fragment in the shared branded email template.
	 *
	 * @param string $body_html Message-specific HTML.
	 * @param array  $message   Full message context.
	 * @return string
	 */
	public static function wrap_html( $body_html, array $message ) {
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
	public static function build_text_body( array $message ) {
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
