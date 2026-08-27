<?php
/**
 * Shared partial: a friendly empty state instead of a bare "No X" line.
 *
 * Expects (all optional, set by the including template just before
 * `require`): $empty_icon (icon name, see ssm_icon()), $empty_title,
 * $empty_desc.
 *
 * @package ServiceStatusManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$empty_icon  = $empty_icon ?? 'check-circle';
$empty_title = $empty_title ?? __( 'All clear', 'service-status-manager' );
$empty_desc  = $empty_desc ?? '';
?>
<div class="ssm-empty-state">
	<span class="ssm-empty-state-icon"><?php echo ssm_icon( $empty_icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	<strong><?php echo esc_html( $empty_title ); ?></strong>
	<?php if ( $empty_desc ) : ?>
		<p><?php echo esc_html( $empty_desc ); ?></p>
	<?php endif; ?>
</div>
