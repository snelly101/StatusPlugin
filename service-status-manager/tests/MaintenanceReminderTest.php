<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\MaintenanceManager;

/**
 * @covers \ServiceStatusManager\MaintenanceManager
 */
final class MaintenanceReminderTest extends TestCase {

	/**
	 * unreachable_reminder_keys() is private - it's pure date math with no
	 * $wpdb dependency, so reflection lets it be tested directly.
	 */
	private function unreachable( $scheduled_start, array $reminder_hours ) {
		$method = new ReflectionMethod( MaintenanceManager::class, 'unreachable_reminder_keys' );
		$method->setAccessible( true );
		return $method->invoke( null, $scheduled_start, $reminder_hours );
	}

	public function test_a_window_far_enough_out_leaves_every_reminder_reachable() {
		$start = gmdate( 'Y-m-d H:i:s', time() + ( 48 * HOUR_IN_SECONDS ) );
		$this->assertSame( array(), $this->unreachable( $start, array( 24, 1 ) ) );
	}

	public function test_a_window_starting_in_30_minutes_makes_both_1h_and_24h_reminders_unreachable() {
		// This is the reported bug: scheduling something 30 minutes out
		// with 24h/1h reminders configured used to fire both reminders
		// immediately on the next cron tick instead of skipping them.
		$start = gmdate( 'Y-m-d H:i:s', time() + ( 30 * MINUTE_IN_SECONDS ) );
		$this->assertSame( array( 'h24', 'h1' ), $this->unreachable( $start, array( 24, 1 ) ) );
	}

	public function test_a_window_starting_in_90_minutes_leaves_the_1h_reminder_reachable_but_not_24h() {
		$start = gmdate( 'Y-m-d H:i:s', time() + ( 90 * MINUTE_IN_SECONDS ) );
		$this->assertSame( array( 'h24' ), $this->unreachable( $start, array( 24, 1 ) ) );
	}

	public function test_a_window_already_in_the_past_makes_every_reminder_unreachable() {
		$start = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$this->assertSame( array( 'h24', 'h1' ), $this->unreachable( $start, array( 24, 1 ) ) );
	}

	public function test_no_configured_reminders_means_nothing_is_unreachable() {
		$start = gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS );
		$this->assertSame( array(), $this->unreachable( $start, array() ) );
	}
}
