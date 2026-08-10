<?php
/**
 * TCP port monitor provider.
 *
 * Validates the target through SsrfGuard and then connects directly to
 * the already-resolved, validated IP address (rather than letting
 * fsockopen() perform its own DNS lookup), which sidesteps DNS-rebinding
 * entirely for this monitor type.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Monitoring;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TcpMonitor implements MonitorProviderInterface {

	/**
	 * {@inheritDoc}
	 */
	public function check( $monitor ) {
		$settings = $monitor->settings ?? array();
		$host     = $settings['host'] ?? '';
		$port     = (int) ( $settings['port'] ?? 0 );

		if ( '' === $host || $port < 1 || $port > 65535 ) {
			return new CheckResult( false, null, null, __( 'No valid host/port is configured for this monitor.', 'service-status-manager' ) );
		}

		$validation = SsrfGuard::validate_host( $host, ! empty( $settings['allow_internal'] ) );
		if ( ! $validation['allowed'] ) {
			return new CheckResult( false, null, null, $validation['reason'] );
		}

		$timeout = (int) ( $monitor->timeout_seconds ?? 10 );
		$target  = false !== strpos( $validation['ip'], ':' ) ? "[{$validation['ip']}]" : $validation['ip'];

		$start = microtime( true );
		$conn  = @fsockopen( $target, $port, $errno, $errstr, $timeout ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen
		$elapsed = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( ! $conn ) {
			return new CheckResult( false, $elapsed, null, $errstr ? $errstr : __( 'Connection failed.', 'service-status-manager' ) );
		}

		fclose( $conn ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return new CheckResult( true, $elapsed );
	}
}
