<?php
/**
 * Registers the plugin's versioned REST API namespace and delegates to
 * the public (read-only) and protected (write) route controllers.
 *
 * Namespace: service-status-manager/v1
 *
 * Protected routes authenticate the same way as any other WordPress REST
 * endpoint - cookie + nonce for logged-in admin-area requests, or
 * WordPress Application Passwords for external integrations - and are
 * additionally gated by the plugin's own capabilities (see
 * includes/class-capabilities.php) in each route's permission_callback.
 * No subscriber personal data is ever exposed through a public (no-auth)
 * endpoint.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestApi {

	const NAMESPACE_V1 = 'service-status-manager/v1';

	/**
	 * Registers every REST route.
	 */
	public function register_routes() {
		( new RestPublicController() )->register_routes();
		( new RestAdminController() )->register_routes();
		( new CronEndpoint() )->register_routes();
	}
}
