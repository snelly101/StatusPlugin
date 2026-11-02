<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\SubscriberManager;

/**
 * @covers \ServiceStatusManager\SubscriberManager::offered_channels
 */
final class OfferedChannelsTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['ssm_test_options']['ssm_settings'] );
	}

	public function test_teams_is_offered_by_default() {
		$this->assertSame( array( 'email', 'sms', 'teams' ), SubscriberManager::offered_channels() );
	}

	public function test_teams_is_removed_when_disabled() {
		update_option( 'ssm_settings', array( 'teams_channel_enabled' => false ) );
		$this->assertSame( array( 'email', 'sms' ), SubscriberManager::offered_channels() );
	}

	public function test_email_and_sms_are_always_offered() {
		update_option( 'ssm_settings', array( 'teams_channel_enabled' => false ) );
		$this->assertContains( 'email', SubscriberManager::offered_channels() );
		$this->assertContains( 'sms', SubscriberManager::offered_channels() );
	}
}
