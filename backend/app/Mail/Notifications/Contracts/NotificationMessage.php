<?php

namespace BitApps\SMTP\Mail\Notifications\Contracts;

/**
 * A dispatchable alert payload the notification channels consume, independent of what produced it
 * (a send failure or a health/OAuth event), so every channel and formatter works against one shape.
 */
interface NotificationMessage
{
    public function emailSubject(): string;

    public function emailBody(): string;

    /**
     * Plain-text rendering for chat channels (Slack/Telegram), so each message type labels itself
     * rather than every channel assuming the send-failure shape.
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
