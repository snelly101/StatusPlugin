<?php
/**
 * "Ping" monitor provider: checks basic host reachability without asking
 * for a specific port.
 *
 * PHP on typical WordPress hosting has no way to send a real ICMP echo
 * request - that needs raw sockets (root/CAP_NET_RAW), which shared and
 * managed hosts don't grant, and shelling out to the system `ping`
 * command needs exec()/shell_exec(), which most hosts disable for
 * security. Instead this tries a plain TCP connection to a small set of
 * common ports and succeeds as soon as any of them accepts one - the
 * same practical substitute most uptime-monitoring tools fall back to
 * when ICMP isn't available. For checking one specific port, use a TCP
 * Port monitor instead.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Monitoring;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PingMonitor implements MonitorProviderInterface {

	/**
	 * {@inheritDoc}
	 */
	public function check( $monitor ) {
		$settings = $monitor->settings ?? array();
		$host     = $settings['host'] ?? '';

		if ( '' === $host ) {
			return new CheckResult( false, null, null, __( 'No host is configured for this monitor.', 'service-status-manager' ) );
		}

		$validation = SsrfGuard::validate_host( $host, ! empty( $settings['allow_internal'] ) );
		if ( ! $validation['allowed'] ) {
			return new CheckResult( false, null, null, $validation['reason'] );
		}

		/**
		 * Filters the ports tried, in order, for a "Ping" monitor check.
		 * The check succeeds as soon as any of them accepts a connection.
		 *
		 * @param int[]  $ports   Ports to try, in order.
		 * @param object $monitor Monitor row.
		 */
		$ports = apply_filters( 'ssm_ping_monitor_ports', array( 80, 443 ), $monitor );
		$ports = array_values( array_filter( array_map( 'absint', (array) $ports ) ) );
		if ( empty( $ports ) ) {
			$ports = array( 80 );
		}

		$timeout           = (int) ( $monitor->timeout_seconds ?? 10 );
		$per_port_timeout  = max( 1, (int) floor( $timeout / count( $ports ) ) );
		$target            = false !== strpos( $validation['ip'], ':' ) ? "[{$validation['ip']}]" : $validation['ip'];

		$start      = microtime( true );
		$last_error = __( 'Host did not respond on any checked port.', 'service-status-manager' );

		foreach ( $ports as $port ) {
			$errno  = 0;
			$errstr = '';
			$conn   = @fsockopen( $target, $port, $errno, $errstr, $per_port_timeout ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen

			if ( $conn ) {
				fclose( $conn ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$elapsed = (int) round( ( microtime( true ) - $start ) * 1000 );
				return new CheckResult( true, $elapsed );
			}

			if ( $errstr ) {
				$last_error = $errstr;
			}
		}

		$elapsed = (int) round( ( microtime( true ) - $start ) * 1000 );
		return new CheckResult( false, $elapsed, null, $last_error );
	}
}
