<?php

namespace BitApps\SMTP\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Notifications\ChannelDeliveryResult;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;
use BitApps\SMTP\Mail\Notifications\NotificationDispatchGuard;

class EmailFailureNotificationChannel implements FailureNotificationChannelInterface
{
    /**
     * The channel key, matched against each alert channel instance's `type`.
     */
    public function key(): string
    {
        return 'email';
    }

    /**
     * Send the alert as a plain-text email through native PHP mail, bypassing the plugin's providers.
     *
     * @param array<string,mixed> $settings
     */
    public function send(NotificationMessage $notification, array $settings): ChannelDeliveryResult
    {
        $recipients = isset($settings['recipients']) && \is_array($settings['recipients'])
            ? $settings['recipients']
            : [];
        if ($recipients === []) {
            return ChannelDeliveryResult::failed(__('No valid recipient email is configured.', 'bit-smtp'));
        }

        $sent = NotificationDispatchGuard::run(static function () use ($recipients, $notification): bool {
            return wp_mail(
                $recipients,
                $notification->emailSubject(),
                $notification->emailBody(),
                ['Content-Type: text/plain; charset=UTF-8']
            );
        });

        return $sent
            ? ChannelDeliveryResult::delivered()
            : ChannelDeliveryResult::failed(__('PHP mail() could not send the message.', 'bit-smtp'));
    }
}
