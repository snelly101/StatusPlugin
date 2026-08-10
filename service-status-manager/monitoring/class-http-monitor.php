<?php
/**
 * HTTP/HTTPS monitor provider.
 *
 * Uses the WordPress HTTP API (wp_remote_request) so the plugin
 * automatically inherits proxy configuration, SSL CA bundles, and any
 * site-level HTTP filters, rather than re-implementing an HTTP client.
 * Every request is validated and DNS-pinned by SsrfGuard first.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Monitoring;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HttpMonitor implements MonitorProviderInterface {

	/**
	 * {@inheritDoc}
	 */
	public function check( $monitor ) {
		$settings = $monitor->settings ?? array();
		$url      = $settings['url'] ?? '';

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new CheckResult( false, null, null, __( 'No valid URL is configured for this monitor.', 'service-status-manager' ) );
		}

		$parts = wp_parse_url( $url );
		$host  = $parts['host'] ?? '';
		$port  = $parts['port'] ?? ( 'https' === ( $parts['scheme'] ?? '' ) ? 443 : 80 );

		$validation = SsrfGuard::validate_host( $host, ! empty( $settings['allow_internal'] ) );
		if ( ! $validation['allowed'] ) {
			return new CheckResult( false, null, null, $validation['reason'] );
		}

		$headers = array();
		foreach ( (array) ( $settings['headers'] ?? array() ) as $name => $value ) {
			$headers[ $name ] = $value;
		}

		$args = array(
			'method'      => $settings['method'] ?? 'GET',
			'timeout'     => (int) ( $monitor->timeout_seconds ?? 10 ),
			'redirection' => ! empty( $settings['follow_redirects'] ) ? 5 : 0,
			'sslverify'   => ! isset( $settings['verify_ssl'] ) || $settings['verify_ssl'],
			'headers'     => $headers,
			'user-agent'  => 'ServiceStatusManager/' . SSM_VERSION . ' (+' . home_url() . ')',
		);

		$pin_callback = SsrfGuard::pin_dns( $host, $port, $validation['ip'] );

		$start    = microtime( true );
		$response = wp_remote_request( $url, $args );
		$elapsed  = (int) round( ( microtime( true ) - $start ) * 1000 );

		SsrfGuard::unpin_dns( $pin_callback );

		if ( is_wp_error( $response ) ) {
			return new CheckResult( false, $elapsed, null, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		$expected = ! empty( $settings['expected_status'] ) ? (int) $settings['expected_status'] : 200;
		if ( $status !== $expected ) {
			return new CheckResult(
				false,
				$elapsed,
				$status,
				sprintf(
					/* translators: 1: expected status, 2: actual status */
					__( 'Expected HTTP status %1$d but received %2$d.', 'service-status-manager' ),
					$expected,
					$status
				)
			);
		}

		if ( ! empty( $settings['required_text'] ) && false === strpos( $body, $settings['required_text'] ) ) {
			return new CheckResult( false, $elapsed, $status, __( 'Required text was not found in the response body.', 'service-status-manager' ) );
		}

		if ( ! empty( $settings['forbidden_text'] ) && false !== strpos( $body, $settings['forbidden_text'] ) ) {
			return new CheckResult( false, $elapsed, $status, __( 'Forbidden text was found in the response body.', 'service-status-manager' ) );
		}

		$ssl_expiry_days = null;
		if ( 'https' === ( $parts['scheme'] ?? '' ) && ( ! isset( $settings['verify_ssl'] ) || $settings['verify_ssl'] ) ) {
			$ssl_expiry_days = $this->get_ssl_expiry_days( $host, $port, $validation['ip'] );
			if ( null !== $ssl_expiry_days && $ssl_expiry_days < 0 ) {
				return new CheckResult( false, $elapsed, $status, __( 'The SSL certificate has expired.', 'service-status-manager' ), $ssl_expiry_days );
			}
		}

		return new CheckResult( true, $elapsed, $status, '', $ssl_expiry_days );
	}

	/**
	 * Opens a short-lived TLS connection (to the already-validated,
	 * DNS-pinned IP) purely to read the certificate's expiry date.
	 *
	 * @param string $host Hostname (used for SNI).
	 * @param int    $port Port.
	 * @param string $ip   Validated, pinned IP address to connect to.
	 * @return int|null Days until expiry, or null if it could not be determined.
	 */
	private function get_ssl_expiry_days( $host, $port, $ip ) {
		$context = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'SNI_enabled'       => true,
					'peer_name'         => $host,
				),
			)
		);

		$target = "ssl://{$ip}:{$port}";
		$client = @stream_socket_client( $target, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $client ) {
			return null;
		}

		$params = stream_context_get_params( $client );
		fclose( $client ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( empty( $params['options']['ssl']['peer_certificate'] ) ) {
			return null;
		}

		$cert_info = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );
		if ( empty( $cert_info['validTo_time_t'] ) ) {
			return null;
		}

		return (int) floor( ( $cert_info['validTo_time_t'] - time() ) / DAY_IN_SECONDS );
	}
}
