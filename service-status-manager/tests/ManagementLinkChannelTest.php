<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\NotificationManager;

/**
 * @covers \ServiceStatusManager\NotificationManager::pick_management_link_channel
 */
final class ManagementLinkChannelTest extends TestCase {

	private function channel( $slug, $active = 1, $verified = 1 ) {
		return (object) array( 'channel' => $slug, 'is_active' => $active, 'verified' => $verified );
	}

	public function test_sms_only_subscriber_gets_sms_not_email() {
		$this->assertSame( 'sms', NotificationManager::pick_management_link_channel( array( $this->channel( 'sms' ) ) ) );
	}

	public function test_email_preferred_over_sms_when_both_verified() {
		$channels = array( $this->channel( 'sms' ), $this->channel( 'email' ) );
		$this->assertSame( 'email', NotificationManager::pick_management_link_channel( $channels ) );
	}

	public function test_verified_sms_beats_unverified_email() {
		$channels = array( $this->channel( 'email', 1, 0 ), $this->channel( 'sms', 1, 1 ) );
		$this->assertSame( 'sms', NotificationManager::pick_management_link_channel( $channels ) );
	}

	public function test_falls_back_to_unverified_channel_when_nothing_verified() {
		$this->assertSame( 'email', NotificationManager::pick_management_link_channel( array( $this->channel( 'email', 1, 0 ) ) ) );
	}

	public function test_inactive_channels_are_never_used() {
		$channels = array( $this->channel( 'email', 0, 1 ), $this->channel( 'teams', 1, 1 ) );
		$this->assertSame( 'teams', NotificationManager::pick_management_link_channel( $channels ) );
	}

	public function test_no_usable_channel_returns_null() {
		$this->assertNull( NotificationManager::pick_management_link_channel( array( $this->channel( 'email', 0, 1 ) ) ) );
		$this->assertNull( NotificationManager::pick_management_link_channel( array() ) );
	}
}
