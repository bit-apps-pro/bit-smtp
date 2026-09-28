<?php

namespace BitApps\SMTP\Mail\Tracking;

\defined('ABSPATH') || exit();

/**
 * Single source of truth for tracking URL path segments; prevents drift between URL builder and router.
 */
final class TrackingRoutes
{
    /**
     * Base path segment common to every tracking endpoint, resolved under home_url().
     */
    public const BASE = 'bit-smtp/track';

    /**
     * Action segment for the open-pixel endpoint.
     */
    public const OPEN = 'open';

    /**
     * Action segment for the click-redirect endpoint.
     */
    public const CLICK = 'click';
}
