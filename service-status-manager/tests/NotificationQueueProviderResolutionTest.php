<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\Notifications\NotificationQueue;
use ServiceStatusManager\Notifications\NotificationCircuitBreaker;

/**
 * @covers \ServiceStatusManager\Notifications\NotificationQueue
 */
final class NotificationQueueProviderResolutionTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['ssm_test_options']['ssm_settings'] );
		unset( $GLOBALS['ssm_test_options'][ NotificationCircuitBreaker::OPTION_KEY ] );
	}

	public function test_defaults_to_wp_mail_when_smtp2go_not_configured() {
		$this->assertSame( 'wp_mail', NotificationQueue::resolve_provider_slug( 'email' ) );
	}

	public function test_uses_smtp2go_when_configured_and_healthy() {
		update_option( 'ssm_settings', array( 'email_provider' => 'smtp2go' ) );

		$this->assertSame( 'smtp2go', NotificationQueue::resolve_provider_slug( 'email' ) );
	}

	public function test_falls_back_to_wp_mail_when_smtp2go_breaker_is_open_and_fallback_enabled() {
		update_option(
			'ssm_settings',
			array( 'email_provider' => 'smtp2go', 'smtp2go_fallback_to_wp_mail' => true )
		);

		for ( $i = 0; $i < 5; $i++ ) {
			NotificationCircuitBreaker::record_failure( 'smtp2go', \ServiceStatusManager\Notifications\NotificationRetryPolicy::TRANSIENT );
		}

		$this->assertTrue( NotificationCircuitBreaker::is_open( 'smtp2go' ) );
		$this->assertSame( 'wp_mail', NotificationQueue::resolve_provider_slug( 'email' ) );
	}

	public function test_stays_on_smtp2go_when_breaker_open_but_fallback_disabled() {
		update_option(
			'ssm_settings',
			array( 'email_provider' => 'smtp2go', 'smtp2go_fallback_to_wp_mail' => false )
		);

		NotificationCircuitBreaker::force_open( 'smtp2go' );

		$this->assertSame( 'smtp2go', NotificationQueue::resolve_provider_slug( 'email' ) );
	}

	public function test_sms_resolves_to_configured_sms_provider_setting() {
		update_option( 'ssm_settings', array( 'sms_provider' => 'twilio' ) );

		$this->assertSame( 'twilio', NotificationQueue::resolve_provider_slug( 'sms' ) );
	}

	public function test_teams_resolves_to_itself() {
		$this->assertSame( 'teams', NotificationQueue::resolve_provider_slug( 'teams' ) );
	}
}
