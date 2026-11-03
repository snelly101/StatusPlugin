<?php
/**
 * Admin view: subscriber list and management.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\SubscriberManager;
use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\MonitorManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$result  = SubscriberManager::query_for_admin( array( 'paged' => $paged, 'search' => $search, 'status' => $status ) );
$edit_id = absint( $_GET['edit'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$editing = $edit_id ? SubscriberManager::get_subscriber( $edit_id ) : null;

// Built once, shared by the list's "Following" column and the edit form:
// every subscribable group, service and (public) monitor, plus id => name
// maps so a subscriber's stored selections can be shown by name.
$all_groups   = ServiceManager::get_groups();
$all_services = ServiceManager::get_services( array( 'show_on_status_page' => 1 ) );
$monitors_by_service = array();
$group_names   = wp_list_pluck( $all_groups, 'name', 'id' );
$service_names = wp_list_pluck( $all_services, 'name', 'id' );
$monitor_names = array();
foreach ( $all_services as $svc ) {
	$monitors_by_service[ $svc->id ] = array_values( array_filter( MonitorManager::get_monitors_for_service( $svc->id ), fn( $m ) => $m->is_public ) );
	foreach ( $monitors_by_service[ $svc->id ] as $m ) {
		$monitor_names[ $m->id ] = $m->name;
	}
}

$severity_labels = array(
	'informational' => __( 'Informational', 'service-status-manager' ),
	'minor'         => __( 'Minor', 'service-status-manager' ),
	'major'         => __( 'Major', 'service-status-manager' ),
	'critical'      => __( 'Critical', 'service-status-manager' ),
);

/**
 * Splits a subscriber's selection rows into id lists per scope type.
 *
 * @param array $selections Rows from SubscriberManager::get_selections().
 * @return array{group: int[], service: int[], monitor: int[]}
 */
