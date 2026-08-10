<?php
/**
 * Template: subscription self-management form (channel/service selection,
 * pause, unsubscribe). Included by subscription-result.php when the
 * ?ssm_action=manage link is used.
 *
 * Expects: $result['subscriber'], $result['selections'], $result['token'], $groups, $services in scope via $result.
 *
 * @package ServiceStatusManager
 */

use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\MonitorManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$subscriber = $result['subscriber'];
$selections = $result['selections'];
$groups     = ServiceManager::get_groups();
$services   = ServiceManager::get_services( array( 'show_on_status_page' => 1 ) );

$selected_groups   = wp_list_pluck( array_filter( $selections, fn( $s ) => 'group' === $s->scope_type ), 'scope_id' );
$selected_services = wp_list_pluck( array_filter( $selections, fn( $s ) => 'service' === $s->scope_type ), 'scope_id' );
$selected_monitors = wp_list_pluck( array_filter( $selections, fn( $s ) => 'monitor' === $s->scope_type ), 'scope_id' );
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ssm-subscribe-form ssm-manage-form">
	<?php wp_nonce_field( 'ssm_update_subscription' ); ?>
	<input type="hidden" name="action" value="ssm_update_subscription" />
	<input type="hidden" name="subscriber_id" value="<?php echo esc_attr( $subscriber->id ); ?>" />
	<input type="hidden" name="token" value="<?php echo esc_attr( $result['token'] ); ?>" />

	<div class="ssm-form-row">
		<label for="ssm-manage-name"><?php esc_html_e( 'Name', 'service-status-manager' ); ?></label>
		<input type="text" id="ssm-manage-name" name="name" value="<?php echo esc_attr( $subscriber->name ); ?>" />
	</div>

	<fieldset class="ssm-form-row">
		<legend><?php esc_html_e( 'Service groups', 'service-status-manager' ); ?></legend>
		<?php foreach ( $groups as $group ) : ?>
			<label class="ssm-checkbox-row">
				<input type="checkbox" name="groups[]" value="<?php echo esc_attr( $group->id ); ?>" <?php checked( in_array( (int) $group->id, array_map( 'intval', $selected_groups ), true ) ); ?> />
				<?php echo esc_html( $group->name ); ?>
			</label>
		<?php endforeach; ?>
	</fieldset>

	<fieldset class="ssm-form-row">
		<legend><?php esc_html_e( 'Services', 'service-status-manager' ); ?></legend>
		<?php foreach ( $services as $service ) : if ( ! $service->allow_subscriptions ) { continue; } ?>
			<label class="ssm-checkbox-row">
				<input type="checkbox" name="services[]" value="<?php echo esc_attr( $service->id ); ?>" <?php checked( in_array( (int) $service->id, array_map( 'intval', $selected_services ), true ) ); ?> />
				<?php echo esc_html( $service->name ); ?>
			</label>
			<?php foreach ( MonitorManager::get_monitors_for_service( $service->id ) as $monitor ) : if ( ! $monitor->is_public ) { continue; } ?>
				<label class="ssm-checkbox-row ssm-checkbox-row--indent">
					<input type="checkbox" name="monitors[]" value="<?php echo esc_attr( $monitor->id ); ?>" <?php checked( in_array( (int) $monitor->id, array_map( 'intval', $selected_monitors ), true ) ); ?> />
					<?php echo esc_html( $monitor->name ); ?>
				</label>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</fieldset>

	<div class="ssm-form-row">
		<label for="ssm-manage-severity"><?php esc_html_e( 'Minimum incident severity', 'service-status-manager' ); ?></label>
		<select id="ssm-manage-severity" name="min_severity">
			<?php foreach ( array( 'informational', 'minor', 'major', 'critical' ) as $level ) : ?>
				<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $subscriber->min_severity, $level ); ?>><?php echo esc_html( ucfirst( $level ) ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>

	<div class="ssm-form-row">
		<label class="ssm-checkbox-row">
			<input type="checkbox" name="maintenance_notifications" value="1" <?php checked( $subscriber->maintenance_notifications, 1 ); ?> />
			<?php esc_html_e( 'Notify me about scheduled maintenance', 'service-status-manager' ); ?>
		</label>
	</div>

	<div class="ssm-form-row">
		<label class="ssm-checkbox-row">
			<input type="checkbox" name="paused" value="1" <?php checked( 'paused' === $subscriber->status ); ?> />
			<?php esc_html_e( 'Pause all notifications (keeps your subscription, stops sending)', 'service-status-manager' ); ?>
		</label>
	</div>

	<button type="submit" class="ssm-button ssm-button-primary" name="submit_action" value="save"><?php esc_html_e( 'Save changes', 'service-status-manager' ); ?></button>
	<button type="submit" class="ssm-button ssm-button-danger" name="submit_action" value="unsubscribe" onclick="return confirm('<?php echo esc_js( __( 'Unsubscribe from all notifications?', 'service-status-manager' ) ); ?>');"><?php esc_html_e( 'Unsubscribe completely', 'service-status-manager' ); ?></button>
	<button type="submit" class="ssm-button" name="submit_action" value="export"><?php esc_html_e( 'Export my data', 'service-status-manager' ); ?></button>
	<button type="submit" class="ssm-button ssm-button-danger" name="submit_action" value="erase" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete all your data? This cannot be undone.', 'service-status-manager' ) ); ?>');"><?php esc_html_e( 'Delete my data', 'service-status-manager' ); ?></button>
</form>
