<?php
/**
 * Admin view: manage services.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\MonitorManager;
use ServiceStatusManager\UptimeAggregator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$services = ServiceManager::get_services();
$groups   = ServiceManager::get_groups();
$edit_id  = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$editing  = $edit_id ? ServiceManager::get_service( $edit_id ) : null;
$statuses = ssm_get_status_definitions();

global $wpdb;
$selections_table = ssm_table( 'subscriber_selections' );
?>
<div class="wrap ssm-wrap">
	<h1><?php esc_html_e( 'Services', 'service-status-manager' ); ?></h1>

	<table class="wp-list-table widefat fixed striped">
		<thead><tr>
			<th><?php esc_html_e( 'Name', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Group', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Status', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Mode', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Monitors', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Uptime (90d)', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Subscribers', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Last updated', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'service-status-manager' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $services ) ) : ?>
			<tr><td colspan="9"><?php esc_html_e( 'No services yet.', 'service-status-manager' ); ?></td></tr>
		<?php endif; ?>
		<?php
		$group_names = wp_list_pluck( $groups, 'name', 'id' );
		foreach ( $services as $service ) :
			$status_def    = ssm_get_status_definition( $service->status );
			$monitor_count = count( MonitorManager::get_monitors_for_service( $service->id ) );
			$uptime        = UptimeAggregator::get_service_uptime_percentage( $service->id, 90 );
			$subscriber_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT subscriber_id) FROM {$selections_table} WHERE scope_type = 'service' AND scope_id = %d", $service->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			?>
			<tr>
				<td><a href="<?php echo esc_url( add_query_arg( 'edit', $service->id ) ); ?>"><strong><?php echo esc_html( $service->name ); ?></strong></a></td>
				<td><?php echo esc_html( $group_names[ $service->group_id ] ?? '—' ); ?></td>
				<td><span class="ssm-badge <?php echo esc_attr( $status_def['css_class'] ); ?>"><?php echo esc_html( $status_def['label'] ); ?></span></td>
				<td><?php echo esc_html( ucfirst( $service->status_mode ) ); ?></td>
				<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=service-status-manager-monitors&service_id=' . $service->id ) ); ?>"><?php echo esc_html( $monitor_count ); ?></a></td>
				<td><?php echo esc_html( number_format_i18n( $uptime, 2 ) ); ?>%</td>
				<td><?php echo esc_html( $subscriber_count ); ?></td>
				<td><?php echo esc_html( ssm_format_datetime( $service->updated_at ) ); ?></td>
				<td class="ssm-quick-actions">
					<a href="<?php echo esc_url( add_query_arg( 'edit', $service->id ) ); ?>"><?php esc_html_e( 'Edit', 'service-status-manager' ); ?></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=service-status-manager-incidents&action=new' ) ); ?>"><?php esc_html_e( 'Incident', 'service-status-manager' ); ?></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=service-status-manager-maintenance&action=new' ) ); ?>"><?php esc_html_e( 'Maintenance', 'service-status-manager' ); ?></a>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this service and all its monitors?', 'service-status-manager' ) ); ?>');">
						<?php wp_nonce_field( 'ssm_delete_service' ); ?>
						<input type="hidden" name="action" value="ssm_delete_service" />
						<input type="hidden" name="id" value="<?php echo esc_attr( $service->id ); ?>" />
						<button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'service-status-manager' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php echo $editing ? esc_html__( 'Edit Service', 'service-status-manager' ) : esc_html__( 'Add Service', 'service-status-manager' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ssm_save_service' ); ?>
		<input type="hidden" name="action" value="ssm_save_service" />
		<input type="hidden" name="id" value="<?php echo esc_attr( $editing->id ?? '' ); ?>" />

		<table class="form-table">
			<tr>
				<th><label for="ssm-name"><?php esc_html_e( 'Name', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-name" name="name" class="regular-text" required value="<?php echo esc_attr( $editing->name ?? '' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ssm-slug"><?php esc_html_e( 'Slug', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-slug" name="slug" class="regular-text" value="<?php echo esc_attr( $editing->slug ?? '' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ssm-group"><?php esc_html_e( 'Service group', 'service-status-manager' ); ?></label></th>
				<td>
					<select id="ssm-group" name="group_id">
						<option value=""><?php esc_html_e( '— None —', 'service-status-manager' ); ?></option>
						<?php foreach ( $groups as $group ) : ?>
							<option value="<?php echo esc_attr( $group->id ); ?>" <?php selected( $editing->group_id ?? '', $group->id ); ?>><?php echo esc_html( $group->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="ssm-description"><?php esc_html_e( 'Description', 'service-status-manager' ); ?></label></th>
				<td><textarea id="ssm-description" name="description" rows="3" class="large-text"><?php echo esc_textarea( $editing->description ?? '' ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="ssm-icon"><?php esc_html_e( 'Icon (dashicon class)', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-icon" name="icon" class="regular-text" value="<?php echo esc_attr( $editing->icon ?? '' ); ?>" placeholder="dashicons-cloud" /></td>
			</tr>
			<tr>
				<th><label for="ssm-image"><?php esc_html_e( 'Image URL (optional)', 'service-status-manager' ); ?></label></th>
				<td><input type="url" id="ssm-image" name="image_url" class="regular-text" value="<?php echo esc_attr( $editing->image_url ?? '' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ssm-status-mode"><?php esc_html_e( 'Status mode', 'service-status-manager' ); ?></label></th>
				<td>
					<select id="ssm-status-mode" name="status_mode">
						<option value="automatic" <?php selected( $editing->status_mode ?? 'automatic', 'automatic' ); ?>><?php esc_html_e( 'Automatic (from monitors)', 'service-status-manager' ); ?></option>
						<option value="manual" <?php selected( $editing->status_mode ?? '', 'manual' ); ?>><?php esc_html_e( 'Manual', 'service-status-manager' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="ssm-status"><?php esc_html_e( 'Current status (manual mode)', 'service-status-manager' ); ?></label></th>
				<td>
					<select id="ssm-status" name="status">
						<?php foreach ( $statuses as $slug => $def ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $editing->status ?? 'operational', $slug ); ?>><?php echo esc_html( $def['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="ssm-visibility"><?php esc_html_e( 'Visibility', 'service-status-manager' ); ?></label></th>
				<td>
					<select id="ssm-visibility" name="visibility">
						<option value="public" <?php selected( $editing->visibility ?? 'public', 'public' ); ?>><?php esc_html_e( 'Public', 'service-status-manager' ); ?></option>
						<option value="private" <?php selected( $editing->visibility ?? '', 'private' ); ?>><?php esc_html_e( 'Private', 'service-status-manager' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="ssm-order"><?php esc_html_e( 'Display order', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-order" name="display_order" value="<?php echo esc_attr( $editing->display_order ?? 0 ); ?>" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Options', 'service-status-manager' ); ?></th>
				<td>
					<label><input type="checkbox" name="allow_subscriptions" value="1" <?php checked( $editing->allow_subscriptions ?? 1, 1 ); ?> /> <?php esc_html_e( 'Allow subscriptions to this service', 'service-status-manager' ); ?></label><br />
					<label><input type="checkbox" name="show_on_status_page" value="1" <?php checked( $editing->show_on_status_page ?? 1, 1 ); ?> /> <?php esc_html_e( 'Show on the public status page', 'service-status-manager' ); ?></label><br />
					<label><input type="checkbox" name="exclude_from_overall" value="1" <?php checked( $editing->exclude_from_overall ?? 0, 1 ); ?> /> <?php esc_html_e( 'Exclude from overall status calculation', 'service-status-manager' ); ?></label>
				</td>
			</tr>
		</table>

		<?php submit_button( $editing ? __( 'Update Service', 'service-status-manager' ) : __( 'Add Service', 'service-status-manager' ) ); ?>
	</form>
</div>
