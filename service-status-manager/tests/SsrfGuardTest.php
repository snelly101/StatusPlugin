<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\Monitoring\SsrfGuard;

/**
 * @covers \ServiceStatusManager\Monitoring\SsrfGuard
 */
final class SsrfGuardTest extends TestCase {

	public function test_loopback_addresses_are_blocked() {
		$this->assertTrue( SsrfGuard::is_blocked_ip( '127.0.0.1' ) );
		$this->assertTrue( SsrfGuard::is_blocked_ip( '::1' ) );
	}

	public function test_private_rfc1918_ranges_are_blocked() {
		$this->assertTrue( SsrfGuard::is_blocked_ip( '10.0.0.5' ) );
		$this->assertTrue( SsrfGuard::is_blocked_ip( '172.16.0.5' ) );
		$this->assertTrue( SsrfGuard::is_blocked_ip( '192.168.1.1' ) );
	}

	public function test_link_local_and_cloud_metadata_address_is_blocked() {
		// 169.254.169.254 is the AWS/Azure/GCP instance metadata endpoint.
		$this->assertTrue( SsrfGuard::is_blocked_ip( '169.254.169.254' ) );
		$this->assertTrue( SsrfGuard::is_blocked_ip( '169.254.1.1' ) );
	}

	public function test_carrier_grade_nat_range_is_blocked() {
		$this->assertTrue( SsrfGuard::is_blocked_ip( '100.64.0.1' ) );
		$this->assertTrue( SsrfGuard::is_blocked_ip( '100.100.100.100' ) );
		$this->assertFalse( SsrfGuard::is_blocked_ip( '100.63.255.255' ) );
		$this->assertFalse( SsrfGuard::is_blocked_ip( '100.128.0.0' ) );
	}

	public function test_ipv6_unique_local_and_link_local_are_blocked() {
		$this->assertTrue( SsrfGuard::is_blocked_ip( 'fe80::1' ) );
		$this->assertTrue( SsrfGuard::is_blocked_ip( 'fc00::1' ) );
		$this->assertTrue( SsrfGuard::is_blocked_ip( 'fd12:3456:789a::1' ) );
	}

	public function test_public_addresses_are_not_blocked() {
		$this->assertFalse( SsrfGuard::is_blocked_ip( '8.8.8.8' ) );
		$this->assertFalse( SsrfGuard::is_blocked_ip( '1.1.1.1' ) );
		$this->assertFalse( SsrfGuard::is_blocked_ip( '2606:4700:4700::1111' ) );
	}

	public function test_invalid_input_is_treated_as_blocked() {
		$this->assertTrue( SsrfGuard::is_blocked_ip( 'not-an-ip' ) );
		$this->assertTrue( SsrfGuard::is_blocked_ip( '' ) );
	}

	public function test_validate_host_rejects_private_ip_literal_by_default() {
		$result = SsrfGuard::validate_host( '192.168.1.50', false );

		$this->assertFalse( $result['allowed'] );
		$this->assertNotEmpty( $result['reason'] );
	}

	public function test_validate_host_allows_private_ip_when_monitor_opts_in() {
		$result = SsrfGuard::validate_host( '192.168.1.50', true );

		$this->assertTrue( $result['allowed'] );
		$this->assertSame( '192.168.1.50', $result['ip'] );
	}

	public function test_validate_host_allows_public_ip_literal() {
		$result = SsrfGuard::validate_host( '8.8.8.8', false );

		$this->assertTrue( $result['allowed'] );
		$this->assertSame( '8.8.8.8', $result['ip'] );
	}
}
