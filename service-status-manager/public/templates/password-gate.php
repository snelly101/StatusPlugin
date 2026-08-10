<?php
/**
 * Template: password prompt for a private status page.
 *
 * Expects: $page (status page row) in scope.
 *
 * @package ServiceStatusManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div class="ssm-status-page ssm-password-gate">
	<h1><?php echo esc_html( $page->page_title ?: $page->name ); ?></h1>
	<p><?php esc_html_e( 'This status page is private. Please enter the password to continue.', 'service-status-manager' ); ?></p>

	<?php if ( ! empty( $_POST['ssm_page_password'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Missing ?>
		<div class="ssm-notice ssm-notice-error" role="alert"><p><?php esc_html_e( 'Incorrect password.', 'service-status-manager' ); ?></p></div>
	<?php endif; ?>

	<form method="post" class="ssm-subscribe-form">
		<?php wp_nonce_field( 'ssm_page_password_' . $page->id, 'ssm_page_password_nonce' ); ?>
		<div class="ssm-form-row">
			<label for="ssm-page-password"><?php esc_html_e( 'Password', 'service-status-manager' ); ?></label>
			<input type="password" id="ssm-page-password" name="ssm_page_password" required autofocus />
		</div>
		<button type="submit" class="ssm-button ssm-button-primary"><?php esc_html_e( 'View status page', 'service-status-manager' ); ?></button>
	</form>
</div>
<?php
get_footer();
