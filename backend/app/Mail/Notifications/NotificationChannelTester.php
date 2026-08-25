<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use Throwable;

final class NotificationChannelTester
{
    public function __construct(
        private MailConfigService $config,
        private FailureNotificationChannelRegistry $channels
    ) {
    }

    /**
     * Deliver a test alert through the given channel; every registered channel is testable, so an
     * unknown key (no registered channel) is the only rejection.
     */
    public function send(string $key): bool
    {
        $channel = $this->channels->get($key);
        if ($channel === null) {
            return false;
        }

        $features = $this->config->load()->getFeatures();
        $alerts   = isset($features['alerts']) && \is_array($features['alerts']) ? $features['alerts'] : [];
        $settings = isset($alerts[$key])       && \is_array($alerts[$key]) ? $alerts[$key] : [];

        try {
            return $channel->send(FailureNotification::forTest(), $settings);
        } catch (Throwable) {
            return false;
        }
    }
}
