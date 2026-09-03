<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\Notifications\NotificationRetryPolicy;

/**
 * @covers \ServiceStatusManager\Notifications\NotificationRetryPolicy
 */
final class NotificationRetryPolicyTest extends TestCase {

	public function test_connection_level_failure_with_no_response_is_transient() {
		$this->assertSame( NotificationRetryPolicy::TRANSIENT, NotificationRetryPolicy::classify( null ) );
	}

	public function test_rate_limited_is_transient() {
		$this->assertSame( NotificationRetryPolicy::TRANSIENT, NotificationRetryPolicy::classify( 429 ) );
	}

	public function test_server_errors_are_transient() {
		$this->assertSame( NotificationRetryPolicy::TRANSIENT, NotificationRetryPolicy::classify( 500 ) );
		$this->assertSame( NotificationRetryPolicy::TRANSIENT, NotificationRetryPolicy::classify( 503 ) );
		$this->assertSame( NotificationRetryPolicy::TRANSIENT, NotificationRetryPolicy::classify( 599 ) );
	}

	public function test_auth_failures_are_permanent_account_not_permanent_recipient() {
		$this->assertSame( NotificationRetryPolicy::PERMANENT_ACCOUNT, NotificationRetryPolicy::classify( 401 ) );
		$this->assertSame( NotificationRetryPolicy::PERMANENT_ACCOUNT, NotificationRetryPolicy::classify( 403 ) );
	}

	public function test_other_client_errors_are_permanent_recipient() {
		// This is the case the old uniform-backoff behaviour got wrong: a
		// permanently invalid phone number/address (a 4xx that isn't an
		// auth failure) used to burn through every retry attempt exactly
		// like a transient 503 would.
		$this->assertSame( NotificationRetryPolicy::PERMANENT_RECIPIENT, NotificationRetryPolicy::classify( 400 ) );
		$this->assertSame( NotificationRetryPolicy::PERMANENT_RECIPIENT, NotificationRetryPolicy::classify( 404 ) );
		$this->assertSame( NotificationRetryPolicy::PERMANENT_RECIPIENT, NotificationRetryPolicy::classify( 422 ) );
	}

	public function test_unrecognised_status_defaults_to_transient_rather_than_silently_dropping() {
		$this->assertSame( NotificationRetryPolicy::TRANSIENT, NotificationRetryPolicy::classify( 999 ) );
	}
}
