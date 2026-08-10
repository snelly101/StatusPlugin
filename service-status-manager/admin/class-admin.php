<?php
/**
 * WordPress admin interface: menu registration, asset loading, and form
 * submission handlers.
 *
 * Every state-changing handler in this class follows the same pattern:
 * capability check, nonce check, sanitize input, delegate to the relevant
 * manager class, audit log, redirect with a status message. View templates
 * contain no business logic - they only render data handed to them.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\Capabilities;
use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\MonitorManager;
use ServiceStatusManager\IncidentManager;
use ServiceStatusManager\MaintenanceManager;
use ServiceStatusManager\SubscriberManager;
use ServiceStatusManager\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/traits/trait-admin-incidents.php';
require_once __DIR__ . '/traits/trait-admin-subscribers.php';
require_once __DIR__ . '/traits/trait-admin-notifications.php';
require_once __DIR__ . '/traits/trait-admin-settings.php';

class Admin {

	use AdminIncidentsTrait;
	use AdminSubscribersTrait;
	use AdminNotificationsTrait;
	use AdminSettingsTrait;

	const MENU_SLUG = 'service-status-manager';

	/**
	 * Registers admin hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'show_notices' ) );

		$handlers = array(
			'ssm_save_group'        => 'handle_save_group',
			'ssm_delete_group'      => 'handle_delete_group',
			'ssm_save_service'      => 'handle_save_service',
			'ssm_delete_service'    => 'handle_delete_service',
			'ssm_save_monitor'      => 'handle_save_monitor',
			'ssm_delete_monitor'    => 'handle_delete_monitor',
			'ssm_set_monitor_state' => 'handle_set_monitor_state',
			'ssm_save_incident'     => 'handle_save_incident',
			'ssm_add_incident_update' => 'handle_add_incident_update',
			'ssm_delete_incident'   => 'handle_delete_incident',
			'ssm_save_maintenance'  => 'handle_save_maintenance',
			'ssm_delete_maintenance' => 'handle_delete_maintenance',
			'ssm_save_subscriber'   => 'handle_save_subscriber',
			'ssm_delete_subscriber' => 'handle_delete_subscriber',
			'ssm_retry_notification' => 'handle_retry_notification',
			'ssm_cancel_notification' => 'handle_cancel_notification',
			'ssm_send_test_notification' => 'handle_send_test_notification',
			'ssm_save_settings'     => 'handle_save_settings',
			'ssm_save_status_page'  => 'handle_save_status_page',
			'ssm_save_webhook_out'  => 'handle_save_webhook_out',
			'ssm_delete_webhook_out' => 'handle_delete_webhook_out',
			'ssm_save_webhook_in'   => 'handle_save_webhook_in',
			'ssm_delete_webhook_in' => 'handle_delete_webhook_in',
			'ssm_run_checks_now'    => 'handle_run_checks_now',
			'ssm_export_report'     => 'handle_export_report',
			'ssm_download_logs'     => 'handle_download_logs',
			'ssm_uninstall_setting' => 'handle_uninstall_setting',
		);

		foreach ( $handlers as $action => $method ) {
			add_action( 'admin_post_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * Registers the plugin's admin menu and submenu pages.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Service Status', 'service-status-manager' ),
			__( 'Service Status', 'service-status-manager' ),
			Capabilities::VIEW,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-chart-area',
			58
		);

		$pages = array(
			'dashboard'      => array( __( 'Dashboard', 'service-status-manager' ), Capabilities::VIEW, 'render_dashboard' ),
			'status-pages'   => array( __( 'Status Pages', 'service-status-manager' ), Capabilities::MANAGE, 'render_status_pages' ),
			'service-groups' => array( __( 'Service Groups', 'service-status-manager' ), Capabilities::MANAGE, 'render_service_groups' ),
			'services'       => array( __( 'Services', 'service-status-manager' ), Capabilities::MANAGE, 'render_services' ),
			'monitors'       => array( __( 'Monitors', 'service-status-manager' ), Capabilities::MANAGE, 'render_monitors' ),
			'incidents'      => array( __( 'Incidents', 'service-status-manager' ), Capabilities::VIEW, 'render_incidents' ),
			'maintenance'    => array( __( 'Maintenance', 'service-status-manager' ), Capabilities::VIEW, 'render_maintenance' ),
			'subscribers'    => array( __( 'Subscribers', 'service-status-manager' ), Capabilities::MANAGE_SUBSCRIBERS, 'render_subscribers' ),
			'notifications'  => array( __( 'Notifications', 'service-status-manager' ), Capabilities::MANAGE_SUBSCRIBERS, 'render_notifications' ),
			'reports'        => array( __( 'Reports', 'service-status-manager' ), Capabilities::VIEW, 'render_reports' ),
			'settings'       => array( __( 'Settings', 'service-status-manager' ), Capabilities::MANAGE_SETTINGS, 'render_settings' ),
			'logs'           => array( __( 'Logs', 'service-status-manager' ), Capabilities::MANAGE, 'render_logs' ),
			'tools'          => array( __( 'Tools', 'service-status-manager' ), Capabilities::MANAGE, 'render_tools' ),
		);

		foreach ( $pages as $slug => list( $title, $cap, $callback ) ) {
			$page_slug = 'dashboard' === $slug ? self::MENU_SLUG : self::MENU_SLUG . '-' . $slug;
			add_submenu_page(
				self::MENU_SLUG,
				$title,
				$title,
				$cap,
				$page_slug,
				array( $this, $callback )
			);
		}
	}

	/**
	 * Loads admin CSS/JS only on the plugin's own screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'ssm-admin', SSM_PLUGIN_URL . 'admin/css/admin.css', array(), SSM_VERSION );
		wp_enqueue_script( 'ssm-admin', SSM_PLUGIN_URL . 'admin/js/admin.js', array( 'jquery' ), SSM_VERSION, true );
		wp_localize_script(
			'ssm-admin',
			'ssmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ssm_admin_ajax' ),
			)
		);
	}

	/**
	 * Renders a queued admin notice from a redirect, if present.
	 */
	public function show_notices() {
		if ( empty( $_GET['ssm_notice'] ) || ! current_user_can( Capabilities::VIEW ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$type    = 'error' === ( $_GET['ssm_notice_type'] ?? '' ) ? 'error' : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = sanitize_text_field( wp_unslash( $_GET['ssm_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Redirects back to an admin page with a status message.
	 *
	 * @param string $page    Submenu slug suffix (e.g. "services").
	 * @param string $message Message text.
	 * @param string $type    "success" or "error".
	 * @param array  $extra   Extra query args to append.
	 */
	private function redirect_with_notice( $page, $message, $type = 'success', array $extra = array() ) {
		$args = array_merge(
			array(
				'page'            => self::MENU_SLUG . ( 'dashboard' === $page ? '' : '-' . $page ),
				'ssm_notice'      => rawurlencode( $message ),
				'ssm_notice_type' => $type,
			),
			$extra
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Verifies the request nonce and capability, dying with a 403 on failure.
	 *
	 * @param string $action Nonce action name.
	 * @param string $cap    Required capability.
	 */
	private function guard( $action, $cap ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'service-status-manager' ), 403 );
		}
		check_admin_referer( $action );
	}

	// ------------------------------------------------------------------
	// Page renderers
	// ------------------------------------------------------------------

	public function render_dashboard() {
		$this->view( 'dashboard' );
	}

	public function render_status_pages() {
		$this->view( 'status-pages' );
	}

	public function render_service_groups() {
		$this->view( 'service-groups' );
	}

	public function render_services() {
		$this->view( 'services' );
	}

	public function render_monitors() {
		$this->view( 'monitors' );
	}

	public function render_incidents() {
		if ( isset( $_GET['incident_id'] ) || isset( $_GET['action'] ) && 'new' === $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->view( 'incident-edit' );
		} else {
			$this->view( 'incidents' );
		}
	}

	public function render_maintenance() {
		if ( isset( $_GET['maintenance_id'] ) || isset( $_GET['action'] ) && 'new' === $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->view( 'maintenance-edit' );
		} else {
			$this->view( 'maintenance' );
		}
	}

	public function render_subscribers() {
		$this->view( 'subscribers' );
	}

	public function render_notifications() {
		$this->view( 'notifications' );
	}

	public function render_reports() {
		$this->view( 'reports' );
	}

	public function render_settings() {
		$this->view( 'settings' );
	}

	public function render_logs() {
		$this->view( 'logs' );
	}

	public function render_tools() {
		$this->view( 'tools' );
	}

	/**
	 * Includes a view file from admin/views/.
	 *
	 * @param string $name View file base name.
	 */
	private function view( $name ) {
		$file = SSM_PLUGIN_DIR . 'admin/views/' . $name . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}

	// ------------------------------------------------------------------
	// Service group handlers
	// ------------------------------------------------------------------

	public function handle_save_group() {
		$this->guard( 'ssm_save_group', Capabilities::MANAGE );

		$id   = absint( $_POST['id'] ?? 0 );
		$data = array(
			'name'          => wp_unslash( $_POST['name'] ?? '' ),
			'slug'          => wp_unslash( $_POST['slug'] ?? '' ),
			'description'   => wp_unslash( $_POST['description'] ?? '' ),
			'display_order' => wp_unslash( $_POST['display_order'] ?? 0 ),
		);

		$result = $id ? ServiceManager::update_group( $id, $data ) : ServiceManager::create_group( $data );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'service-groups', $result->get_error_message(), 'error' );
		}

		$this->redirect_with_notice( 'service-groups', __( 'Service group saved.', 'service-status-manager' ) );
	}

	public function handle_delete_group() {
		$this->guard( 'ssm_delete_group', Capabilities::MANAGE );
		ServiceManager::delete_group( absint( $_POST['id'] ?? 0 ) );
		$this->redirect_with_notice( 'service-groups', __( 'Service group deleted.', 'service-status-manager' ) );
	}

	// ------------------------------------------------------------------
	// Service handlers
	// ------------------------------------------------------------------

	public function handle_save_service() {
		$this->guard( 'ssm_save_service', Capabilities::MANAGE );

		$id   = absint( $_POST['id'] ?? 0 );
		$data = array(
			'group_id'             => absint( $_POST['group_id'] ?? 0 ),
			'name'                 => wp_unslash( $_POST['name'] ?? '' ),
			'slug'                 => wp_unslash( $_POST['slug'] ?? '' ),
			'description'          => wp_unslash( $_POST['description'] ?? '' ),
			'visibility'           => wp_unslash( $_POST['visibility'] ?? 'public' ),
			'display_order'        => wp_unslash( $_POST['display_order'] ?? 0 ),
			'icon'                 => wp_unslash( $_POST['icon'] ?? '' ),
			'image_url'            => wp_unslash( $_POST['image_url'] ?? '' ),
			'status'               => wp_unslash( $_POST['status'] ?? 'operational' ),
			'status_mode'          => wp_unslash( $_POST['status_mode'] ?? 'automatic' ),
			'allow_subscriptions'  => ! empty( $_POST['allow_subscriptions'] ),
			'show_on_status_page'  => ! empty( $_POST['show_on_status_page'] ),
			'exclude_from_overall' => ! empty( $_POST['exclude_from_overall'] ),
		);

		$result = $id ? ServiceManager::update_service( $id, $data ) : ServiceManager::create_service( $data );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'services', $result->get_error_message(), 'error' );
		}

		$this->redirect_with_notice( 'services', __( 'Service saved.', 'service-status-manager' ) );
	}

	public function handle_delete_service() {
		$this->guard( 'ssm_delete_service', Capabilities::MANAGE );
		ServiceManager::delete_service( absint( $_POST['id'] ?? 0 ) );
		$this->redirect_with_notice( 'services', __( 'Service deleted.', 'service-status-manager' ) );
	}

	// ------------------------------------------------------------------
	// Monitor handlers
	// ------------------------------------------------------------------

	public function handle_save_monitor() {
		$this->guard( 'ssm_save_monitor', Capabilities::MANAGE );

		$id   = absint( $_POST['id'] ?? 0 );
		$type = sanitize_key( wp_unslash( $_POST['type'] ?? 'manual' ) );

		$settings = array();
		if ( 'http' === $type ) {
			$settings = array(
				'url'               => wp_unslash( $_POST['url'] ?? '' ),
				'method'            => wp_unslash( $_POST['method'] ?? 'GET' ),
				'expected_status'   => wp_unslash( $_POST['expected_status'] ?? 200 ),
				'required_text'     => wp_unslash( $_POST['required_text'] ?? '' ),
				'forbidden_text'    => wp_unslash( $_POST['forbidden_text'] ?? '' ),
				'follow_redirects'  => ! empty( $_POST['follow_redirects'] ),
				'verify_ssl'        => ! empty( $_POST['verify_ssl'] ),
				'allow_internal'    => ! empty( $_POST['allow_internal'] ),
				'headers'           => $this->parse_header_rows(),
			);
		} elseif ( 'tcp' === $type ) {
			$settings = array(
				'host'           => wp_unslash( $_POST['host'] ?? '' ),
				'port'           => wp_unslash( $_POST['port'] ?? 443 ),
				'allow_internal' => ! empty( $_POST['allow_internal'] ),
			);
		}

		$data = array(
			'service_id'            => absint( $_POST['service_id'] ?? 0 ),
			'name'                  => wp_unslash( $_POST['name'] ?? '' ),
			'type'                  => $type,
			'settings'              => $settings,
			'check_frequency'       => wp_unslash( $_POST['check_frequency'] ?? 300 ),
			'timeout_seconds'       => wp_unslash( $_POST['timeout_seconds'] ?? 10 ),
			'failure_threshold'     => wp_unslash( $_POST['failure_threshold'] ?? 3 ),
			'recovery_threshold'    => wp_unslash( $_POST['recovery_threshold'] ?? 2 ),
			'auto_incident'         => ! empty( $_POST['auto_incident'] ),
			'is_active'             => ! empty( $_POST['is_active'] ),
			'is_public'             => ! empty( $_POST['is_public'] ),
			'display_order'         => wp_unslash( $_POST['display_order'] ?? 0 ),
			'warning_response_ms'   => wp_unslash( $_POST['warning_response_ms'] ?? '' ),
			'critical_response_ms'  => wp_unslash( $_POST['critical_response_ms'] ?? '' ),
		);

		$result = $id ? MonitorManager::update_monitor( $id, $data ) : MonitorManager::create_monitor( $data );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'monitors', $result->get_error_message(), 'error' );
		}

		$this->redirect_with_notice( 'monitors', __( 'Monitor saved.', 'service-status-manager' ) );
	}

	/**
	 * Parses the repeatable custom-header rows submitted from the monitor form.
	 *
	 * @return array
	 */
	private function parse_header_rows() {
		$names  = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['header_name'] ?? array() ) ) );
		$values = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['header_value'] ?? array() ) ) );

		$headers = array();
		foreach ( $names as $index => $name ) {
			if ( '' === $name ) {
				continue;
			}
			$headers[] = array(
				'name'  => $name,
				'value' => $values[ $index ] ?? '',
			);
		}

		return $headers;
	}

	public function handle_delete_monitor() {
		$this->guard( 'ssm_delete_monitor', Capabilities::MANAGE );
		MonitorManager::delete_monitor( absint( $_POST['id'] ?? 0 ) );
		$this->redirect_with_notice( 'monitors', __( 'Monitor deleted.', 'service-status-manager' ) );
	}

	public function handle_set_monitor_state() {
		$this->guard( 'ssm_set_monitor_state', Capabilities::MANAGE );
		MonitorManager::set_manual_state( absint( $_POST['id'] ?? 0 ), wp_unslash( $_POST['state'] ?? 'unknown' ) );
		$this->redirect_with_notice( 'monitors', __( 'Monitor state updated.', 'service-status-manager' ) );
	}

	public function handle_run_checks_now() {
		$this->guard( 'ssm_run_checks_now', Capabilities::MANAGE );
		\ServiceStatusManager\Monitoring\MonitorRunner::run_due_checks( true );
		$this->redirect_with_notice( 'tools', __( 'Monitor checks triggered.', 'service-status-manager' ) );
	}

}
