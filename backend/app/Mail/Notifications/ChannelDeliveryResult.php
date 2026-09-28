<?php

namespace BitApps\SMTP\Mail\Notifications;

/**
 * Immutable alert channel delivery outcome: provider acceptance and admin-safe reason.
 */
final class ChannelDeliveryResult
{
    /**
     * Built only through the named constructors.
     */
    private function __construct(private bool $ok, private ?string $reason)
    {
    }

    /**
     * The provider accepted the alert.
     */
    public static function delivered(): self
    {
        return new self(true, null);
    }

    /**
     * The alert was not delivered, for the given admin-safe reason.
     */
    public static function failed(string $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * True when the provider accepted the alert.
     */
    public function ok(): bool
    {
        return $this->ok;
    }

    /**
     * Why delivery failed, or null when it succeeded.
     */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
