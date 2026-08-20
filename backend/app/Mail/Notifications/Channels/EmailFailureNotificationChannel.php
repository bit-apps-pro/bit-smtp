<?php

namespace BitApps\SMTP\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\NotificationDispatchGuard;

class EmailFailureNotificationChannel implements FailureNotificationChannelInterface
{
    public function key(): string
    {
        return 'email';
    }

    public function send(FailureNotification $notification, array $settings): bool
    {
        $recipients = isset($settings['recipients']) && \is_array($settings['recipients'])
            ? $settings['recipients']
            : [];
        if ($recipients === []) {
            return false;
        }

        return NotificationDispatchGuard::run(static function () use ($recipients, $notification): bool {
            return wp_mail(
                $recipients,
                $notification->emailSubject(),
                $notification->emailBody(),
                ['Content-Type: text/plain; charset=UTF-8']
            );
        });
    }
}
