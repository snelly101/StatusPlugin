<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers ::ssm_format_datetime
 */
final class FormatDatetimeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ssm_test_options'] = array();
	}

	public function test_default_format_uses_site_date_and_time_format_options() {
		$GLOBALS['ssm_test_options']['date_format'] = 'Y-m-d';
		$GLOBALS['ssm_test_options']['time_format'] = 'H:i';

		$this->assertSame( '2026-01-21 15:00', ssm_format_datetime( '2026-01-21 15:00:00' ) );
	}

	/**
	 * The SMS-specific short d/m/y format used by
	 * NotificationManager::notify_for_maintenance() for its sms_summary -
	 * "21/1/26", not "21/01/2026" or the US-style "1/21/26".
	 */
	public function test_custom_short_uk_format_matches_requested_style() {
		$this->assertSame( '21/1/26', ssm_format_datetime( '2026-01-21 15:00:00', 'j/n/y' ) );
		$this->assertSame( '5/3/26', ssm_format_datetime( '2026-03-05 09:00:00', 'j/n/y' ) );
	}

	public function test_custom_short_uk_format_with_compact_time() {
		$this->assertSame( '21/1/26 3:00pm', ssm_format_datetime( '2026-01-21 15:00:00', 'j/n/y g:ia' ) );
	}

	public function test_empty_datetime_returns_empty_string() {
		$this->assertSame( '', ssm_format_datetime( '' ) );
		$this->assertSame( '', ssm_format_datetime( '0000-00-00 00:00:00' ) );
	}
}
