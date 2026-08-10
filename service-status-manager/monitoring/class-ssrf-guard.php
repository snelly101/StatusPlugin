<?php
/**
 * Protects HTTP/TCP monitoring against Server-Side Request Forgery.
 *
 * HTTP monitors let an administrator point the plugin at an arbitrary URL,
 * which is checked from the web server itself - this is a classic SSRF
 * vector if left unguarded (an attacker with monitor-management access, or
 * a compromised admin session, could probe the internal network or cloud
 * metadata service). This class:
 *
 *  1. Resolves the target hostname and rejects it if the resolved IP falls
 *     in a loopback, link-local, private, reserved, or known cloud
 *     metadata range - unless the specific host is on the administrator's
 *     explicit allow-list.
 *  2. Re-validates on every check (not just when the monitor is saved), so
 *     a public hostname that is later repointed at an internal address via
 *     DNS is still caught.
 *  3. Pins the TCP connection to the exact IP address that was validated,
 *     using CURLOPT_RESOLVE via the `http_api_curl` action, so a second
 *     DNS lookup performed by the HTTP client at connect time (a "DNS
 *     rebinding" attack) cannot silently redirect the request to a
 *     different, unvalidated address between validation and connection.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Monitoring;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SsrfGuard {

	/**
	 * Validates a hostname (or IP literal) for outbound monitoring.
	 *
	 * @param string $host           Hostname or IP address.
	 * @param bool   $allow_internal Whether this specific monitor has opted
	 *                               into internal-address monitoring.
	 * @return array{allowed: bool, ip: string, reason: string}
	 */
	public static function validate_host( $host, $allow_internal = false ) {
		$host = trim( (string) $host );

		if ( '' === $host ) {
			return array( 'allowed' => false, 'ip' => '', 'reason' => __( 'No host specified.', 'service-status-manager' ) );
		}

		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : self::resolve( $host );

		if ( ! $ip ) {
			return array( 'allowed' => false, 'ip' => '', 'reason' => __( 'The hostname could not be resolved.', 'service-status-manager' ) );
		}

		$allowlist = (array) \ssm_get_setting( 'ssrf_allowlist', array() );
		if ( in_array( $host, $allowlist, true ) || in_array( $ip, $allowlist, true ) ) {
			return array( 'allowed' => true, 'ip' => $ip, 'reason' => '' );
		}

		if ( self::is_blocked_ip( $ip ) ) {
			if ( $allow_internal ) {
				\ssm_log(
					sprintf( 'Monitoring an internal/private address by explicit administrator opt-in: %s (%s)', $host, $ip ),
					'warning'
				);
				return array( 'allowed' => true, 'ip' => $ip, 'reason' => '' );
			}

			return array(
				'allowed' => false,
				'ip'      => $ip,
				'reason'  => sprintf(
					/* translators: %s: resolved IP address */
					__( 'The target resolves to a private, loopback, link-local, or cloud metadata address (%s), which is blocked by default. Enable "Allow internal addresses" on the monitor or add it to the SSRF allow-list in Settings if this is intentional.', 'service-status-manager' ),
					$ip
				),
			);
		}

		return array( 'allowed' => true, 'ip' => $ip, 'reason' => '' );
	}

	/**
	 * Resolves a hostname to its first IPv4/IPv6 address.
	 *
	 * @param string $host Hostname.
	 * @return string
	 */
	private static function resolve( $host ) {
		$records = @dns_get_record( $host, DNS_A + DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! empty( $records ) ) {
			foreach ( $records as $record ) {
				if ( ! empty( $record['ip'] ) ) {
					return $record['ip'];
				}
				if ( ! empty( $record['ipv6'] ) ) {
					return $record['ipv6'];
				}
			}
		}

		$ip = gethostbyname( $host );
		return ( $ip && $ip !== $host ) ? $ip : '';
	}

	/**
	 * Determines whether an IP address falls in a blocked range: loopback,
	 * private (RFC1918), link-local (including the 169.254.169.254 cloud
	 * metadata endpoint used by AWS/Azure/GCP), carrier-grade NAT,
	 * unspecified, or IPv6 equivalents.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_blocked_ip( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		// Fast path: PHP's built-in private/reserved range filter covers
		// most of RFC1918, link-local, and reserved space for both
		// protocol families.
		$is_public_by_filter = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		if ( ! $is_public_by_filter ) {
			return true;
		}

		// Explicit belt-and-braces checks for ranges some PHP builds miss,
		// notably carrier-grade NAT (100.64.0.0/10) and IPv6 unique-local.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $ip );
			$cgnat_start = ip2long( '100.64.0.0' );
			$cgnat_end   = ip2long( '100.127.255.255' );
			if ( $long >= $cgnat_start && $long <= $cgnat_end ) {
				return true;
			}
		} elseif ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$lower = strtolower( $ip );
			if ( 0 === strpos( $lower, 'fc' ) || 0 === strpos( $lower, 'fd' ) || 0 === strpos( $lower, 'fe80' ) || '::1' === $lower ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Temporarily pins outbound cURL requests to a specific IP for the
	 * given host/port, preventing a second DNS lookup at connect time from
	 * resolving to a different (unvalidated) address. Call remove() with
	 * the returned callback once the request has completed.
	 *
	 * @param string $host Hostname as used in the request URL.
	 * @param int    $port Port number.
	 * @param string $ip   Validated IP address to pin to.
	 * @return callable Callback to pass to remove_action() to undo the pin.
	 */
	public static function pin_dns( $host, $port, $ip ) {
		$callback = function ( $handle ) use ( $host, $port, $ip ) {
			if ( function_exists( 'curl_setopt' ) && defined( 'CURLOPT_RESOLVE' ) ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, array( "{$host}:{$port}:{$ip}" ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			}
		};

		add_action( 'http_api_curl', $callback, 10, 1 );

		return $callback;
	}

	/**
	 * Removes a pin previously installed by pin_dns().
	 *
	 * @param callable $callback Callback returned by pin_dns().
	 */
	public static function unpin_dns( $callback ) {
		remove_action( 'http_api_curl', $callback, 10 );
	}
}
