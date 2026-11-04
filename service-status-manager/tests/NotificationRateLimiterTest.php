<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\Notifications\NotificationRateLimiter;

/**
 * @covers \ServiceStatusManager\Notifications\NotificationRateLimiter::reserve_capacity
 */
final class NotificationRateLimiterTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ssm_test_cache']      = array();
		$GLOBALS['ssm_test_transients'] = array();

		// The limiter's window is the wall-clock second; start each test at
		// the top of a fresh one so two calls can't straddle a boundary.
		$second = time();
		while ( time() === $second ) {
			usleep( 10000 );
		}
	}

	public function test_grants_up_to_the_per_second_limit_then_refuses_within_the_same_second() {
		$this->assertSame( 10, NotificationRateLimiter::reserve_capacity( 'provider-a', 10, 15 ) );
		$this->assertSame( 0, NotificationRateLimiter::reserve_capacity( 'provider-a', 10, 5 ) );
	}

	public function test_providers_have_independent_buckets() {
		NotificationRateLimiter::reserve_capacity( 'provider-a', 10, 10 );
		$this->assertSame( 5, NotificationRateLimiter::reserve_capacity( 'provider-b', 10, 5 ) );
	}

	public function test_zero_or_negative_limit_means_unlimited() {
		$this->assertSame( 500, NotificationRateLimiter::reserve_capacity( 'provider-a', 0, 500 ) );
		$this->assertSame( 500, NotificationRateLimiter::reserve_capacity( 'provider-a', -1, 500 ) );
	}

	public function test_capacity_returns_in_the_next_second() {
		$this->assertSame( 10, NotificationRateLimiter::reserve_capacity( 'provider-a', 10, 10 ) );
		$this->assertSame( 0, NotificationRateLimiter::reserve_capacity( 'provider-a', 10, 1 ) );

		$second = time();
		while ( time() === $second ) {
			usleep( 10000 );
		}

		$this->assertSame( 10, NotificationRateLimiter::reserve_capacity( 'provider-a', 10, 10 ) );
	}
}
