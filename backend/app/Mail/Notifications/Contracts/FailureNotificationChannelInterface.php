<?php

namespace BitApps\SMTP\Mail\Notifications\Contracts;

use BitApps\SMTP\Mail\Notifications\FailureNotification;

interface FailureNotificationChannelInterface
{
    public function key(): string;

    public function send(FailureNotification $notification, array $settings): bool;
}
