<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\Notifications\NotificationQueue;

/**
 * @covers \ServiceStatusManager\Notifications\NotificationQueue
 */
final class NotificationQueueStalenessTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['ssm_test_options']['ssm_settings'] );
	}

	/**
	 * NotificationQueue::is_stale() is private (dispatch_row()/
	 * dispatch_concurrent() are the only real callers, and both need
	 * $wpdb) - reflection lets this pure date-math/settings check be
	 * tested directly without dragging in a database.
	 */
	private function is_stale( $row ) {
		$method = new ReflectionMethod( NotificationQueue::class, 'is_stale' );
		$method->setAccessible( true );
		return $method->invoke( null, $row );
	}

	private function row( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'event_type' => 'incident_created',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			$overrides
		);
	}

	public function test_a_fresh_row_is_not_stale() {
		$this->assertFalse( $this->is_stale( $this->row() ) );
	}

	public function test_a_row_older_than_the_default_24h_cutoff_is_stale() {
		$row = $this->row( array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 25 * HOUR_IN_SECONDS ) ) ) );
		$this->assertTrue( $this->is_stale( $row ) );
	}

	public function test_a_row_just_under_the_default_cutoff_is_not_stale() {
		$row = $this->row( array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 23 * HOUR_IN_SECONDS ) ) ) );
		$this->assertFalse( $this->is_stale( $row ) );
	}

	public function test_zero_disables_the_cutoff_entirely() {
		update_option( 'ssm_settings', array( 'notification_max_age_hours' => 0 ) );
		$row = $this->row( array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 1000 * HOUR_IN_SECONDS ) ) ) );
		$this->assertFalse( $this->is_stale( $row ) );
	}

	public function test_the_cutoff_is_configurable() {
		update_option( 'ssm_settings', array( 'notification_max_age_hours' => 1 ) );
		$row = $this->row( array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) ) ) );
		$this->assertTrue( $this->is_stale( $row ) );
	}

	/**
	 * Transactional per-subscriber messages (confirm your subscription,
	 * here's your management link) stay useful indefinitely - expiring one
	 * could leave a subscriber permanently unable to confirm/manage, which
	 * is worse than a late delivery.
	 */
	public function test_verification_and_management_messages_never_expire() {
		$ancient = gmdate( 'Y-m-d H:i:s', time() - ( 1000 * HOUR_IN_SECONDS ) );

		foreach ( array( 'subscription_confirmation', 'teams_verify', 'management_link' ) as $event_type ) {
			$row = $this->row( array( 'event_type' => $event_type, 'created_at' => $ancient ) );
			$this->assertFalse( $this->is_stale( $row ), "{$event_type} should never expire" );
		}
	}
}
