<?php
/**
 * Template: result page for confirm/unsubscribe/manage/teams-verify links.
 *
 * Expects: $action (string), $result (array from SubscriberManager), $subscriber_id, $token.
 *
 * @package ServiceStatusManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$messages = array(
	'confirm'      => __( 'Your subscription has been confirmed. Thank you.', 'service-status-manager' ),
	'unsubscribe'  => __( 'You have been unsubscribed from all Service Status Manager notifications.', 'service-status-manager' ),
	'manage'       => __( 'Manage your subscription below.', 'service-status-manager' ),
	'teams_verify' => __( 'Your Microsoft Teams destination has been verified.', 'service-status-manager' ),
);
?>
<div class="ssm-status-page ssm-subscription-result">
	<?php if ( is_wp_error( $result ) ) : ?>
		<div class="ssm-notice ssm-notice-error" role="alert">
			<p><?php echo esc_html( $result->get_error_message() ); ?></p>
		</div>
	<?php else : ?>
		<div class="ssm-notice ssm-notice-success" role="status">
			<p><?php echo esc_html( $messages[ $action ] ?? __( 'Done.', 'service-status-manager' ) ); ?></p>
		</div>

		<?php if ( 'manage' === $action && ! empty( $result['subscriber'] ) ) : ?>
			<?php require SSM_PLUGIN_DIR . 'public/templates/manage-subscription.php'; ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php
get_footer();
