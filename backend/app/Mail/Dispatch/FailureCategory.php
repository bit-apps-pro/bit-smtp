<?php

namespace BitApps\SMTP\Mail\Dispatch;

/**
 * Send-failure taxonomy consumed by retry/failover decisions.
 */
final class FailureCategory
{
    public const OK = 'ok';

    public const TRANSIENT = 'transient';

    public const RATE_LIMITED = 'rate_limited';

    public const AUTH = 'auth';

    public const INVALID_RECIPIENT = 'invalid_recipient';

    public const PERMANENT = 'permanent';

    private const RETRYABLE = [self::TRANSIENT, self::RATE_LIMITED];

    // Only an invalid/nonexistent recipient is undeliverable on EVERY connection, so only it stops
    // the fallback chain. PERMANENT (content/reputation/policy rejections) and AUTH are
    // connection-scoped — a clean-reputation or differently-credentialed connection may still
    // deliver — so failover must keep trying them.
    private const STOPS_FAILOVER = [self::INVALID_RECIPIENT];

    /**
     * True when a resend of the same message is safe to attempt (transient/rate-limited only).
     */
    public static function isRetryable(string $category): bool
    {
        return \in_array($category, self::RETRYABLE, true);
    }

    /**
     * True when the fallback chain should stop rather than try the next connection.
     */
    public static function stopsFailover(string $category): bool
    {
        return \in_array($category, self::STOPS_FAILOVER, true);
    }
}
