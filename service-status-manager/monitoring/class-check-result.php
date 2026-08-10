<?php
/**
 * Immutable value object returned by every monitor provider's check().
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Monitoring;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CheckResult {

	/** @var bool */
	public $success;

	/** @var int|null Response time in milliseconds. */
	public $response_time_ms;

	/** @var int|null HTTP status code, where applicable. */
	public $http_status;

	/** @var string|null Human-readable error/failure reason. */
	public $error_message;

	/** @var int|null Days until SSL certificate expiry, where applicable. */
	public $ssl_expiry_days;

	/**
	 * @param bool     $success          Whether the check passed.
	 * @param int|null $response_time_ms Response time in milliseconds.
	 * @param int|null $http_status      HTTP status code.
	 * @param string   $error_message    Failure reason, if any.
	 * @param int|null $ssl_expiry_days  Days until certificate expiry.
	 */
	public function __construct( $success, $response_time_ms = null, $http_status = null, $error_message = '', $ssl_expiry_days = null ) {
		$this->success          = (bool) $success;
		$this->response_time_ms = $response_time_ms;
		$this->http_status      = $http_status;
		$this->error_message    = $error_message ? substr( $error_message, 0, 500 ) : null;
		$this->ssl_expiry_days  = $ssl_expiry_days;
	}
}
