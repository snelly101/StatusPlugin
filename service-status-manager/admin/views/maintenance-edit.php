<?php
/**
 * Admin view: create/edit a scheduled maintenance event.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\MaintenanceManager;
use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$maintenance_id = absint( $_GET['maintenance_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$event           = $maintenance_id ? MaintenanceManager::get_maintenance( $maintenance_id ) : null;
$services        = ServiceManager::get_services();
$notify_settings = $event ? ( json_decode( (string) $event->notify_settings, true ) ?: array() ) : array();
$selected_services = $maintenance_id ? wp_list_pluck( MaintenanceManager::get_services_for_maintenance( $maintenance_id ), 'id' ) : array();
?>
<div class="wrap ssm-wrap">
	<h1><?php echo $event ? esc_html__( 'Edit Maintenance', 'service-status-manager' ) : esc_html__( 'New Maintenance', 'service-status-manager' ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ssm_save_maintenance' ); ?>
		<input type="hidden" name="action" value="ssm_save_maintenance" />
		<input type="hidden" name="id" value="<?php echo esc_attr( $event->id ?? '' ); ?>" />

		<table class="form-table">
			<tr>
				<th><label for="ssm-mt-title"><?php esc_html_e( 'Title', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-mt-title" name="title" class="large-text" required value="<?php echo esc_attr( $event->title ?? '' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ssm-mt-desc"><?php esc_html_e( 'Description', 'service-status-manager' ); ?></label></th>
				<td><textarea id="ssm-mt-desc" name="description" rows="4" class="large-text"><?php echo esc_textarea( $event->description ?? '' ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="ssm-mt-start"><?php esc_html_e( 'Scheduled start', 'service-status-manager' ); ?></label></th>
				<td><input type="datetime-local" id="ssm-mt-start" name="scheduled_start" required value="<?php echo esc_attr( $event ? ssm_format_datetime( $event->scheduled_start, 'Y-m-d\TH:i' ) : '' ); ?>" />
				<p class="description"><?php echo esc_html( sprintf(
					/* translators: %s: site timezone */
					__( 'Times are entered in your site timezone (%s) and stored internally as UTC.', 'service-status-manager' ),
					wp_timezone_string()
				) ); ?></p></td>
			</tr>
			<tr>
				<th><label for="ssm-mt-end"><?php esc_html_e( 'Scheduled end', 'service-status-manager' ); ?></label></th>
				<td><input type="datetime-local" id="ssm-mt-end" name="scheduled_end" required value="<?php echo esc_attr( $event ? ssm_format_datetime( $event->scheduled_end, 'Y-m-d\TH:i' ) : '' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ssm-mt-impact"><?php esc_html_e( 'Expected impact', 'service-status-manager' ); ?></label></th>
				<td>
					<select id="ssm-mt-impact" name="impact">
						<?php foreach ( MaintenanceManager::IMPACTS as $impact ) : ?>
							<option value="<?php echo esc_attr( $impact ); ?>" <?php selected( $event->impact ?? 'none', $impact ); ?>><?php echo esc_html( ucfirst( $impact ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Affected services', 'service-status-manager' ); ?></th>
				<td>
					<?php foreach ( $services as $service ) : ?>
						<label class="ssm-checkbox-row-admin">
							<input type="checkbox" name="service_ids[]" value="<?php echo esc_attr( $service->id ); ?>" <?php checked( in_array( (int) $service->id, array_map( 'intval', $selected_services ), true ) ); ?> />
							<?php echo esc_html( $service->name ); ?>
						</label><br />
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Notifications', 'service-status-manager' ); ?></th>
				<td>
					<label><input type="checkbox" name="notify_on_announce" value="1" <?php checked( $notify_settings['on_announce'] ?? true, 1 ); ?> /> <?php esc_html_e( 'When announced', 'service-status-manager' ); ?></label><br />
					<label><input type="checkbox" name="notify_on_start" value="1" <?php checked( $notify_settings['on_start'] ?? true, 1 ); ?> /> <?php esc_html_e( 'When it begins', 'service-status-manager' ); ?></label><br />
					<label><input type="checkbox" name="notify_on_complete" value="1" <?php checked( $notify_settings['on_complete'] ?? true, 1 ); ?> /> <?php esc_html_e( 'When completed', 'service-status-manager' ); ?></label><br />
					<label><input type="checkbox" name="notify_on_extend" value="1" <?php checked( $notify_settings['on_extend'] ?? true, 1 ); ?> /> <?php esc_html_e( 'When extended', 'service-status-manager' ); ?></label><br />
					<label><input type="checkbox" name="notify_on_cancel" value="1" <?php checked( $notify_settings['on_cancel'] ?? true, 1 ); ?> /> <?php esc_html_e( 'When cancelled', 'service-status-manager' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="ssm-mt-reminders"><?php esc_html_e( 'Reminder lead times (hours before start)', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-mt-reminders" name="reminder_hours_text" value="<?php echo esc_attr( implode( ', ', $notify_settings['reminder_hours'] ?? array( 24, 1 ) ) ); ?>" onchange="ssmSyncReminderHours(this.value)" />
				<div id="ssm-reminder-hidden"></div>
				<p class="description"><?php esc_html_e( 'Comma-separated list, e.g. "24, 1" sends a reminder 24 hours and again 1 hour before the start.', 'service-status-manager' ); ?></p></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Visibility', 'service-status-manager' ); ?></th>
				<td><label><input type="checkbox" name="is_public" value="1" <?php checked( $event->is_public ?? 1, 1 ); ?> /> <?php esc_html_e( 'Show on public status page', 'service-status-manager' ); ?></label></td>
			</tr>
		</table>

		<?php submit_button( $event ? __( 'Save Maintenance', 'service-status-manager' ) : __( 'Schedule Maintenance', 'service-status-manager' ) ); ?>
	</form>
</div>
<script>
	function ssmSyncReminderHours( value ) {
		var wrap = document.getElementById( 'ssm-reminder-hidden' );
		wrap.innerHTML = '';
		value.split( ',' ).forEach( function ( part ) {
			part = parseInt( part.trim(), 10 );
			if ( ! isNaN( part ) && part > 0 ) {
				var input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = 'reminder_hours[]';
				input.value = part;
				wrap.appendChild( input );
			}
		} );
	}
	document.addEventListener( 'DOMContentLoaded', function () {
		var field = document.getElementById( 'ssm-mt-reminders' );
		ssmSyncReminderHours( field.value );
	} );
</script>
