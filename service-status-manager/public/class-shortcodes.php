<?php
/**
 * Registers every public-facing shortcode and renders its template.
 *
 * All shortcodes share a common attribute set where relevant (group, service,
 * count, show_monitors, show_subscribe, layout) so they can be combined
 * freely on a single page, and all output is produced from templates in
 * public/templates/ so themes can override them by copying the file into
 * `your-theme/service-status-manager/`.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Publicweb;

use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\MonitorManager;
use ServiceStatusManager\IncidentManager;
use ServiceStatusManager\MaintenanceManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shortcodes {

	/**
	 * Registers all shortcodes.
	 */
	public function register() {
		add_shortcode( 'service_status_page', array( $this, 'render_page' ) );
		add_shortcode( 'service_status_summary', array( $this, 'render_summary' ) );
		add_shortcode( 'service_status_services', array( $this, 'render_services' ) );
		add_shortcode( 'service_status_incidents', array( $this, 'render_incidents' ) );
		add_shortcode( 'service_status_maintenance', array( $this, 'render_maintenance' ) );
		add_shortcode( 'service_status_subscribe', array( $this, 'render_subscribe' ) );
		add_shortcode( 'service_status_history', array( $this, 'render_history' ) );
	}

	/**
	 * Resolves a template path, allowing themes to override by placing a
	 * same-named file in `<theme>/service-status-manager/<name>.php`.
	 *
	 * @param string $name Template base name.
	 * @return string
	 */
	private function locate_template( $name ) {
		$theme_override = locate_template( 'service-status-manager/' . $name . '.php' );
		return $theme_override ? $theme_override : SSM_PLUGIN_DIR . 'public/templates/' . $name . '.php';
	}

	/**
	 * Renders a template file with the given variables in scope.
	 *
	 * @param string $name Template base name.
	 * @param array  $vars Variables to extract into the template's scope.
	 * @return string
	 */
	private function render( $name, array $vars = array() ) {
		$template = $this->locate_template( $name );
		if ( ! file_exists( $template ) ) {
			return '';
		}

		extract( $vars ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract

		ob_start();
		include $template;
		return ob_get_clean();
	}

	/**
	 * Common attribute normalisation shared by every shortcode.
	 *
	 * @param array $atts Raw shortcode attributes.
	 * @return array
	 */
	private function normalize_atts( array $atts ) {
		return shortcode_atts(
			array(
				'group'          => '',
				'service'        => '',
				'count'          => 5,
				'show_monitors'  => 'yes',
				'show_subscribe' => 'yes',
				'layout'         => 'full',
				'range'          => 90,
			),
			$atts
		);
	}

	/**
	 * [service_status_page] - the full status page: summary, services,
	 * incidents, maintenance and a subscribe button, in one shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_page( $atts ) {
		$atts = $this->normalize_atts( (array) $atts );

		return $this->render(
			'status-page',
			array(
				'atts' => $atts,
			)
		);
	}

	/**
	 * [service_status_summary] - overall status banner only.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_summary( $atts ) {
		$atts    = $this->normalize_atts( (array) $atts );
		$overall = ServiceManager::get_overall_status();

		$services      = ServiceManager::get_services( array( 'show_on_status_page' => 1 ) );
		$uptime_values = array();
		foreach ( $services as $service ) {
			if ( empty( $service->exclude_from_overall ) ) {
				$uptime_values[] = \ServiceStatusManager\UptimeAggregator::get_service_uptime_percentage( $service->id, 90 );
			}
		}
		$overall_uptime = $uptime_values ? array_sum( $uptime_values ) / count( $uptime_values ) : null;

		return $this->render(
			'summary',
			array(
				'overall_status'  => $overall,
				'overall_uptime'  => $overall_uptime,
				'atts'            => $atts,
			)
		);
	}

	/**
	 * [service_status_services] - grouped service/monitor list.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_services( $atts ) {
		$atts = $this->normalize_atts( (array) $atts );

		$groups   = ServiceManager::get_groups();
		$services = ServiceManager::get_services( array( 'show_on_status_page' => 1 ) );

		if ( $atts['service'] ) {
			$services = array_values(
				array_filter(
					$services,
					function ( $service ) use ( $atts ) {
						return $service->slug === $atts['service'];
					}
				)
			);
		} elseif ( $atts['group'] ) {
			$services = array_values(
				array_filter(
					$services,
					function ( $service ) use ( $atts, $groups ) {
						foreach ( $groups as $group ) {
							if ( $group->slug === $atts['group'] && (int) $group->id === (int) $service->group_id ) {
								return true;
							}
						}
						return false;
					}
				)
			);
		}

		return $this->render(
			'services',
			array(
				'groups'   => $groups,
				'services' => $services,
				'atts'     => $atts,
			)
		);
	}

	/**
	 * [service_status_incidents] - active + recent resolved incidents.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_incidents( $atts ) {
		$atts = $this->normalize_atts( (array) $atts );

		$active   = IncidentManager::get_active_incidents();
		$resolved = IncidentManager::get_recent_resolved( (int) $atts['count'] );

		return $this->render(
			'incidents',
			array(
				'active_incidents'   => $active,
				'resolved_incidents' => $resolved,
				'atts'               => $atts,
			)
		);
	}

	/**
	 * [service_status_maintenance] - upcoming + recently completed maintenance.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_maintenance( $atts ) {
		$atts = $this->normalize_atts( (array) $atts );

		$upcoming = MaintenanceManager::get_upcoming();
		$past     = MaintenanceManager::get_recent_completed( (int) $atts['count'] );

		return $this->render(
			'maintenance',
			array(
				'upcoming_maintenance' => $upcoming,
				'past_maintenance'     => $past,
				'atts'                 => $atts,
			)
		);
	}

	/**
	 * [service_status_subscribe] - subscription form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_subscribe( $atts ) {
		$atts = $this->normalize_atts( (array) $atts );

		$groups   = ServiceManager::get_groups();
		$services = ServiceManager::get_services( array( 'show_on_status_page' => 1 ) );

		$notice = '';
		if ( isset( $_GET['ssm_subscribed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice = ( 'error' === $_GET['ssm_subscribed'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				? __( 'We could not process your subscription. Please check your details and try again.', 'service-status-manager' )
				: __( 'Thank you - please check your email (or other selected channel) to confirm your subscription.', 'service-status-manager' );
		}

		return $this->render(
			'subscribe-form',
			array(
				'groups'   => $groups,
				'services' => $services,
				'notice'   => $notice,
				'atts'     => $atts,
			)
		);
	}

	/**
	 * [service_status_history] - uptime bars and incident history.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_history( $atts ) {
		$atts = $this->normalize_atts( (array) $atts );

		$services = ServiceManager::get_services( array( 'show_on_status_page' => 1 ) );
		if ( $atts['service'] ) {
			$services = array_values( array_filter( $services, fn( $s ) => $s->slug === $atts['service'] ) );
		}

		$monitors_by_service = array();
		foreach ( $services as $service ) {
			$monitors_by_service[ $service->id ] = array_filter( MonitorManager::get_monitors_for_service( $service->id ), fn( $m ) => $m->is_public );
		}

		return $this->render(
			'history',
			array(
				'services'            => $services,
				'monitors_by_service' => $monitors_by_service,
				'range_days'          => (int) $atts['range'],
				'atts'                => $atts,
			)
		);
	}
}
