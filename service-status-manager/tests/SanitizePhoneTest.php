<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\SubscriberManager;

/**
 * @covers \ServiceStatusManager\SubscriberManager::sanitize_phone
 */
final class SanitizePhoneTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['ssm_test_options']['ssm_settings'] );
	}

	public function test_uk_national_format_is_converted_to_e164_with_default_country() {
		$this->assertSame( '+447780118777', SubscriberManager::sanitize_phone( '07780118777' ) );
	}

	public function test_already_e164_is_left_untouched() {
		$this->assertSame( '+447780118777', SubscriberManager::sanitize_phone( '+447780118777' ) );
	}

	public function test_formatting_characters_are_stripped_before_conversion() {
		$this->assertSame( '+447780118777', SubscriberManager::sanitize_phone( '07780 118777' ) );
		$this->assertSame( '+447780118777', SubscriberManager::sanitize_phone( '(0778) 011-8777' ) );
	}

	public function test_international_dialling_prefix_00_is_converted_to_plus() {
		$this->assertSame( '+447780118777', SubscriberManager::sanitize_phone( '00447780118777' ) );
	}

	public function test_bare_number_without_leading_zero_is_still_prefixed() {
		$this->assertSame( '+447780118777', SubscriberManager::sanitize_phone( '7780118777' ) );
	}

	public function test_respects_configured_default_country() {
		update_option( 'ssm_settings', array( 'sms_default_country' => 'US' ) );
		$this->assertSame( '+12025550123', SubscriberManager::sanitize_phone( '2025550123' ) );
	}

	public function test_unrecognised_default_country_leaves_digits_unprefixed() {
		update_option( 'ssm_settings', array( 'sms_default_country' => 'ZZ' ) );
		$this->assertSame( '07780118777', SubscriberManager::sanitize_phone( '07780118777' ) );
	}

	public function test_too_short_returns_empty_string() {
		$this->assertSame( '', SubscriberManager::sanitize_phone( '12345' ) );
	}

	public function test_empty_input_returns_empty_string() {
		$this->assertSame( '', SubscriberManager::sanitize_phone( '' ) );
	}
}
