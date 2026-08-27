<?php
/**
 * Template: the "Get status updates" modal - a 3-step guided flow
 * (channels -> what to follow -> destination + consent) that submits to
 * exactly the same admin-post handler and field names as the standalone
 * [service_status_subscribe] form (see SubscriberManager::
 * handle_public_subscription()). JavaScript only changes how the fields
 * are *presented* (one step at a time, with client-side validation
 * between steps); the server-side contract, and the no-JS fallback
 * rendered as a plain form via <noscript> next to the trigger button,
 * are unchanged.
 *
 * @package ServiceStatusManager
 */

use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\MonitorManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$groups   = ServiceManager::get_groups();
$services = ServiceManager::get_services( array( 'show_on_status_page' => 1 ) );
$settings = ssm_get_settings();
?>
<div class="ssm-modal-overlay" id="ssm-subscribe-modal" data-ssm-modal aria-hidden="true">
	<div class="ssm-modal" role="dialog" aria-modal="true" aria-labelledby="ssm-modal-title">
		<button type="button" class="ssm-modal-close" data-ssm-close-modal aria-label="<?php esc_attr_e( 'Close', 'service-status-manager' ); ?>">
			<?php echo ssm_icon( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>

		<div data-ssm-wizard>
			<div class="ssm-modal-header">
				<p class="ssm-modal-eyebrow"><?php esc_html_e( 'Get status updates', 'service-status-manager' ); ?></p>
				<h2 class="ssm-modal-title" id="ssm-modal-title"><?php esc_html_e( 'How would you like to be notified?', 'service-status-manager' ); ?></h2>
			</div>

			<div class="ssm-step-progress">
				<span class="ssm-is-active" data-ssm-progress="1"></span>
				<span data-ssm-progress="2"></span>
				<span data-ssm-progress="3"></span>
			</div>

			<div class="ssm-notice ssm-notice-error" data-ssm-wizard-error hidden></div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ssm-subscribe-form novalidate>
				<?php wp_nonce_field( 'ssm_public_subscribe' ); ?>
				<input type="hidden" name="action" value="ssm_public_subscribe" />
				<input type="hidden" name="ssm_ajax" value="1" />

				<!-- Step 1: channels -->
				<section class="ssm-step ssm-is-active" data-step="1">
					<div class="ssm-choice-cards">
						<label class="ssm-choice-card">
							<input type="checkbox" name="channels[]" value="email" data-ssm-channel="email" />
							<?php echo ssm_icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php esc_html_e( 'Email', 'service-status-manager' ); ?>
						</label>
						<label class="ssm-choice-card">
							<input type="checkbox" name="channels[]" value="teams" data-ssm-channel="teams" />
							<?php echo ssm_icon( 'message-square' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php esc_html_e( 'Microsoft Teams', 'service-status-manager' ); ?>
						</label>
						<label class="ssm-choice-card">
							<input type="checkbox" name="channels[]" value="sms" data-ssm-channel="sms" />
							<?php echo ssm_icon( 'smartphone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php esc_html_e( 'SMS', 'service-status-manager' ); ?>
						</label>
					</div>
					<p class="ssm-uptime-range-label"><?php esc_html_e( 'Select one or more. You can combine channels.', 'service-status-manager' ); ?></p>
				</section>

				<!-- Step 2: what to follow -->
				<section class="ssm-step" data-step="2">
					<div class="ssm-form-row">
						<label class="ssm-checkbox-row">
							<input type="checkbox" data-ssm-select-all />
							<strong><?php esc_html_e( 'Everything', 'service-status-manager' ); ?></strong>
						</label>
					</div>

					<?php if ( ! empty( $groups ) ) : ?>
						<p class="ssm-selection-heading"><?php esc_html_e( 'Service groups', 'service-status-manager' ); ?></p>
						<?php foreach ( $groups as $group ) : ?>
							<label class="ssm-checkbox-row">
								<input type="checkbox" name="groups[]" value="<?php echo esc_attr( $group->id ); ?>" data-ssm-selectable />
								<?php echo esc_html( $group->name ); ?>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>

					<p class="ssm-selection-heading"><?php esc_html_e( 'Individual services', 'service-status-manager' ); ?></p>
					<?php foreach ( $services as $service ) : if ( ! $service->allow_subscriptions ) { continue; } ?>
						<label class="ssm-checkbox-row">
							<input type="checkbox" name="services[]" value="<?php echo esc_attr( $service->id ); ?>" data-ssm-selectable />
							<?php echo esc_html( $service->name ); ?>
						</label>
						<?php foreach ( MonitorManager::get_monitors_for_service( $service->id ) as $monitor ) : if ( ! $monitor->is_public ) { continue; } ?>
							<label class="ssm-checkbox-row ssm-checkbox-row--indent">
								<input type="checkbox" name="monitors[]" value="<?php echo esc_attr( $monitor->id ); ?>" data-ssm-selectable />
								<?php echo esc_html( $monitor->name ); ?>
							</label>
						<?php endforeach; ?>
					<?php endforeach; ?>

					<div class="ssm-form-row" style="margin-top:16px;">
						<label for="ssm-modal-severity"><?php esc_html_e( 'Minimum incident severity', 'service-status-manager' ); ?></label>
						<select id="ssm-modal-severity" name="min_severity">
							<option value="informational"><?php esc_html_e( 'All updates', 'service-status-manager' ); ?></option>
							<option value="minor"><?php esc_html_e( 'Minor and above', 'service-status-manager' ); ?></option>
							<option value="major"><?php esc_html_e( 'Major and above', 'service-status-manager' ); ?></option>
							<option value="critical"><?php esc_html_e( 'Critical only', 'service-status-manager' ); ?></option>
						</select>
					</div>
					<label class="ssm-checkbox-row">
						<input type="checkbox" name="maintenance_notifications" value="1" checked />
						<?php esc_html_e( 'Also notify me about scheduled maintenance', 'service-status-manager' ); ?>
					</label>
				</section>

				<!-- Step 3: destination + consent -->
				<section class="ssm-step" data-step="3">
					<div class="ssm-form-row">
						<label for="ssm-modal-name"><?php esc_html_e( 'Name (optional)', 'service-status-manager' ); ?></label>
						<input type="text" id="ssm-modal-name" name="name" autocomplete="name" />
					</div>
					<div class="ssm-form-row" data-ssm-destination="email" hidden>
						<label for="ssm-modal-email"><?php esc_html_e( 'Email address', 'service-status-manager' ); ?></label>
						<input type="email" id="ssm-modal-email" name="email" autocomplete="email" placeholder="you@example.com" />
					</div>
					<div class="ssm-form-row" data-ssm-destination="teams" hidden>
						<label for="ssm-modal-teams"><?php esc_html_e( 'Microsoft Teams webhook URL', 'service-status-manager' ); ?></label>
						<input type="url" id="ssm-modal-teams" name="teams_webhook" placeholder="https://...webhook.office.com/..." />
					</div>
					<div class="ssm-form-row" data-ssm-destination="sms" hidden>
						<label for="ssm-modal-phone"><?php esc_html_e( 'Mobile number', 'service-status-manager' ); ?></label>
						<input type="tel" id="ssm-modal-phone" name="phone" autocomplete="tel" placeholder="+44 7700 900000" />
					</div>

					<div class="ssm-privacy-notice">
						<p><?php echo wp_kses_post( $settings['privacy_notice'] ); ?></p>
						<label class="ssm-checkbox-row">
							<input type="checkbox" name="consent" value="1" required />
							<?php esc_html_e( 'I consent to receive these notifications and agree to the privacy notice above.', 'service-status-manager' ); ?>
						</label>
					</div>

					<input type="hidden" name="consent_version" value="<?php echo esc_attr( $settings['consent_wording_version'] ); ?>" />
					<input type="hidden" name="consent_source" value="<?php echo esc_url( ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' ) ); ?>" />
					<div class="ssm-honeypot" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off" /></div>
				</section>

				<div class="ssm-wizard-nav">
					<button type="button" class="ssm-button ssm-button-ghost" data-ssm-back hidden><?php esc_html_e( 'Back', 'service-status-manager' ); ?></button>
					<button type="button" class="ssm-button ssm-button-primary" data-ssm-next><?php esc_html_e( 'Next', 'service-status-manager' ); ?></button>
					<button type="submit" class="ssm-button ssm-button-primary" data-ssm-submit hidden><?php esc_html_e( 'Subscribe', 'service-status-manager' ); ?></button>
				</div>
			</form>

			<p class="ssm-manage-link">
				<?php esc_html_e( 'Already subscribed?', 'service-status-manager' ); ?>
				<a href="#ssm-modal-resend" data-ssm-toggle="resend"><?php esc_html_e( 'Resend my confirmation or management link', 'service-status-manager' ); ?></a>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ssm-modal-resend" class="ssm-resend-form" hidden>
				<?php wp_nonce_field( 'ssm_resend_confirmation' ); ?>
				<input type="hidden" name="action" value="ssm_resend_confirmation" />
				<label for="ssm-modal-resend-email" class="screen-reader-text"><?php esc_html_e( 'Email address', 'service-status-manager' ); ?></label>
				<input type="email" id="ssm-modal-resend-email" name="email" placeholder="<?php esc_attr_e( 'you@example.com', 'service-status-manager' ); ?>" required />
				<button type="submit" class="ssm-button"><?php esc_html_e( 'Send link', 'service-status-manager' ); ?></button>
			</form>
		</div>

		<div class="ssm-confirmation" data-ssm-confirmation hidden>
			<div class="ssm-confirmation-icon"><?php echo ssm_icon( 'check-circle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<h3><?php esc_html_e( 'Almost done', 'service-status-manager' ); ?></h3>
			<p data-ssm-confirmation-message><?php esc_html_e( 'Thank you - please check your inbox (or other selected channel) to confirm your subscription.', 'service-status-manager' ); ?></p>
			<button type="button" class="ssm-button" data-ssm-close-modal style="margin-top:16px;"><?php esc_html_e( 'Close', 'service-status-manager' ); ?></button>
		</div>
	</div>
</div>
