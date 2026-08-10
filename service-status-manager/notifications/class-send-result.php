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

	/**
	 * @param bool   $success  Whether the send succeeded.
	 * @param string $response Sanitised provider response (e.g. message ID).
	 * @param string $error    Error message, if any.
	 */
	public function __construct( $success, $response = '', $error = '' ) {
		$this->success  = (bool) $success;
		$this->response = substr( (string) $response, 0, 1000 );
		$this->error    = $error ? substr( (string) $error, 0, 500 ) : null;
	}
}
