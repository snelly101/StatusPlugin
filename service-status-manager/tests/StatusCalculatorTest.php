<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\StatusCalculator;

/**
 * @covers \ServiceStatusManager\StatusCalculator
 */
final class StatusCalculatorTest extends TestCase {

	public function test_service_status_from_monitors_picks_most_severe() {
		$this->assertSame(
			'major_outage',
			StatusCalculator::calculate_service_status( array( 'operational', 'degraded', 'major_outage' ) )
		);
	}

	public function test_service_status_from_monitors_prefers_partial_over_degraded() {
		$this->assertSame(
			'partial_outage',
			StatusCalculator::calculate_service_status( array( 'operational', 'degraded', 'partial_outage' ) )
		);
	}

	public function test_service_status_defaults_to_unknown_when_no_monitors() {
		$this->assertSame( 'unknown', StatusCalculator::calculate_service_status( array() ) );
	}

	public function test_overall_status_is_operational_when_all_services_operational() {
		$services = array(
			(object) array( 'status' => 'operational', 'exclude_from_overall' => 0 ),
			(object) array( 'status' => 'operational', 'exclude_from_overall' => 0 ),
		);

		$this->assertSame( 'operational', StatusCalculator::calculate_overall_status( $services ) );
	}

	public function test_overall_status_reflects_worst_included_service() {
		$services = array(
			(object) array( 'status' => 'operational', 'exclude_from_overall' => 0 ),
			(object) array( 'status' => 'major_outage', 'exclude_from_overall' => 0 ),
			(object) array( 'status' => 'degraded', 'exclude_from_overall' => 0 ),
		);

		$this->assertSame( 'major_outage', StatusCalculator::calculate_overall_status( $services ) );
	}

	public function test_overall_status_ignores_excluded_services() {
		$services = array(
			(object) array( 'status' => 'operational', 'exclude_from_overall' => 0 ),
			(object) array( 'status' => 'major_outage', 'exclude_from_overall' => 1 ),
		);

		$this->assertSame( 'operational', StatusCalculator::calculate_overall_status( $services ) );
	}

	public function test_overall_status_with_no_services_is_operational() {
		$this->assertSame( 'operational', StatusCalculator::calculate_overall_status( array() ) );
	}

	public function test_maintenance_outranks_unknown_but_not_outages() {
		$services = array(
			(object) array( 'status' => 'maintenance', 'exclude_from_overall' => 0 ),
			(object) array( 'status' => 'unknown', 'exclude_from_overall' => 0 ),
		);

		$this->assertSame( 'maintenance', StatusCalculator::calculate_overall_status( $services ) );

		$services[] = (object) array( 'status' => 'partial_outage', 'exclude_from_overall' => 0 );
		$this->assertSame( 'partial_outage', StatusCalculator::calculate_overall_status( $services ) );
	}
}
