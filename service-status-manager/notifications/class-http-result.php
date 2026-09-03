<?php
/**
 * Value object for a single HTTP call made by ConcurrentHttpClient. Kept
 * separate from SendResult (which is a *notification* outcome) because one
 * HTTP call doesn't always map 1:1 to one notification outcome - a
 * provider's send_many() interprets a batch of these into SendResults.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HttpResult {

	/** @var bool Whether the request completed (a response was received at all). False only for connection-level failures. */
	public $completed;

	/** @var int|null HTTP status code, if a response was received. */
	public $http_code;

	/** @var string Raw response body, if a response was received. */
	public $body;

	/** @var string|null Connection-level error message (timeout, DNS/SSL failure, connection reset), if the request never completed. */
	public $error;

	/**
	 * @param bool     $completed Whether a response was received.
	 * @param int|null $http_code HTTP status code, if any.
	 * @param string   $body      Raw response body.
	 * @param string   $error     Connection-level error, if any.
	 */
	public function __construct( $completed, $http_code = null, $body = '', $error = null ) {
		$this->completed = (bool) $completed;
		$this->http_code = null !== $http_code ? (int) $http_code : null;
		$this->body       = (string) $body;
		$this->error      = $error ? (string) $error : null;
	}
}
