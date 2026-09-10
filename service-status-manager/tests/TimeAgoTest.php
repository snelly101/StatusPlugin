<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers ::ssm_time_ago
 */
final class TimeAgoTest extends TestCase {

	public function test_empty_input_returns_empty_string() {
		$this->assertSame( '', ssm_time_ago( '' ) );
	}

	public function test_very_recent_returns_just_now() {
		$this->assertSame( 'just now', ssm_time_ago( gmdate( 'Y-m-d H:i:s' ) ) );
	}

	public function test_minutes_ago_is_formatted_with_ago_suffix() {
		$this->assertSame( '5 minutes ago', ssm_time_ago( gmdate( 'Y-m-d H:i:s', time() - 300 ) ) );
	}

	public function test_hours_ago_is_formatted_with_ago_suffix() {
		$this->assertSame( '3 hours ago', ssm_time_ago( gmdate( 'Y-m-d H:i:s', time() - ( 3 * HOUR_IN_SECONDS ) ) ) );
	}
}
