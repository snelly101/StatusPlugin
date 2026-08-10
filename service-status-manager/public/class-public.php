<?php
/**
 * Public-facing controller: asset loading and handling of token-based
 * subscriber links (confirm / unsubscribe / manage / Teams verify) plus
 * the public subscription form submission.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Publicweb;

use ServiceStatusManager\SubscriberManager;
use ServiceStatusManager\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PublicController {

	const QUERY_VAR = 'ssm_action';

	/**
	 * Registers public-facing hooks.
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_token_actions' ) );

		add_action( 'admin_post_nopriv_ssm_public_subscribe', array( $this, 'handle_subscribe' ) );
		add_action( 'admin_post_ssm_public_subscribe', array( $this, 'handle_subscribe' ) );
		add_action( 'admin_post_nopriv_ssm_update_subscription', array( $this, 'handle_update_subscription' ) );
		add_action( 'admin_post_ssm_update_subscription', array( $this, 'handle_update_subscription' ) );
		add_action( 'admin_post_nopriv_ssm_resend_confirmation', array( $this, 'handle_resend_confirmation' ) );
		add_action( 'admin_post_ssm_resend_confirmation', array( $this, 'handle_resend_confirmation' ) );
	}

	/**
	 * Registers the ssm_action/ssm_token/ssm_id query vars used for
	 * subscriber confirmation, unsubscribe and management links.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = 'ssm_token';
		$vars[] = 'ssm_id';
		return $vars;
	}

	/**
	 * Enqueues public CSS/JS. Loaded on every front-end page since
	 * shortcodes can appear inside widgets, page builders, etc. where
	 * has_shortcode() cannot reliably detect them; the payload is small.
	 */
	public function enqueue_assets() {
		wp_enqueue_style( 'ssm-public', SSM_PLUGIN_URL . 'public/css/public.css', array(), SSM_VERSION );
		wp_enqueue_script( 'ssm-public', SSM_PLUGIN_URL . 'public/js/public.js', array(), SSM_VERSION, true );
		wp_localize_script(
			'ssm-public',
			'ssmPublic',
			array(
				'restUrl' => esc_url_raw( rest_url( 'service-status-manager/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Handles confirm/unsubscribe/manage/teams-verify links reached via
	 * ?ssm_action=confirm&ssm_id=..&ssm_token=... on any front-end page.
	 */
	public function handle_token_actions() {
		$action = get_query_var( self::QUERY_VAR );
		if ( ! $action ) {
			return;
		}

		$subscriber_id = absint( get_query_var( 'ssm_id' ) );
		$token         = sanitize_text_field( get_query_var( 'ssm_token' ) );

		if ( ! in_array( $action, array( 'confirm', 'unsubscribe', 'manage', 'teams_verify' ), true ) || ! $subscriber_id || '' === $token ) {
			wp_die( esc_html__( 'This link is invalid.', 'service-status-manager' ), '', array( 'response' => 400 ) );
		}

		if ( ! RateLimiter::allow( 'token_action_' . $subscriber_id, 20, HOUR_IN_SECONDS ) ) {
			wp_die( esc_html__( 'Too many attempts. Please try again later.', 'service-status-manager' ), '', array( 'response' => 429 ) );
		}

		$result = SubscriberManager::handle_token_action( $action, $subscriber_id, $token );

		require SSM_PLUGIN_DIR . 'public/templates/subscription-result.php';
		exit;
	}

	/**
	 * Handles the public subscription form submission.
	 */
	public function handle_subscribe() {
		check_admin_referer( 'ssm_public_subscribe' );

		if ( ! RateLimiter::allow( 'subscribe_' . RateLimiter::client_fingerprint(), 5, 10 * MINUTE_IN_SECONDS ) ) {
			wp_die( esc_html__( 'Too many attempts. Please try again later.', 'service-status-manager' ), '', array( 'response' => 429 ) );
		}

		$result = SubscriberManager::handle_public_subscription( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$redirect = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		$redirect = add_query_arg( 'ssm_subscribed', is_wp_error( $result ) ? 'error' : '1', $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handles updates to an existing subscription from the "manage
	 * subscription" page (channel/service selection changes, pause, full
	 * unsubscribe).
	 */
	public function handle_update_subscription() {
		check_admin_referer( 'ssm_update_subscription' );

		$subscriber_id = absint( $_POST['subscriber_id'] ?? 0 );
		$token         = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );

		if ( ! RateLimiter::allow( 'manage_' . $subscriber_id, 20, HOUR_IN_SECONDS ) ) {
			wp_die( esc_html__( 'Too many attempts. Please try again later.', 'service-status-manager' ), '', array( 'response' => 429 ) );
		}

		SubscriberManager::update_subscription_from_management_form( $subscriber_id, $token, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$redirect = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		wp_safe_redirect( add_query_arg( 'ssm_updated', '1', $redirect ) );
		exit;
	}

	/**
	 * Handles a "resend confirmation email" request without revealing
	 * whether the supplied address is actually registered.
	 */
	public function handle_resend_confirmation() {
		check_admin_referer( 'ssm_resend_confirmation' );

		if ( ! RateLimiter::allow( 'resend_' . RateLimiter::client_fingerprint(), 3, 10 * MINUTE_IN_SECONDS ) ) {
			wp_die( esc_html__( 'Too many attempts. Please try again later.', 'service-status-manager' ), '', array( 'response' => 429 ) );
		}

		SubscriberManager::resend_confirmation( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );

		$redirect = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		wp_safe_redirect( add_query_arg( 'ssm_resent', '1', $redirect ) );
		exit;
	}
}
