<?php

namespace BitApps\SMTP\Mail\Notifications\Contracts;

use BitApps\SMTP\Mail\Notifications\ChannelDeliveryResult;

interface FailureNotificationChannelInterface
{
    /**
     * The channel key, matched against each alert channel instance's `type`.
     */
    public function key(): string;

    /**
     * Deliver the alert with the instance's settings; the result carries an admin-safe failure reason.
     *
     * @param array<string,mixed> $settings
     */
    public function send(NotificationMessage $notification, array $settings): ChannelDeliveryResult;
}
