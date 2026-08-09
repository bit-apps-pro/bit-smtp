<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use Throwable;

final class NotificationChannelTester
{
    private const TESTABLE_CHANNELS = ['slack', 'telegram'];

    public function __construct(
        private MailConfigService $config,
        private FailureNotificationChannelRegistry $channels
    ) {
    }

    public function send(string $key): bool
    {
        if (!\in_array($key, self::TESTABLE_CHANNELS, true)) {
            return false;
        }

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
