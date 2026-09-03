<?php
/**
 * Fires many outbound HTTP requests concurrently within a single PHP
 * request, using curl_multi with a sliding window (a completed slot is
 * refilled immediately rather than waiting for the whole batch to finish).
 * This is the mechanism that makes "near-immediate dispatch at 2,000+
 * subscribers" achievable without a persistent worker process: instead of
 * 2,000 sequential HTTP round trips, a batch runs with up to
 * $max_concurrency requests in flight at once.
 *
 * Falls back to a plain sequential wp_remote_post() loop on hosts that
 * disable the curl extension - functionally correct, just without the
 * concurrency, and callers never need to know which path ran.
 *
 * Deliberately knows nothing about notifications, providers, or the
 * database - it is pure HTTP plumbing, reusable by any provider.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ConcurrentHttpClient {

	/**
	 * Sends many HTTP POST requests concurrently and returns their results
	 * keyed the same way the requests were keyed, so callers can map a
	 * result back to whatever it belongs to (e.g. a queue row ID).
	 *
	 * @param array $requests Keyed by an opaque caller-chosen key => array{
	 *     url: string,
	 *     headers: array<string,string>,
	 *     body: string,
	 * }.
	 * @param int   $max_concurrency Maximum requests in flight at once.
	 * @param int   $timeout         Per-request timeout in seconds.
	 * @return array Keyed the same as $requests => HttpResult.
	 */
	public static function send_many( array $requests, $max_concurrency = 10, $timeout = 15 ) {
		if ( empty( $requests ) ) {
			return array();
		}

		if ( ! function_exists( 'curl_multi_init' ) ) {
			return self::send_sequentially( $requests, $timeout );
		}

		$max_concurrency = max( 1, (int) $max_concurrency );
		$timeout         = max( 1, (int) $timeout );

		$results  = array();
		$multi    = curl_multi_init();
		$pending  = $requests;
		$inflight = array(); // spl_object_id(handle) => array( 'key' => ..., 'handle' => ... ).

		$start_next = function () use ( &$pending, &$inflight, $multi, $timeout ) {
			$key = array_key_first( $pending );
			if ( null === $key ) {
				return false;
			}

			$request = $pending[ $key ];
			unset( $pending[ $key ] );

			$ch = self::build_handle( $request, $timeout );
			curl_multi_add_handle( $multi, $ch );
			$inflight[ spl_object_id( $ch ) ] = array(
				'key'    => $key,
				'handle' => $ch,
			);

			return true;
		};

		for ( $i = 0; $i < $max_concurrency; $i++ ) {
			if ( ! $start_next() ) {
				break;
			}
		}

		while ( ! empty( $inflight ) ) {
			do {
				$status = curl_multi_exec( $multi, $running );
			} while ( CURLM_CALL_MULTI_PERFORM === $status );

			if ( $running && -1 === curl_multi_select( $multi, 1.0 ) ) {
				// A small number of platforms have a curl_multi_select()
				// that occasionally misreports readiness; a short sleep
				// avoids spinning the CPU in a tight loop if that happens.
				usleep( 10000 );
			}

			while ( $info = curl_multi_info_read( $multi ) ) {
				$ch = $info['handle'];
				$id = spl_object_id( $ch );

				if ( isset( $inflight[ $id ] ) ) {
					$results[ $inflight[ $id ]['key'] ] = self::interpret_handle( $ch, $info );
					unset( $inflight[ $id ] );
				}

				curl_multi_remove_handle( $multi, $ch );
				curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close

				$start_next();
			}
		}

		curl_multi_close( $multi );

		return $results;
	}

	/**
	 * @param array $request {url, headers, body}.
	 * @param int   $timeout Seconds.
	 * @return \CurlHandle|resource
	 */
	private static function build_handle( array $request, $timeout ) {
		$ch = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init

		$headers = array();
		foreach ( (array) ( $request['headers'] ?? array() ) as $name => $value ) {
			$headers[] = $name . ': ' . $value;
		}

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_URL            => $request['url'],
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $request['body'] ?? '',
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_FOLLOWLOCATION => false,
			)
		);

		return $ch;
	}

	/**
	 * @param \CurlHandle|resource $ch   Completed handle (still open - not yet removed/closed).
	 * @param array                $info One entry from curl_multi_info_read().
	 * @return HttpResult
	 */
	private static function interpret_handle( $ch, array $info ) {
		if ( 0 !== $info['result'] ) { // CURLE_OK === 0.
			$message = function_exists( 'curl_strerror' ) ? curl_strerror( $info['result'] ) : null;
			return new HttpResult( false, null, '', $message ?: ( 'curl error ' . $info['result'] ) );
		}

		$body = curl_multi_getcontent( $ch );
		$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		return new HttpResult( true, $code, (string) $body );
	}

	/**
	 * Fallback used when the curl extension (or curl_multi specifically)
	 * isn't available - correct, just not concurrent.
	 *
	 * @param array $requests Same shape as send_many().
	 * @param int   $timeout  Seconds.
	 * @return array Keyed the same as $requests => HttpResult.
	 */
	private static function send_sequentially( array $requests, $timeout ) {
		$results = array();

		foreach ( $requests as $key => $request ) {
			$response = wp_remote_post(
				$request['url'],
				array(
					'timeout'   => $timeout,
					'headers'   => $request['headers'] ?? array(),
					'body'      => $request['body'] ?? '',
					'sslverify' => true,
				)
			);

			if ( is_wp_error( $response ) ) {
				$results[ $key ] = new HttpResult( false, null, '', $response->get_error_message() );
				continue;
			}

			$results[ $key ] = new HttpResult(
				true,
				wp_remote_retrieve_response_code( $response ),
				wp_remote_retrieve_body( $response )
			);
		}

		return $results;
	}
}
