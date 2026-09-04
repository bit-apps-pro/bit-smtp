<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Status;

/**
 * Immutable outcome of a provider delivery-status lookup.
 */
final class DeliveryStatus
{
    public const DELIVERED = 'delivered';

    public const ACCEPTED = 'accepted';

    public const DEFERRED = 'deferred';

    public const BLOCKED = 'blocked';

    public const BOUNCED = 'bounced';

    public const SPAM = 'spam';

    public const PENDING = 'pending';

    public const UNKNOWN = 'unknown';

    private const NEGATIVE_STATES = [self::DEFERRED, self::BLOCKED, self::BOUNCED, self::SPAM];

    /**
     * Worst-outcome ranking (higher = worse). Hard negatives dominate; a confirmed delivery outranks
     * a merely transient deferral, so a feed carrying both resolves to delivered, not a false failure.
     */
    private const SEVERITY = [
        self::BLOCKED   => 6,
        self::BOUNCED   => 5,
        self::SPAM      => 4,
        self::DELIVERED => 3,
        self::DEFERRED  => 2,
        self::ACCEPTED  => 1,
        self::PENDING   => 0,
    ];

    private string $state;

    private string $detail;

    public function __construct(string $state, string $detail = '')
    {
        $this->state  = $state;
        $this->detail = $detail;
    }

    /**
     * Severity rank for a delivery state; 0 for unknown/unranked states.
     */
    public static function severity(string $state): int
    {
        return self::SEVERITY[$state] ?? 0;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function detail(): string
    {
        return $this->detail;
    }

    public function isNegative(): bool
    {
        return \in_array($this->state, self::NEGATIVE_STATES, true);
    }
}
