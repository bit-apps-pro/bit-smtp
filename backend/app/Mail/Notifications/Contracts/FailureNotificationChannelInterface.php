<?php

namespace BitApps\SMTP\Mail\Notifications\Contracts;

interface FailureNotificationChannelInterface
{
    public function key(): string;

    public function send(NotificationMessage $notification, array $settings): bool;
}
