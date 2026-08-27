<?php
/**
 * Admin view: manage status pages (branding, included services, visibility).
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\StatusPageManager;
use ServiceStatusManager\ServiceManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pages   = StatusPageManager::get_pages();
$edit_id = absint( $_GET['edit'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$editing = $edit_id ? StatusPageManager::get_page( $edit_id ) : null;
$groups  = ServiceManager::get_groups();
$services = ServiceManager::get_services();

$included_groups   = $editing ? ( json_decode( (string) $editing->included_groups, true ) ?: array() ) : array();
$included_services = $editing ? ( json_decode( (string) $editing->included_services, true ) ?: array() ) : array();
$page_settings      = $editing ? ( json_decode( (string) $editing->settings, true ) ?: array() ) : array();
?>
<div class="wrap ssm-wrap">
	<h1><?php esc_html_e( 'Status Pages', 'service-status-manager' ); ?></h1>
	<p class="description"><?php esc_html_e( 'The plugin ships with one status page ("main") used by [service_status_page]. You can define additional pages here for future multi-page/branded use cases.', 'service-status-manager' ); ?></p>

	<table class="wp-list-table widefat fixed striped">
		<thead><tr>
			<th><?php esc_html_e( 'Name', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Slug', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Visibility', 'service-status-manager' ); ?></th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $pages as $page ) : ?>
			<tr>
				<td><?php echo esc_html( $page->name ); ?></td>
				<td><code><?php echo esc_html( $page->slug ); ?></code></td>
				<td><?php echo esc_html( ucfirst( $page->visibility ) ); ?></td>
				<td><a href="<?php echo esc_url( add_query_arg( 'edit', $page->id ) ); ?>"><?php esc_html_e( 'Edit', 'service-status-manager' ); ?></a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php echo $editing ? esc_html__( 'Edit Status Page', 'service-status-manager' ) : esc_html__( 'Add Status Page', 'service-status-manager' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ssm_save_status_page' ); ?>
		<input type="hidden" name="action" value="ssm_save_status_page" />
		<input type="hidden" name="id" value="<?php echo esc_attr( $editing->id ?? '' ); ?>" />

		<table class="form-table">
			<tr><th><label for="ssm-p-name"><?php esc_html_e( 'Name', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-p-name" name="name" class="regular-text" required value="<?php echo esc_attr( $editing->name ?? '' ); ?>" /></td></tr>
			<tr><th><label for="ssm-p-slug"><?php esc_html_e( 'Slug', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-p-slug" name="slug" class="regular-text" value="<?php echo esc_attr( $editing->slug ?? '' ); ?>" /></td></tr>
			<tr><th><label for="ssm-p-title"><?php esc_html_e( 'Page title', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-p-title" name="page_title" class="regular-text" value="<?php echo esc_attr( $editing->page_title ?? '' ); ?>" /></td></tr>
			<tr><th><label for="ssm-p-intro"><?php esc_html_e( 'Introductory text', 'service-status-manager' ); ?></label></th>
				<td><textarea id="ssm-p-intro" name="intro_text" rows="3" class="large-text"><?php echo esc_textarea( $editing->intro_text ?? '' ); ?></textarea></td></tr>
			<tr><th><label for="ssm-p-logo"><?php esc_html_e( 'Logo URL', 'service-status-manager' ); ?></label></th>
				<td><input type="url" id="ssm-p-logo" name="logo_url" class="regular-text" value="<?php echo esc_attr( $editing->logo_url ?? '' ); ?>" /></td></tr>
			<tr><th><label for="ssm-p-primary"><?php esc_html_e( 'Primary colour', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-p-primary" name="primary_color" placeholder="#2563eb" value="<?php echo esc_attr( $editing->primary_color ?? '' ); ?>" /></td></tr>
			<tr><th><label for="ssm-p-secondary"><?php esc_html_e( 'Secondary colour', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-p-secondary" name="secondary_color" placeholder="#1e293b" value="<?php echo esc_attr( $editing->secondary_color ?? '' ); ?>" /></td></tr>
			<tr><th><?php esc_html_e( 'Visibility', 'service-status-manager' ); ?></th>
				<td>
					<select name="visibility">
						<option value="public" <?php selected( $editing->visibility ?? 'public', 'public' ); ?>><?php esc_html_e( 'Public', 'service-status-manager' ); ?></option>
						<option value="private" <?php selected( $editing->visibility ?? '', 'private' ); ?>><?php esc_html_e( 'Private (password protected)', 'service-status-manager' ); ?></option>
					</select>
				</td></tr>
			<tr><th><label for="ssm-p-password"><?php esc_html_e( 'Password (private pages)', 'service-status-manager' ); ?></label></th>
				<td><input type="password" id="ssm-p-password" name="password" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to keep existing password', 'service-status-manager' ); ?>" autocomplete="new-password" /></td></tr>
			<tr><th><label for="ssm-p-support"><?php esc_html_e( 'Support URL', 'service-status-manager' ); ?></label></th>
				<td><input type="url" id="ssm-p-support" name="support_url" class="regular-text" value="<?php echo esc_attr( $editing->support_url ?? '' ); ?>" /></td></tr>
			<tr><th><label for="ssm-p-privacy"><?php esc_html_e( 'Privacy policy URL', 'service-status-manager' ); ?></label></th>
				<td><input type="url" id="ssm-p-privacy" name="privacy_url" class="regular-text" value="<?php echo esc_attr( $editing->privacy_url ?? '' ); ?>" /></td></tr>
			<tr><th><label for="ssm-p-terms"><?php esc_html_e( 'Terms URL', 'service-status-manager' ); ?></label></th>
				<td><input type="url" id="ssm-p-terms" name="terms_url" class="regular-text" value="<?php echo esc_attr( $editing->terms_url ?? '' ); ?>" /></td></tr>
			<tr><th><label for="ssm-p-history"><?php esc_html_e( 'Default history range (days)', 'service-status-manager' ); ?></label></th>
				<td>
					<select id="ssm-p-history" name="default_history_range">
						<?php foreach ( array( 30, 60, 90 ) as $range ) : ?>
							<option value="<?php echo esc_attr( $range ); ?>" <?php selected( $editing->default_history_range ?? 90, $range ); ?>><?php echo esc_html( $range ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
			<tr><th><?php esc_html_e( 'Included service groups', 'service-status-manager' ); ?></th>
				<td>
					<?php foreach ( $groups as $group ) : ?>
						<label class="ssm-checkbox-row-admin"><input type="checkbox" name="included_groups[]" value="<?php echo esc_attr( $group->id ); ?>" <?php checked( in_array( (int) $group->id, array_map( 'intval', $included_groups ), true ) ); ?> /> <?php echo esc_html( $group->name ); ?></label><br />
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Leave all unchecked to include every group.', 'service-status-manager' ); ?></p>
				</td></tr>
			<tr><th><label for="ssm-p-css"><?php esc_html_e( 'Custom CSS', 'service-status-manager' ); ?></label></th>
				<td><textarea id="ssm-p-css" name="custom_css" rows="6" class="large-text code"><?php echo esc_textarea( $editing->custom_css ?? '' ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Restricted to administrators. Applied to the public status page only.', 'service-status-manager' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Dedicated header', 'service-status-manager' ); ?></th>
				<td>
					<label><input type="checkbox" name="show_header" value="1" <?php checked( ! empty( $page_settings['show_header'] ) ); ?> /> <?php esc_html_e( 'Show a sticky header (logo, section links, live status) above the status page', 'service-status-manager' ); ?></label>
					<p class="description"><?php esc_html_e( 'Leave unchecked if your theme already provides site navigation and you do not want a second header.', 'service-status-manager' ); ?></p>
				</td></tr>
			<tr><th><label for="ssm-p-theme"><?php esc_html_e( 'Default appearance', 'service-status-manager' ); ?></label></th>
				<td>
					<select id="ssm-p-theme" name="theme_default">
						<option value="system" <?php selected( $page_settings['theme_default'] ?? 'system', 'system' ); ?>><?php esc_html_e( 'Match visitor\'s system setting', 'service-status-manager' ); ?></option>
						<option value="light" <?php selected( $page_settings['theme_default'] ?? '', 'light' ); ?>><?php esc_html_e( 'Always light', 'service-status-manager' ); ?></option>
						<option value="dark" <?php selected( $page_settings['theme_default'] ?? '', 'dark' ); ?>><?php esc_html_e( 'Always dark', 'service-status-manager' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Visitors can still switch manually using the theme toggle; this only sets the default.', 'service-status-manager' ); ?></p>
				</td></tr>
			<tr><th><label for="ssm-p-refresh"><?php esc_html_e( 'Live refresh interval', 'service-status-manager' ); ?></label></th>
				<td>
					<input type="number" id="ssm-p-refresh" name="live_refresh_interval" value="<?php echo esc_attr( $page_settings['live_refresh_interval'] ?? 60 ); ?>" min="0" step="5" style="width:100px;" /> <?php esc_html_e( 'seconds', 'service-status-manager' ); ?>
					<p class="description"><?php esc_html_e( 'How often the page checks for status changes in the background, using the existing public REST API (no page reload). Set to 0 to disable live refresh.', 'service-status-manager' ); ?></p>
				</td></tr>
			<tr><th><?php esc_html_e( 'Uptime history', 'service-status-manager' ); ?></th>
				<td>
					<label><input type="checkbox" name="show_uptime_history" value="1" <?php checked( ! isset( $page_settings['show_uptime_history'] ) || $page_settings['show_uptime_history'] ); ?> /> <?php esc_html_e( 'Show uptime history and percentages', 'service-status-manager' ); ?></label>
					<p class="description"><?php esc_html_e( 'Uptime percentages are calculated purely from monitor checks. If your services do not have automated monitors attached, uptime figures will show little or no data - turn this off to hide the "Uptime history" section further down the page, and the "Overall uptime" figure shown at the top.', 'service-status-manager' ); ?></p>
				</td></tr>
		</table>

		<?php submit_button( $editing ? __( 'Save Status Page', 'service-status-manager' ) : __( 'Add Status Page', 'service-status-manager' ) ); ?>
	</form>
</div>
