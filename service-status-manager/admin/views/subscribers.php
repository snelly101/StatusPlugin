<?php
/**
 * Admin view: subscriber list and management.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\SubscriberManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$result  = SubscriberManager::query_for_admin( array( 'paged' => $paged, 'search' => $search, 'status' => $status ) );
$edit_id = absint( $_GET['edit'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$editing = $edit_id ? SubscriberManager::get_subscriber( $edit_id ) : null;
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
		<div class="ssm-col-form" style="max-width:420px;">
			<h2><?php esc_html_e( 'Edit Subscriber', 'service-status-manager' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ssm_save_subscriber' ); ?>
				<input type="hidden" name="action" value="ssm_save_subscriber" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $editing->id ); ?>" />
				<table class="form-table">
					<tr><th><?php esc_html_e( 'Email', 'service-status-manager' ); ?></th><td><?php echo esc_html( $editing->email ?: '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Phone', 'service-status-manager' ); ?></th><td><?php echo esc_html( $editing->phone ?: '—' ); ?></td></tr>
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
			<th><?php esc_html_e( 'Status', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Subscribed', 'service-status-manager' ); ?></th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $result['items'] ) ) : ?>
			<tr><td colspan="7"><?php esc_html_e( 'No subscribers found.', 'service-status-manager' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $result['items'] as $subscriber ) : ?>
			<tr>
				<td><?php echo esc_html( $subscriber->name ?: '—' ); ?></td>
				<td><?php echo esc_html( $subscriber->email ?: '—' ); ?></td>
				<td><?php echo esc_html( $subscriber->phone ?: '—' ); ?></td>
				<td>
					<?php
					$channels = wp_list_pluck( array_filter( SubscriberManager::get_channels( $subscriber->id ), fn( $c ) => $c->is_active ), 'verified', 'channel' );
					$labels   = array();
					foreach ( $channels as $channel => $verified ) {
						$labels[] = ucfirst( $channel ) . ( $verified ? '' : ' (' . esc_html__( 'unverified', 'service-status-manager' ) . ')' );
					}
					echo esc_html( implode( ', ', $labels ) ?: '—' );
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
