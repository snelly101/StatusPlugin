<?php
/**
 * Value object returned by every notification provider's send()/send_test().
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendResult {

	/** @var bool */
	public $success;

	/** @var string Provider response, with secrets already stripped - safe to persist. */
	public $response;

	/** @var string|null Error message on failure. */
	public $error;

	/** @var int|null HTTP status code from the provider's API, when the
	 * transport is HTTP-based and a response was received (null for
	 * wp_mail() and for connection-level failures with no response at
	 * all) - used by NotificationRetryPolicy to tell a transient failure
	 * apart from a permanent one. */
	public $http_code;

	/**
	 * @param bool     $success   Whether the send succeeded.
	 * @param string   $response  Sanitised provider response (e.g. message ID).
	 * @param string   $error     Error message, if any.
	 * @param int|null $http_code HTTP status code from the provider, if any.
	 */
	public function __construct( $success, $response = '', $error = '', $http_code = null ) {
		$this->success   = (bool) $success;
		$this->response  = substr( (string) $response, 0, 1000 );
		$this->error     = $error ? substr( (string) $error, 0, 500 ) : null;
		$this->http_code = null !== $http_code ? (int) $http_code : null;
	}
}
