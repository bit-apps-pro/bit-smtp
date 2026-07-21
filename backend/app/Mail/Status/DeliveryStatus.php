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

    public const UNKNOWN = 'unknown';

    private const NEGATIVE_STATES = [self::DEFERRED, self::BLOCKED, self::BOUNCED, self::SPAM];

    private string $state;

    private string $detail;

    public function __construct(string $state, string $detail = '')
    {
        $this->state  = $state;
        $this->detail = $detail;
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
