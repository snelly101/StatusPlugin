<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\Notifications\NotificationCircuitBreaker;
use ServiceStatusManager\Notifications\NotificationRetryPolicy;

/**
 * @covers \ServiceStatusManager\Notifications\NotificationCircuitBreaker
 */
final class NotificationCircuitBreakerTest extends TestCase {

	protected function setUp(): void {
		// The breaker's state lives in a single get_option()/update_option()
		// value (see bootstrap.php's stubs, backed by $GLOBALS) - reset it
		// between tests so they don't leak state into each other.
		unset( $GLOBALS['ssm_test_options'][ NotificationCircuitBreaker::OPTION_KEY ] );
	}

	public function test_starts_closed() {
		$this->assertFalse( NotificationCircuitBreaker::is_open( 'twilio' ) );
	}

	public function test_stays_closed_below_the_failure_threshold() {
		for ( $i = 0; $i < 4; $i++ ) {
			NotificationCircuitBreaker::record_failure( 'twilio', NotificationRetryPolicy::TRANSIENT );
		}

		$this->assertFalse( NotificationCircuitBreaker::is_open( 'twilio' ) );
	}

	public function test_trips_open_once_the_transient_failure_threshold_is_reached() {
		for ( $i = 0; $i < 5; $i++ ) {
			NotificationCircuitBreaker::record_failure( 'twilio', NotificationRetryPolicy::TRANSIENT );
		}

		$this->assertTrue( NotificationCircuitBreaker::is_open( 'twilio' ) );
	}

	public function test_account_level_failure_force_opens_immediately_on_the_first_occurrence() {
		NotificationCircuitBreaker::record_failure( 'smtp2go', NotificationRetryPolicy::PERMANENT_ACCOUNT );

		$this->assertTrue( NotificationCircuitBreaker::is_open( 'smtp2go' ) );
	}

	public function test_recipient_level_failures_never_trip_the_breaker() {
		for ( $i = 0; $i < 50; $i++ ) {
			NotificationCircuitBreaker::record_failure( 'twilio', NotificationRetryPolicy::PERMANENT_RECIPIENT );
		}

		$this->assertFalse( NotificationCircuitBreaker::is_open( 'twilio' ) );
	}

	public function test_success_closes_the_breaker_and_resets_the_failure_count() {
		for ( $i = 0; $i < 4; $i++ ) {
			NotificationCircuitBreaker::record_failure( 'twilio', NotificationRetryPolicy::TRANSIENT );
		}

		NotificationCircuitBreaker::record_success( 'twilio' );

		// One more failure right after a success shouldn't be enough to
		// re-trip it - the count must have actually reset, not just been
		// one below threshold already.
		NotificationCircuitBreaker::record_failure( 'twilio', NotificationRetryPolicy::TRANSIENT );

		$this->assertFalse( NotificationCircuitBreaker::is_open( 'twilio' ) );
	}

	public function test_breakers_for_different_providers_are_independent() {
		for ( $i = 0; $i < 5; $i++ ) {
			NotificationCircuitBreaker::record_failure( 'twilio', NotificationRetryPolicy::TRANSIENT );
		}

		$this->assertTrue( NotificationCircuitBreaker::is_open( 'twilio' ) );
		$this->assertFalse( NotificationCircuitBreaker::is_open( 'smtp2go' ) );
	}
}
