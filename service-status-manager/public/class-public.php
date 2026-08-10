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
use ServiceStatusManager\StatusPageManager;
use ServiceStatusManager\Capabilities;

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
		add_action( 'template_redirect', array( $this, 'guard_private_status_page' ), 5 );
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
	 * Gates access to a page containing [service_status_page] when the
	 * plugin's main status page has been marked private. Runs on
	 * template_redirect (before any output) so it can safely set a cookie
	 * after a successful password check and issue a redirect.
	 *
	 * Logged-in users with the plugin's "view" capability (any of the
	 * plugin's roles, or an administrator) always have access, satisfying
	 * the "WordPress user authentication" option from the private-page
	 * requirements; a shared password provides the "password protection"
	 * option for visitors without a WordPress account.
	 */
	public function guard_private_status_page() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post || ! has_shortcode( $post->post_content, 'service_status_page' ) ) {
			return;
		}

		$page = StatusPageManager::get_page_by_slug( 'main' );
		if ( ! $page || 'private' !== $page->visibility ) {
			return;
		}

		if ( current_user_can( Capabilities::VIEW ) ) {
			return;
		}

		if ( empty( $page->password_hash ) ) {
			auth_redirect();
			return;
		}

		$cookie_name = 'ssm_page_auth_' . $page->id;
		$expected    = hash_hmac( 'sha256', $page->id . '|' . $page->password_hash, wp_salt( 'auth' ) );

		if ( isset( $_COOKIE[ $cookie_name ] ) && hash_equals( $expected, wp_unslash( $_COOKIE[ $cookie_name ] ) ) ) {
			return;
		}

		if ( ! empty( $_POST['ssm_page_password'] ) && isset( $_POST['ssm_page_password_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ssm_page_password_nonce'] ) ), 'ssm_page_password_' . $page->id ) ) {
			if ( ! RateLimiter::allow( 'status_page_password_' . RateLimiter::client_fingerprint(), 10, 10 * MINUTE_IN_SECONDS ) ) {
				wp_die( esc_html__( 'Too many attempts. Please try again later.', 'service-status-manager' ), '', array( 'response' => 429 ) );
			}

			if ( wp_check_password( wp_unslash( $_POST['ssm_page_password'] ), $page->password_hash ) ) {
				$secure = is_ssl();
				setcookie( $cookie_name, $expected, time() + 12 * HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, $secure, true );
				wp_safe_redirect( home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ?? '/' ) ) );
				exit;
			}
		}

		require SSM_PLUGIN_DIR . 'public/templates/password-gate.php';
		exit;
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
