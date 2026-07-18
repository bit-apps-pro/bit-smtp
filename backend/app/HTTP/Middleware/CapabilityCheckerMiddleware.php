<?php

namespace BitApps\SMTP\HTTP\Middleware;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Deps\BitApps\WPKit\Utils\Capabilities;

/**
 * Authorization gate for the admin REST group: requires manage_options.
 * CSRF is enforced separately by WP core's wp_rest cookie nonce (rest_cookie_check_errors), not here.
 */
final class CapabilityCheckerMiddleware
{
    public function handle(Request $request, ...$params)
    {
        if (!Capabilities::check('manage_options')) {
            return Response::error([])->message('unauthorized access');
        }

        return true;
    }
}
