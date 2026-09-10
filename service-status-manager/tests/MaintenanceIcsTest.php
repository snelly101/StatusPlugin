<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers ::ssm_build_maintenance_ics
 * @covers ::ssm_ics_escape
 * @covers ::ssm_ics_fold
 * @covers ::ssm_ics_datetime
 */
final class MaintenanceIcsTest extends TestCase {

	private function make_event( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'title'           => 'Database upgrade',
				'slug'            => 'database-upgrade',
				'description'     => "We will be upgrading the database.\nExpect brief blips.",
				'impact'          => 'minor',
				'scheduled_start' => '2026-01-21 14:00:00',
				'scheduled_end'   => '2026-01-21 16:00:00',
			),
			$overrides
		);
	}

	public function test_builds_a_valid_vevent_with_required_fields() {
		$ics = ssm_build_maintenance_ics( $this->make_event(), 'https://example.com/status/#ssm-maintenance-database-upgrade' );

		$this->assertStringContainsString( "BEGIN:VCALENDAR\r\n", $ics );
		$this->assertStringContainsString( "BEGIN:VEVENT\r\n", $ics );
		$this->assertStringContainsString( 'DTSTART:20260121T140000Z', $ics );
		$this->assertStringContainsString( 'DTEND:20260121T160000Z', $ics );
		$this->assertStringContainsString( 'SUMMARY:Database upgrade', $ics );
		$this->assertStringContainsString( 'UID:ssm-maintenance-database-upgrade@', $ics );
		$this->assertStringContainsString( "END:VEVENT\r\n", $ics );
		$this->assertStringContainsString( "END:VCALENDAR\r\n", $ics );
	}

	public function test_description_includes_plain_language_impact_and_url() {
		$ics = ssm_build_maintenance_ics( $this->make_event(), 'https://example.com/#ssm-maintenance-database-upgrade' );

		$this->assertStringContainsString( 'brief or partial disruption possible', $ics );
		$this->assertStringContainsString( 'https://example.com/#ssm-maintenance-database-upgrade', $ics );
	}

	public function test_no_impact_line_when_impact_is_none() {
		$ics = ssm_build_maintenance_ics( $this->make_event( array( 'impact' => 'none' ) ) );

		$this->assertStringNotContainsString( 'disruption possible', $ics );
		$this->assertStringNotContainsString( 'unavailable', $ics );
	}

	public function test_escape_handles_commas_semicolons_backslashes_and_newlines() {
		$this->assertSame( 'a\\,b\\;c\\\\d\\ne', ssm_ics_escape( "a,b;c\\d\ne" ) );
	}

	public function test_fold_leaves_short_lines_untouched() {
		$this->assertSame( 'SUMMARY:short', ssm_ics_fold( 'SUMMARY:short' ) );
	}

	public function test_fold_wraps_long_lines_at_75_octets_with_leading_space_continuation() {
		$long  = 'DESCRIPTION:' . str_repeat( 'x', 100 );
		$fold  = ssm_ics_fold( $long );
		$lines = explode( "\r\n", $fold );

		$this->assertGreaterThan( 1, count( $lines ) );
		$this->assertSame( 75, strlen( $lines[0] ) );
		$this->assertStringStartsWith( ' ', $lines[1] );
	}

	public function test_ics_datetime_converts_utc_mysql_string() {
		$this->assertSame( '20260121T140000Z', ssm_ics_datetime( '2026-01-21 14:00:00' ) );
	}
}
