<?php

namespace BitApps\SMTP\Mail\Dispatch;

/**
 * Send-failure taxonomy for retry/failover decisions.
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

    private const STOPS_FAILOVER = [self::INVALID_RECIPIENT];

    /**
     * True when a resend of the same message is safe to attempt (transient/rate-limited only).
     */
    public static function isRetryable(string $category): bool
    {
        return \in_array($category, self::RETRYABLE, true);
    }

    /**
     * The failure classes a resend may ever target, exposed for the retry-class filter UI + validation.
     *
     * @return string[]
     */
    public static function retryableClasses(): array
    {
        return self::RETRYABLE;
    }

    /**
     * True when failure is retryable and explicitly listed in allowedClasses (an empty list retries nothing).
     *
     * @param string[] $allowedClasses retry_on_classes preference
     */
    public static function isRetryableWithin(string $category, array $allowedClasses): bool
    {
        return self::isRetryable($category) && \in_array($category, $allowedClasses, true);
    }

    /**
     * True when the fallback chain should stop rather than try the next connection.
     */
    public static function stopsFailover(string $category): bool
    {
        return \in_array($category, self::STOPS_FAILOVER, true);
    }
}
