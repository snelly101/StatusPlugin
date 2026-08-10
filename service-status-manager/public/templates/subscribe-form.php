<?php
/**
 * Template: public subscription form.
 *
 * Expects: $groups, $services (arrays), $notice (string), $atts.
 *
 * @package ServiceStatusManager
 */

use ServiceStatusManager\MonitorManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = ssm_get_settings();
$show_monitor_selection = 'yes' === $atts['show_subscribe'];
?>
<div class="ssm-subscribe">
	<?php if ( $notice ) : ?>
		<div class="ssm-notice" role="status"><?php echo esc_html( $notice ); ?></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ssm-subscribe-form" novalidate>
		<?php wp_nonce_field( 'ssm_public_subscribe' ); ?>
		<input type="hidden" name="action" value="ssm_public_subscribe" />

		<div class="ssm-form-row">
			<label for="ssm-sub-name"><?php esc_html_e( 'Name (optional)', 'service-status-manager' ); ?></label>
			<input type="text" id="ssm-sub-name" name="name" autocomplete="name" />
		</div>

		<fieldset class="ssm-form-row">
			<legend><?php esc_html_e( 'Notification channels', 'service-status-manager' ); ?></legend>

			<label class="ssm-checkbox-row">
				<input type="checkbox" name="channels[]" value="email" checked />
				<?php esc_html_e( 'Email', 'service-status-manager' ); ?>
			</label>
			<input type="email" id="ssm-sub-email" name="email" autocomplete="email" placeholder="<?php esc_attr_e( 'you@example.com', 'service-status-manager' ); ?>" />

			<label class="ssm-checkbox-row">
				<input type="checkbox" name="channels[]" value="sms" />
				<?php esc_html_e( 'SMS text message', 'service-status-manager' ); ?>
			</label>
			<input type="tel" id="ssm-sub-phone" name="phone" autocomplete="tel" placeholder="<?php esc_attr_e( '+44 7700 900000', 'service-status-manager' ); ?>" />

			<label class="ssm-checkbox-row">
				<input type="checkbox" name="channels[]" value="teams" />
				<?php esc_html_e( 'Microsoft Teams', 'service-status-manager' ); ?>
			</label>
			<input type="url" id="ssm-sub-teams" name="teams_webhook" placeholder="<?php esc_attr_e( 'https://...webhook.office.com/...', 'service-status-manager' ); ?>" />
			<p class="description"><?php esc_html_e( 'Paste an Incoming Webhook URL for the Teams channel that should receive alerts. This is never shown publicly once saved.', 'service-status-manager' ); ?></p>
		</fieldset>

		<?php if ( $show_monitor_selection ) : ?>
			<fieldset class="ssm-form-row">
				<legend><?php esc_html_e( 'What would you like to be notified about?', 'service-status-manager' ); ?></legend>

				<?php if ( ! empty( $groups ) ) : ?>
					<p class="ssm-selection-heading"><?php esc_html_e( 'Service groups', 'service-status-manager' ); ?></p>
					<?php foreach ( $groups as $group ) : ?>
						<label class="ssm-checkbox-row">
							<input type="checkbox" name="groups[]" value="<?php echo esc_attr( $group->id ); ?>" />
							<?php echo esc_html( $group->name ); ?>
						</label>
					<?php endforeach; ?>
				<?php endif; ?>

				<p class="ssm-selection-heading"><?php esc_html_e( 'Individual services', 'service-status-manager' ); ?></p>
				<?php foreach ( $services as $service ) : if ( ! $service->allow_subscriptions ) { continue; } ?>
					<label class="ssm-checkbox-row">
						<input type="checkbox" name="services[]" value="<?php echo esc_attr( $service->id ); ?>" />
						<?php echo esc_html( $service->name ); ?>
					</label>
					<?php foreach ( MonitorManager::get_monitors_for_service( $service->id ) as $monitor ) : if ( ! $monitor->is_public ) { continue; } ?>
						<label class="ssm-checkbox-row ssm-checkbox-row--indent">
							<input type="checkbox" name="monitors[]" value="<?php echo esc_attr( $monitor->id ); ?>" />
							<?php echo esc_html( $monitor->name ); ?>
						</label>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</fieldset>

			<div class="ssm-form-row">
				<label for="ssm-sub-severity"><?php esc_html_e( 'Minimum incident severity', 'service-status-manager' ); ?></label>
				<select id="ssm-sub-severity" name="min_severity">
					<option value="informational"><?php esc_html_e( 'All updates (informational and above)', 'service-status-manager' ); ?></option>
					<option value="minor"><?php esc_html_e( 'Minor and above', 'service-status-manager' ); ?></option>
					<option value="major"><?php esc_html_e( 'Major and above', 'service-status-manager' ); ?></option>
					<option value="critical"><?php esc_html_e( 'Critical only', 'service-status-manager' ); ?></option>
				</select>
			</div>

			<div class="ssm-form-row">
				<label class="ssm-checkbox-row">
					<input type="checkbox" name="maintenance_notifications" value="1" checked />
					<?php esc_html_e( 'Also notify me about scheduled maintenance', 'service-status-manager' ); ?>
				</label>
			</div>
		<?php endif; ?>

		<div class="ssm-form-row ssm-privacy-notice">
			<p><?php echo wp_kses_post( $settings['privacy_notice'] ); ?></p>
			<label class="ssm-checkbox-row">
				<input type="checkbox" name="consent" value="1" required />
				<?php esc_html_e( 'I consent to receive these notifications and agree to the privacy notice above.', 'service-status-manager' ); ?>
			</label>
		</div>

		<input type="hidden" name="consent_version" value="<?php echo esc_attr( $settings['consent_wording_version'] ); ?>" />
		<input type="hidden" name="consent_source" value="<?php echo esc_url( ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' ) ); ?>" />
		<?php // A honeypot field for basic bot mitigation; real subscribers never fill it in. ?>
		<div class="ssm-honeypot" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off" /></div>

		<button type="submit" class="ssm-button ssm-button-primary"><?php esc_html_e( 'Subscribe', 'service-status-manager' ); ?></button>
	</form>

	<p class="ssm-manage-link">
		<?php esc_html_e( 'Already subscribed?', 'service-status-manager' ); ?>
		<a href="#ssm-resend" data-ssm-toggle="resend"><?php esc_html_e( 'Resend my confirmation or management link', 'service-status-manager' ); ?></a>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ssm-resend" class="ssm-resend-form" hidden>
		<?php wp_nonce_field( 'ssm_resend_confirmation' ); ?>
		<input type="hidden" name="action" value="ssm_resend_confirmation" />
		<label for="ssm-resend-email" class="screen-reader-text"><?php esc_html_e( 'Email address', 'service-status-manager' ); ?></label>
		<input type="email" id="ssm-resend-email" name="email" placeholder="<?php esc_attr_e( 'you@example.com', 'service-status-manager' ); ?>" required />
		<button type="submit" class="ssm-button"><?php esc_html_e( 'Send link', 'service-status-manager' ); ?></button>
	</form>
</div>
