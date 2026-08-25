<?php

namespace BitApps\SMTP\Mail\Health;

/**
 * Health status / circuit vocabulary and the passive-recording tuning constants.
 */
final class HealthStatus
{
    public const HEALTHY = 'healthy';

    public const DEGRADED = 'degraded';

    public const UNHEALTHY = 'unhealthy';

    public const UNKNOWN = 'unknown';

    public const CIRCUIT_CLOSED = 'closed';

    public const CIRCUIT_OPEN = 'open';

    /**
     * Consecutive transient/rate-limited failures that trip a connection to unhealthy.
     */
    public const HEALTH_FAILURE_THRESHOLD = 3;

    /**
     * Seconds a healthy record's last_ok_at may age before a steady-state success rewrites it.
     */
    public const HEALTH_OK_WRITE_THROTTLE = 300;
}
