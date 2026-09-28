<?php

namespace BitApps\SMTP\Mail\Notifications\Contracts;

/**
 * Dispatchable alert payload for notification channels; uniform interface for all sources.
 */
interface NotificationMessage
{
    public function emailSubject(): string;

    public function emailBody(): string;

    /**
     * Render plain-text message for chat channels, with message type self-labeling.
     */
    public function chatText(): string;

    /**
     * Machine event name for webhook routing (e.g. `email_send_failed`, `connection_unhealthy`).
     */
    public function eventType(): string;

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array;

    public function isTest(): bool;
}
