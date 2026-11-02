<?php
/**
 * Partial: the outcome of a confirm/unsubscribe/manage/teams-verify link -
 * a notice, plus the self-service manage form for "manage".
 *
 * Reads the pending outcome stashed by PublicController::handle_token_actions()
 * for this request, so the same partial serves both the inline render at
 * the top of the status page (the normal case) and the standalone fallback
 * template used when the site has no status page.
 *
 * @package ServiceStatusManager
 */

use ServiceStatusManager\Publicweb\PublicController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pending = PublicController::pending_token_result();
if ( ! $pending ) {
	return;
}

$action = $pending['action'];
$result = $pending['result'];

$messages = array(
	'confirm'      => __( 'Your subscription has been confirmed. Thank you.', 'service-status-manager' ),
	'unsubscribe'  => __( 'You have been unsubscribed from all Service Status Manager notifications.', 'service-status-manager' ),
	'manage'       => __( 'Manage your subscription below.', 'service-status-manager' ),
	'teams_verify' => __( 'Your Microsoft Teams destination has been verified.', 'service-status-manager' ),
);
?>
<div class="ssm-subscription-result" id="ssm-subscription-result">
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
