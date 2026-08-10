<?php
/**
 * Admin view: manage service groups.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\ServiceManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$groups = ServiceManager::get_groups();
$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$editing = $edit_id ? ServiceManager::get_group( $edit_id ) : null;
?>
<div class="wrap ssm-wrap">
	<h1><?php esc_html_e( 'Service Groups', 'service-status-manager' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Group related services together on the public status page, e.g. "Microsoft 365" or "Network".', 'service-status-manager' ); ?></p>

	<div class="ssm-two-col">
		<div class="ssm-col-list">
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th><?php esc_html_e( 'Name', 'service-status-manager' ); ?></th>
					<th><?php esc_html_e( 'Slug', 'service-status-manager' ); ?></th>
					<th><?php esc_html_e( 'Order', 'service-status-manager' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $groups ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No service groups yet.', 'service-status-manager' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $groups as $group ) : ?>
					<tr>
						<td><?php echo esc_html( $group->name ); ?></td>
						<td><code><?php echo esc_html( $group->slug ); ?></code></td>
						<td><?php echo esc_html( $group->display_order ); ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( 'edit', $group->id ) ); ?>"><?php esc_html_e( 'Edit', 'service-status-manager' ); ?></a>
							|
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this service group? Services in it will be kept but ungrouped.', 'service-status-manager' ) ); ?>');">
								<?php wp_nonce_field( 'ssm_delete_group' ); ?>
								<input type="hidden" name="action" value="ssm_delete_group" />
								<input type="hidden" name="id" value="<?php echo esc_attr( $group->id ); ?>" />
								<button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'service-status-manager' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="ssm-col-form">
			<h2><?php echo $editing ? esc_html__( 'Edit Service Group', 'service-status-manager' ) : esc_html__( 'Add Service Group', 'service-status-manager' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ssm_save_group' ); ?>
				<input type="hidden" name="action" value="ssm_save_group" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $editing->id ?? '' ); ?>" />

				<table class="form-table">
					<tr>
						<th><label for="ssm-name"><?php esc_html_e( 'Name', 'service-status-manager' ); ?></label></th>
						<td><input type="text" id="ssm-name" name="name" class="regular-text" required value="<?php echo esc_attr( $editing->name ?? '' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ssm-slug"><?php esc_html_e( 'Slug', 'service-status-manager' ); ?></label></th>
						<td><input type="text" id="ssm-slug" name="slug" class="regular-text" value="<?php echo esc_attr( $editing->slug ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Auto-generated if left blank', 'service-status-manager' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ssm-description"><?php esc_html_e( 'Description', 'service-status-manager' ); ?></label></th>
						<td><textarea id="ssm-description" name="description" rows="3" class="large-text"><?php echo esc_textarea( $editing->description ?? '' ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="ssm-order"><?php esc_html_e( 'Display order', 'service-status-manager' ); ?></label></th>
						<td><input type="number" id="ssm-order" name="display_order" value="<?php echo esc_attr( $editing->display_order ?? 0 ); ?>" /></td>
					</tr>
				</table>

				<?php submit_button( $editing ? __( 'Update Group', 'service-status-manager' ) : __( 'Add Group', 'service-status-manager' ) ); ?>
			</form>
		</div>
	</div>
</div>
