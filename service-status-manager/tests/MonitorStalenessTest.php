<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\MonitorManager;

/**
 * @covers \ServiceStatusManager\MonitorManager::is_stale
 */
final class MonitorStalenessTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ssm_test_options'] = array();
	}

	private function monitor( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'type'            => 'http',
				'is_active'       => 1,
				'last_checked_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			$overrides
		);
	}

	public function test_manual_monitor_is_never_stale() {
		$this->assertFalse( MonitorManager::is_stale( $this->monitor( array( 'type' => 'manual', 'last_checked_at' => null ) ) ) );
	}

	public function test_inactive_monitor_is_never_stale() {
		$this->assertFalse( MonitorManager::is_stale( $this->monitor( array( 'is_active' => 0, 'last_checked_at' => null ) ) ) );
	}

	public function test_active_automated_monitor_never_checked_is_stale() {
		$this->assertTrue( MonitorManager::is_stale( $this->monitor( array( 'last_checked_at' => null ) ) ) );
	}

	public function test_recently_checked_monitor_is_not_stale() {
		$monitor = $this->monitor( array( 'last_checked_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
		$this->assertFalse( MonitorManager::is_stale( $monitor ) );
	}

	public function test_monitor_checked_beyond_threshold_is_stale() {
		$GLOBALS['ssm_test_options']['ssm_settings'] = array( 'stale_data_threshold_minutes' => 10 );

		$monitor = $this->monitor( array( 'last_checked_at' => gmdate( 'Y-m-d H:i:s', time() - ( 20 * MINUTE_IN_SECONDS ) ) ) );
		$this->assertTrue( MonitorManager::is_stale( $monitor ) );

		$monitor = $this->monitor( array( 'last_checked_at' => gmdate( 'Y-m-d H:i:s', time() - ( 5 * MINUTE_IN_SECONDS ) ) ) );
		$this->assertFalse( MonitorManager::is_stale( $monitor ) );
	}
}
