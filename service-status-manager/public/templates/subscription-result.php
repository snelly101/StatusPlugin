<?php
/**
 * Template: standalone fallback for confirm/unsubscribe/manage/teams-verify
 * links, used only when the site has no page containing
 * [service_status_page] to render the outcome inside (see
 * PublicController::handle_token_actions()).
 *
 * @package ServiceStatusManager
 */

use ServiceStatusManager\StatusPageManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$page          = StatusPageManager::get_page_by_slug( 'main' );
$page_settings = $page ? ( json_decode( (string) $page->settings, true ) ?: array() ) : array();
$theme_default = in_array( $page_settings['theme_default'] ?? 'system', array( 'light', 'dark' ), true ) ? $page_settings['theme_default'] : 'auto';
?>
<div class="ssm-status-page ssm-standalone" data-ssm-theme="<?php echo esc_attr( $theme_default ); ?>" data-ssm-theme-preference="<?php echo esc_attr( $page_settings['theme_default'] ?? 'system' ); ?>">
	<?php require SSM_PLUGIN_DIR . 'public/templates/parts/subscription-result-body.php'; ?>
	<div class="ssm-toast-region" data-ssm-toast-region aria-live="polite"></div>
</div>
<?php
get_footer();
