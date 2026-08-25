<?php

namespace BitApps\SMTP\Mail\Tracking;

\defined('ABSPATH') || exit();

/**
 * Single source of truth for the public tracking URL path segments, shared by the URL builder
 * (TrackingBodyRewriter) and the route matcher (TrackingRouter) so the two can never silently drift.
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
