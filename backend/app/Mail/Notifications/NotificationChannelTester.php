<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\AlertChannelSettings;
use BitApps\SMTP\Mail\Config\MaskedSecretResolver;
use BitApps\SMTP\Mail\Exceptions\InvalidAlertChannelException;
use Throwable;

/**
 * Send test alert through channel instance; resolve masked secrets from storage.
 */
final class NotificationChannelTester
{
    public function __construct(
        private MailConfigService $config,
        private FailureNotificationChannelRegistry $channels
    ) {
    }

    /**
     * Deliver test alert through instance; return result with admin-safe reason.
     *
     * @param array<string,mixed> $settings
     *
     * @throws InvalidAlertChannelException when the type is unknown or a required field is missing
     */
    public function send(string $type, array $settings, string $id = ''): ChannelDeliveryResult
    {
        $channel = AlertChannelSettings::isType($type) ? $this->channels->get($type) : null;
        if ($channel === null) {
            throw InvalidAlertChannelException::unknownType();
        }

        $resolved  = MaskedSecretResolver::resolveAlertChannel($id, $type, $settings, $this->config->load());
        $sanitized = AlertChannelSettings::sanitize($type, $resolved);
        $missing   = AlertChannelSettings::missingRequirement($type, $sanitized);
        if ($missing !== null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text JSON message; React escapes on render.
            throw InvalidAlertChannelException::incomplete($missing);
        }

        try {
            return $channel->send(FailureNotification::forTest(), $sanitized);
        } catch (Throwable) {
            return ChannelDeliveryResult::failed(__('Notification test failed.', 'bit-smtp'));
        }
    }
}
