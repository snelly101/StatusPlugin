<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\Notifications\NotificationRetryPolicy;

/**
 * @covers \ServiceStatusManager\Notifications\NotificationRetryPolicy::classify
 * @covers \ServiceStatusManager\Notifications\NotificationRetryPolicy::is_missing_destination
 */
final class MissingDestinationClassificationTest extends TestCase {

	public function test_no_email_address_is_a_permanent_recipient_failure_not_transient() {
		$this->assertSame(
			NotificationRetryPolicy::PERMANENT_RECIPIENT,
			NotificationRetryPolicy::classify( null, 'Subscriber has no valid email address.', 'smtp2go' )
		);
	}

	public function test_no_mobile_number_is_a_permanent_recipient_failure() {
		$this->assertSame(
			NotificationRetryPolicy::PERMANENT_RECIPIENT,
			NotificationRetryPolicy::classify( null, 'Subscriber has no mobile number.', 'twilio' )
		);
	}

	public function test_a_genuine_connection_failure_with_no_status_is_still_transient() {
		$this->assertSame(
			NotificationRetryPolicy::TRANSIENT,
			NotificationRetryPolicy::classify( null, 'cURL error 28: Operation timed out', 'smtp2go' )
		);
	}
}
