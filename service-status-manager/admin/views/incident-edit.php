<?php
/**
 * Admin view: create/edit an incident and manage its update timeline.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\IncidentManager;
use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\MonitorManager;
use ServiceStatusManager\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$incident_id = absint( $_GET['incident_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$incident    = $incident_id ? IncidentManager::get_incident( $incident_id ) : null;
$services    = ServiceManager::get_services();
$can_manage  = current_user_can( Capabilities::MANAGE_INCIDENTS );

$selected_services = $incident_id ? wp_list_pluck( IncidentManager::get_services_for_incident( $incident_id ), 'id' ) : array();
$selected_monitors  = $incident_id ? wp_list_pluck( IncidentManager::get_monitors_for_incident( $incident_id ), 'id' ) : array();
?>
<div class="wrap ssm-wrap">
	<h1><?php echo $incident ? esc_html__( 'Edit Incident', 'service-status-manager' ) : esc_html__( 'New Incident', 'service-status-manager' ); ?></h1>

	<?php if ( $can_manage ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ssm_save_incident' ); ?>
		<input type="hidden" name="action" value="ssm_save_incident" />
		<input type="hidden" name="id" value="<?php echo esc_attr( $incident->id ?? '' ); ?>" />

		<table class="form-table">
			<tr>
				<th><label for="ssm-i-title"><?php esc_html_e( 'Title', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-i-title" name="title" class="large-text" required value="<?php echo esc_attr( $incident->title ?? '' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ssm-i-desc"><?php esc_html_e( 'Public description', 'service-status-manager' ); ?></label></th>
				<td><textarea id="ssm-i-desc" name="description" rows="4" class="large-text"><?php echo esc_textarea( $incident->description ?? '' ); ?></textarea></td>
			</tr>
			<?php if ( ! $incident ) : ?>
			<tr>
				<th><label for="ssm-i-initial"><?php esc_html_e( 'Initial update message', 'service-status-manager' ); ?></label></th>
				<td><textarea id="ssm-i-initial" name="initial_message" rows="3" class="large-text"></textarea>
				<p class="description"><?php esc_html_e( 'Shown as the first entry in the public timeline. Leave blank to reuse the description above.', 'service-status-manager' ); ?></p></td>
			</tr>
			<?php endif; ?>
			<tr>
				<th><label for="ssm-i-internal"><?php esc_html_e( 'Internal notes', 'service-status-manager' ); ?></label></th>
				<td><textarea id="ssm-i-internal" name="internal_notes" rows="3" class="large-text"><?php echo esc_textarea( $incident->internal_notes ?? '' ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Never shown publicly, in feeds, or through the API.', 'service-status-manager' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="ssm-i-severity"><?php esc_html_e( 'Severity', 'service-status-manager' ); ?></label></th>
				<td>
					<select id="ssm-i-severity" name="severity">
						<?php foreach ( IncidentManager::SEVERITIES as $level ) : ?>
							<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $incident->severity ?? 'minor', $level ); ?>><?php echo esc_html( ucfirst( $level ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<?php if ( ! $incident ) : ?>
			<tr>
				<th><label for="ssm-i-status"><?php esc_html_e( 'Initial status', 'service-status-manager' ); ?></label></th>
				<td>
					<select id="ssm-i-status" name="status">
						<?php foreach ( IncidentManager::STATUSES as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( ucfirst( $s ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="ssm-i-starts"><?php esc_html_e( 'Start time', 'service-status-manager' ); ?></label></th>
				<td><input type="datetime-local" id="ssm-i-starts" name="starts_at" value="<?php echo esc_attr( gmdate( 'Y-m-d\TH:i' ) ); ?>" /></td>
			</tr>
			<?php endif; ?>
			<tr>
				<th><label for="ssm-i-publish"><?php esc_html_e( 'Scheduled publish time (optional)', 'service-status-manager' ); ?></label></th>
				<td><input type="datetime-local" id="ssm-i-publish" name="scheduled_publish_at" value="" />
				<p class="description"><?php esc_html_e( 'Leave blank to publish immediately.', 'service-status-manager' ); ?></p></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Affected services', 'service-status-manager' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'At least one service is required.', 'service-status-manager' ); ?></p>
					<?php foreach ( $services as $service ) : ?>
						<label class="ssm-checkbox-row-admin">
							<input type="checkbox" name="service_ids[]" value="<?php echo esc_attr( $service->id ); ?>" <?php checked( in_array( (int) $service->id, array_map( 'intval', $selected_services ), true ) ); ?> />
							<?php echo esc_html( $service->name ); ?>
						</label>
						<?php foreach ( MonitorManager::get_monitors_for_service( $service->id ) as $monitor ) : ?>
							<label class="ssm-checkbox-row-admin" style="margin-left:20px;">
								<input type="checkbox" name="monitor_ids[]" value="<?php echo esc_attr( $monitor->id ); ?>" <?php checked( in_array( (int) $monitor->id, array_map( 'intval', $selected_monitors ), true ) ); ?> />
								<?php echo esc_html( $monitor->name ); ?>
							</label>
						<?php endforeach; ?>
						<br />
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Options', 'service-status-manager' ); ?></th>
				<td>
					<label><input type="checkbox" name="is_public" value="1" <?php checked( $incident->is_public ?? 1, 1 ); ?> /> <?php esc_html_e( 'Visible on public status page', 'service-status-manager' ); ?></label><br />
					<label><input type="checkbox" name="notify" value="1" <?php checked( $incident->notify ?? 1, 1 ); ?> /> <?php esc_html_e( 'Send notifications to subscribers', 'service-status-manager' ); ?></label><br />
					<label><input type="checkbox" name="is_pinned" value="1" <?php checked( $incident->is_pinned ?? 0, 1 ); ?> /> <?php esc_html_e( 'Pin to top of status page', 'service-status-manager' ); ?></label>
				</td>
			</tr>
			<?php if ( $incident ) : ?>
			<tr>
				<th><label for="ssm-i-root"><?php esc_html_e( 'Root cause', 'service-status-manager' ); ?></label></th>
				<td><textarea id="ssm-i-root" name="root_cause" rows="3" class="large-text"><?php echo esc_textarea( $incident->root_cause ?? '' ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="ssm-i-resolution"><?php esc_html_e( 'Resolution', 'service-status-manager' ); ?></label></th>
				<td><textarea id="ssm-i-resolution" name="resolution" rows="3" class="large-text"><?php echo esc_textarea( $incident->resolution ?? '' ); ?></textarea></td>
			</tr>
			<?php endif; ?>
		</table>

		<?php submit_button( $incident ? __( 'Save Incident', 'service-status-manager' ) : __( 'Create Incident', 'service-status-manager' ) ); ?>
	</form>
	<?php endif; ?>

	<?php if ( $incident ) : ?>
		<h2><?php esc_html_e( 'Timeline', 'service-status-manager' ); ?></h2>
		<table class="wp-list-table widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Date', 'service-status-manager' ); ?></th>
				<th><?php esc_html_e( 'Status', 'service-status-manager' ); ?></th>
				<th><?php esc_html_e( 'Message', 'service-status-manager' ); ?></th>
				<th><?php esc_html_e( 'Author', 'service-status-manager' ); ?></th>
				<th><?php esc_html_e( 'Visibility', 'service-status-manager' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( IncidentManager::get_all_updates( $incident->id ) as $update ) : ?>
				<tr>
					<td><?php echo esc_html( ssm_format_datetime( $update->created_at ) ); ?></td>
					<td><?php echo esc_html( ucfirst( $update->status ) ); ?></td>
					<td><?php echo wp_kses_post( $update->message ); ?></td>
					<td><?php echo esc_html( $update->author_name ); ?></td>
					<td><?php echo $update->is_internal ? esc_html__( 'Internal only', 'service-status-manager' ) : esc_html__( 'Public', 'service-status-manager' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( current_user_can( Capabilities::EDIT_UPDATES ) ) : ?>
			<h3><?php esc_html_e( 'Add update', 'service-status-manager' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ssm_add_incident_update' ); ?>
				<input type="hidden" name="action" value="ssm_add_incident_update" />
				<input type="hidden" name="incident_id" value="<?php echo esc_attr( $incident->id ); ?>" />

				<table class="form-table">
					<tr>
						<th><label for="ssm-u-status"><?php esc_html_e( 'Status', 'service-status-manager' ); ?></label></th>
						<td>
							<select id="ssm-u-status" name="status">
								<?php foreach ( IncidentManager::STATUSES as $s ) : ?>
									<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $incident->status, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ssm-u-message"><?php esc_html_e( 'Message', 'service-status-manager' ); ?></label></th>
						<td><textarea id="ssm-u-message" name="message" rows="3" class="large-text" required></textarea></td>
					</tr>
					<?php if ( $can_manage ) : ?>
					<tr>
						<th><?php esc_html_e( 'Visibility', 'service-status-manager' ); ?></th>
						<td><label><input type="checkbox" name="is_internal" value="1" /> <?php esc_html_e( 'Internal note only (never shown publicly)', 'service-status-manager' ); ?></label></td>
					</tr>
					<?php endif; ?>
				</table>

				<?php submit_button( __( 'Add Update', 'service-status-manager' ) ); ?>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</div>
