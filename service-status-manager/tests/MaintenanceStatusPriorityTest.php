<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\StatusCalculator;

/**
 * Guards the behaviour ServiceManager::compute_status() now relies on: a
 * live maintenance window, added as a status candidate alongside the
 * monitor-derived status, must win over "operational" - otherwise the
 * service silently keeps showing Operational while under maintenance.
 *
 * @covers \ServiceStatusManager\StatusCalculator::highest_priority
 */
final class MaintenanceStatusPriorityTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['ssm_test_options']['ssm_settings'] );
	}

	public function test_maintenance_outranks_operational() {
		$this->assertSame( 'maintenance', StatusCalculator::highest_priority( array( 'operational', 'maintenance' ) ) );
	}

	public function test_a_real_outage_still_outranks_maintenance() {
		$this->assertSame( 'major_outage', StatusCalculator::highest_priority( array( 'maintenance', 'major_outage' ) ) );
	}

	public function test_maintenance_label_and_colour() {
		$def = ssm_get_status_definition( 'maintenance' );
		$this->assertSame( 'Maintenance in progress', $def['label'] );
		$this->assertSame( 'ssm-status-maintenance', $def['css_class'] );
		$this->assertSame( '#6366f1', $def['color'] );
	}
}