$split_selections = function ( array $selections ) {
	$out = array( 'group' => array(), 'service' => array(), 'monitor' => array() );
	foreach ( $selections as $row ) {
		if ( isset( $out[ $row->scope_type ] ) ) {
			$out[ $row->scope_type ][] = (int) $row->scope_id;
		}
	}
	return $out;
};
?>
<div class="wrap ssm-wrap">
	<h1><?php esc_html_e( 'Subscribers', 'service-status-manager' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Subscribers sign up through the public status page. Administrators can review, pause, or delete (erase) subscriber records here.', 'service-status-manager' ); ?></p>

	<form method="get" style="margin: 12px 0;">
		<input type="hidden" name="page" value="service-status-manager-subscribers" />
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name or email…', 'service-status-manager' ); ?>" />
		<select name="status">
			<option value=""><?php esc_html_e( 'All statuses', 'service-status-manager' ); ?></option>
			<?php foreach ( array( 'pending', 'active', 'paused', 'unsubscribed' ) as $s ) : ?>
				<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php submit_button( __( 'Filter', 'service-status-manager' ), '', '', false ); ?>
	</form>

	<?php if ( $editing ) : ?>
		<?php
		$editing_sel        = $split_selections( SubscriberManager::get_selections( $editing->id ) );
		$editing_everything = empty( $editing_sel['group'] ) && empty( $editing_sel['service'] ) && empty( $editing_sel['monitor'] );
		?>
		<div class="ssm-col-form" style="max-width:680px;">
			<h2><?php esc_html_e( 'Edit Subscriber', 'service-status-manager' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ssm_save_subscriber' ); ?>
				<input type="hidden" name="action" value="ssm_save_subscriber" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $editing->id ); ?>" />
				<table class="form-table">
					<tr><th><label for="ssm-s-email"><?php esc_html_e( 'Email', 'service-status-manager' ); ?></label></th>
						<td><input type="email" id="ssm-s-email" name="email" class="regular-text" value="<?php echo esc_attr( $editing->email ); ?>" /></td></tr>
					<tr><th><label for="ssm-s-phone"><?php esc_html_e( 'Phone', 'service-status-manager' ); ?></label></th>
						<td><input type="tel" id="ssm-s-phone" name="phone" class="regular-text" value="<?php echo esc_attr( $editing->phone ); ?>" />
						<p class="description"><?php esc_html_e( 'Changing an email or number treats it as a new, unconfirmed address: that channel drops back to unverified and a fresh confirmation is sent to the corrected address. Use the Verify button in the list if you already know it is right. Clearing a value removes that channel.', 'service-status-manager' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Channels', 'service-status-manager' ); ?></th>
						<td>
							<?php
							$editing_channels = array_filter( SubscriberManager::get_channels( $editing->id ), fn( $c ) => $c->is_active );
							if ( empty( $editing_channels ) ) {
								echo '—';
							}
							foreach ( $editing_channels as $ch ) {
								echo '<div>' . esc_html( ucfirst( $ch->channel ) ) . ' &mdash; ' . ( $ch->verified ? esc_html__( 'verified', 'service-status-manager' ) : '<em>' . esc_html__( 'unverified', 'service-status-manager' ) . '</em>' ) . '</div>';
							}
							?>
							<p class="description"><?php esc_html_e( 'To verify a channel by hand (e.g. the confirmation email never arrived), use the Verify button next to it in the list below.', 'service-status-manager' ); ?></p>
						</td>
					</tr>
					<tr><th><label for="ssm-s-name"><?php esc_html_e( 'Name', 'service-status-manager' ); ?></label></th><td><input type="text" id="ssm-s-name" name="name" value="<?php echo esc_attr( $editing->name ); ?>" /></td></tr>
					<tr><th><label for="ssm-s-status"><?php esc_html_e( 'Status', 'service-status-manager' ); ?></label></th>
						<td>
							<select id="ssm-s-status" name="status">
								<?php foreach ( array( 'pending', 'active', 'paused', 'unsubscribed' ) as $s ) : ?>
									<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $editing->status, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr><th><?php esc_html_e( 'Following', 'service-status-manager' ); ?></th>
						<td>
							<label><input type="checkbox" name="following_everything" value="1" data-ssm-admin-select-all <?php checked( $editing_everything ); ?> /> <strong><?php esc_html_e( 'Everything', 'service-status-manager' ); ?></strong></label>
							<p class="description"><?php esc_html_e( 'Ticked: notified about all current and future services. Untick to pick specific groups, services or monitors below.', 'service-status-manager' ); ?></p>

							<?php if ( $all_groups ) : ?>
								<p style="margin:12px 0 4px;"><strong><?php esc_html_e( 'Service groups', 'service-status-manager' ); ?></strong></p>
								<?php foreach ( $all_groups as $group ) : ?>
									<label style="display:block;"><input type="checkbox" name="groups[]" value="<?php echo esc_attr( $group->id ); ?>" data-ssm-admin-selectable <?php checked( in_array( (int) $group->id, $editing_sel['group'], true ) ); ?> <?php disabled( $editing_everything ); ?> /> <?php echo esc_html( $group->name ); ?></label>
								<?php endforeach; ?>
							<?php endif; ?>

							<p style="margin:12px 0 4px;"><strong><?php esc_html_e( 'Services', 'service-status-manager' ); ?></strong></p>
							<?php foreach ( $all_services as $service ) : if ( ! $service->allow_subscriptions ) { continue; } ?>
								<label style="display:block;"><input type="checkbox" name="services[]" value="<?php echo esc_attr( $service->id ); ?>" data-ssm-admin-selectable <?php checked( in_array( (int) $service->id, $editing_sel['service'], true ) ); ?> <?php disabled( $editing_everything ); ?> /> <?php echo esc_html( $service->name ); ?></label>
								<?php foreach ( $monitors_by_service[ $service->id ] ?? array() as $monitor ) : ?>
									<label style="display:block; margin-left:24px;"><input type="checkbox" name="monitors[]" value="<?php echo esc_attr( $monitor->id ); ?>" data-ssm-admin-selectable <?php checked( in_array( (int) $monitor->id, $editing_sel['monitor'], true ) ); ?> <?php disabled( $editing_everything ); ?> /> <?php echo esc_html( $monitor->name ); ?></label>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr><th><label for="ssm-s-severity"><?php esc_html_e( 'Minimum incident severity', 'service-status-manager' ); ?></label></th>
						<td>
							<select id="ssm-s-severity" name="min_severity">
								<?php foreach ( $severity_labels as $level => $label ) : ?>
									<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $editing->min_severity, $level ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Incidents below this severity are not sent to this subscriber.', 'service-status-manager' ); ?></p>
						</td>
					</tr>
					<tr><th><?php esc_html_e( 'Maintenance', 'service-status-manager' ); ?></th>
						<td><label><input type="checkbox" name="maintenance_notifications" value="1" <?php checked( $editing->maintenance_notifications, 1 ); ?> /> <?php esc_html_e( 'Notify about scheduled maintenance', 'service-status-manager' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save', 'service-status-manager' ) ); ?>
			</form>
		</div>
	<?php endif; ?>

	<table class="wp-list-table widefat fixed striped">
		<thead><tr>
			<th><?php esc_html_e( 'Name', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Email', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Phone', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Channels', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Following', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Preferences', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Status', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Subscribed', 'service-status-manager' ); ?></th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $result['items'] ) ) : ?>
			<tr><td colspan="9"><?php esc_html_e( 'No subscribers found.', 'service-status-manager' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $result['items'] as $subscriber ) : ?>
			<tr>
				<td><?php echo esc_html( $subscriber->name ?: '—' ); ?></td>
				<td><?php echo esc_html( $subscriber->email ?: '—' ); ?></td>
				<td><?php echo esc_html( $subscriber->phone ?: '—' ); ?></td>
				<td>
					<?php
					$channels = array_filter( SubscriberManager::get_channels( $subscriber->id ), fn( $c ) => $c->is_active );
					if ( empty( $channels ) ) {
						echo '—';
					}
					foreach ( $channels as $ch ) :
						?>
						<div style="white-space:nowrap;">
							<?php echo esc_html( ucfirst( $ch->channel ) ); ?>
							<?php if ( $ch->verified ) : ?>
								<span style="color:#00a32a;" title="<?php esc_attr_e( 'Verified', 'service-status-manager' ); ?>">&#10003;</span>
							<?php else : ?>
								<em><?php esc_html_e( '(unverified)', 'service-status-manager' ); ?></em>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( sprintf( /* translators: %s: channel name */ __( 'Mark this %s channel as verified? The subscriber will be activated and sent their management link, exactly as if they had clicked the confirmation link.', 'service-status-manager' ), ucfirst( $ch->channel ) ) ); ?>');">
									<?php wp_nonce_field( 'ssm_verify_channel' ); ?>
									<input type="hidden" name="action" value="ssm_verify_channel" />
									<input type="hidden" name="id" value="<?php echo esc_attr( $subscriber->id ); ?>" />
									<input type="hidden" name="channel" value="<?php echo esc_attr( $ch->channel ); ?>" />
									<button type="submit" class="button-link"><?php esc_html_e( 'Verify', 'service-status-manager' ); ?></button>
								</form>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</td>
				<td>
					<?php
					$sel   = $split_selections( SubscriberManager::get_selections( $subscriber->id ) );
					$names = array();
					foreach ( $sel['group'] as $gid ) {
						$names[] = ( $group_names[ $gid ] ?? '#' . $gid ) . ' (' . __( 'group', 'service-status-manager' ) . ')';
					}
					foreach ( $sel['service'] as $sid ) {
						$names[] = $service_names[ $sid ] ?? '#' . $sid;
					}
					foreach ( $sel['monitor'] as $mid ) {
						$names[] = ( $monitor_names[ $mid ] ?? '#' . $mid ) . ' (' . __( 'monitor', 'service-status-manager' ) . ')';
					}
					echo $names ? esc_html( implode( ', ', $names ) ) : '<em>' . esc_html__( 'Everything', 'service-status-manager' ) . '</em>';
					?>
				</td>
				<td>
					<?php
					/* translators: %s: minimum severity label */
					echo esc_html( sprintf( __( 'Severity: %s', 'service-status-manager' ), $severity_labels[ $subscriber->min_severity ] ?? ucfirst( $subscriber->min_severity ) ) );
					echo '<br />';
					echo esc_html( $subscriber->maintenance_notifications ? __( 'Maintenance: yes', 'service-status-manager' ) : __( 'Maintenance: no', 'service-status-manager' ) );
					?>
				</td>
				<td><?php echo esc_html( ucfirst( $subscriber->status ) ); ?></td>
				<td><?php echo esc_html( ssm_format_datetime( $subscriber->created_at ) ); ?></td>
				<td>
					<a href="<?php echo esc_url( add_query_arg( 'edit', $subscriber->id ) ); ?>"><?php esc_html_e( 'Edit', 'service-status-manager' ); ?></a>
					|
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete this subscriber and all their data?', 'service-status-manager' ) ); ?>');">
						<?php wp_nonce_field( 'ssm_delete_subscriber' ); ?>
						<input type="hidden" name="action" value="ssm_delete_subscriber" />
						<input type="hidden" name="id" value="<?php echo esc_attr( $subscriber->id ); ?>" />
						<button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'service-status-manager' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	$total_pages = (int) ceil( $result['total'] / 20 );
	if ( $total_pages > 1 ) :
		?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $total_pages ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div></div>
	<?php endif; ?>
</div>
