<?php
/**
 * Optional built-in SMTP transport configuration.
 *
 * Email notifications are still sent entirely through wp_mail() (see
 * Notifications\EmailProvider) - this class does not replace that. It
 * simply configures PHPMailer, via the same `phpmailer_init` hook any
 * SMTP-configuration plugin uses, to relay through a specific SMTP
 * server when an administrator has provided one under Settings, rather
 * than requiring a separate SMTP plugin. When enabled, it affects every
 * outbound `wp_mail()` call on the site (not just this plugin's own
 * notifications) - the same scope a dedicated SMTP plugin would have.
 *
 * If a separate SMTP-configuration plugin is also active, the two will
 * compete for the same hook; the settings screen warns about this.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Smtp {

	/**
	 * Registers the phpmailer_init hook, only when SMTP relay is enabled
	 * and a host has been configured.
	 */
	public static function register() {
		$settings = ssm_get_settings();

		if ( empty( $settings['smtp_enabled'] ) || empty( $settings['smtp_host'] ) ) {
			return;
		}

		add_action( 'phpmailer_init', array( __CLASS__, 'configure' ) );

		if ( ! empty( $settings['smtp_force_from'] ) ) {
			add_filter( 'wp_mail_from', array( __CLASS__, 'filter_from_email' ) );
			add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ) );
		}
	}

	/**
	 * Configures PHPMailer to relay through the configured SMTP server.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance, by reference via the hook.
	 */
	public static function configure( $phpmailer ) {
		$settings = ssm_get_settings();

		$phpmailer->isSMTP();
		$phpmailer->Host       = $settings['smtp_host']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->Port       = max( 1, (int) $settings['smtp_port'] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->SMTPAuth   = ! empty( $settings['smtp_auth'] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$encryption = in_array( $settings['smtp_encryption'] ?? 'tls', array( 'none', 'ssl', 'tls' ), true ) ? $settings['smtp_encryption'] : 'tls';
		if ( 'none' === $encryption ) {
			$phpmailer->SMTPSecure  = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$phpmailer->SMTPAutoTLS = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} else {
			$phpmailer->SMTPSecure = $encryption; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		if ( $phpmailer->SMTPAuth ) {
			$phpmailer->Username = $settings['smtp_username']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$phpmailer->Password = Encryption::decrypt( $settings['smtp_password_encrypted'] ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	/**
	 * @param string $email Existing From address.
	 * @return string
	 */
	public static function filter_from_email( $email ) {
		$configured = ssm_get_setting( 'from_email' );
		return $configured ? sanitize_email( $configured ) : $email;
	}

	/**
	 * @param string $name Existing From name.
	 * @return string
	 */
	public static function filter_from_name( $name ) {
		$configured = ssm_get_setting( 'from_name' );
		return $configured ? $configured : $name;
	}
}
