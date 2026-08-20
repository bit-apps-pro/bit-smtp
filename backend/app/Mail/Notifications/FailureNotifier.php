<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotifierInterface;
use Throwable;
use WP_Error;

class FailureNotifier implements FailureNotifierInterface
{
    private MailConfigService $config;

    private FailureNotificationGate $gate;

    public function __construct(
        MailConfigService $config,
        FailureNotificationGate $gate,
        private FailureNotificationChannelRegistry $channels
    ) {
        $this->config = $config;
        $this->gate   = $gate;
    }

    public function notifyFailure(WP_Error $error, ?Connection $connection = null): void
    {
        $alerts = $this->alerts();
        if (!$this->hasEnabledChannel($alerts)) {
            return;
        }

        $notification = FailureNotification::fromError($error, $connection);
        if (!$this->gate->acquire()) {
            return;
        }

        foreach ($this->channels->all() as $key => $channel) {
            $settings = isset($alerts[$key]) && \is_array($alerts[$key]) ? $alerts[$key] : [];
            if (empty($settings['enabled'])) {
                continue;
            }

            try {
                $channel->send($notification, $settings);
            } catch (Throwable) {
                // Notification failures are intentionally isolated from wp_mail and never expose provider output.
            }
        }
    }

    public function notifySuccess(): void
    {
        $this->gate->reset();
    }

    private function alerts(): array
    {
        $features = $this->config->load()->getFeatures();
        $alerts   = $features['alerts'] ?? [];

        return \is_array($alerts) ? $alerts : [];
    }

    private function hasEnabledChannel(array $alerts): bool
    {
        if (empty($alerts['enabled'])) {
            return false;
        }

        foreach (array_keys($this->channels->all()) as $key) {
            if (!empty($alerts[$key]['enabled'])) {
                return true;
            }
        }

        return false;
    }
}
