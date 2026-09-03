<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\NotificationManager;

/**
 * @covers \ServiceStatusManager\NotificationManager
 */
final class NotificationManagerPriorityTest extends TestCase {

	public function test_critical_incident_is_highest_priority() {
		$this->assertSame( 0, NotificationManager::priority_for( 'incident_created', 'critical' ) );
		$this->assertSame( 0, NotificationManager::priority_for( 'incident_updated', 'critical' ) );
	}

	public function test_incident_severity_maps_to_descending_priority() {
		$this->assertSame( 1, NotificationManager::priority_for( 'incident_created', 'major' ) );
		$this->assertSame( 2, NotificationManager::priority_for( 'incident_created', 'minor' ) );
		$this->assertSame( 3, NotificationManager::priority_for( 'incident_created', 'informational' ) );
	}

	public function test_incident_resolved_is_always_informational_priority_regardless_of_severity() {
		$this->assertSame( 3, NotificationManager::priority_for( 'incident_resolved', 'critical' ) );
	}

	public function test_transactional_subscriber_messages_outrank_routine_maintenance_notices() {
		$verification = NotificationManager::priority_for( 'subscription_confirmation' );
		$management   = NotificationManager::priority_for( 'management_link' );
		$announced    = NotificationManager::priority_for( 'maintenance_announced' );

		$this->assertLessThan( $announced, $verification );
		$this->assertLessThan( $announced, $management );
	}

	public function test_a_critical_incident_never_waits_behind_a_routine_maintenance_reminder() {
		$critical_incident      = NotificationManager::priority_for( 'incident_created', 'critical' );
		$maintenance_reminder   = NotificationManager::priority_for( 'maintenance_reminder' );

		$this->assertLessThan( $maintenance_reminder, $critical_incident );
	}

	public function test_unknown_event_type_falls_back_to_the_default_priority() {
		$this->assertSame( 3, NotificationManager::priority_for( 'some_future_event_type' ) );
	}
}
