<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotifierInterface;
use Throwable;
use WP_Error;

class FailureNotifier implements FailureNotifierInterface
{
    private MailConfigService $config;

    private FailureNotificationGate $gate;

    /**
     * @var array<string,FailureNotificationChannelInterface>
     */
    private array $channels = [];

    /**
     * @param FailureNotificationChannelInterface[] $channels
     */
    public function __construct(MailConfigService $config, FailureNotificationGate $gate, array $channels)
    {
        $this->config = $config;
        $this->gate   = $gate;

        foreach ($channels as $channel) {
            $this->channels[$channel->key()] = $channel;
        }
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

        foreach ($this->channels as $key => $channel) {
            $settings = isset($alerts[$key]) && \is_array($alerts[$key]) ? $alerts[$key] : [];
            if (empty($settings['enabled'])) {
                continue;
            }

            try {
                $channel->send($notification, $settings);
            } catch (Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log -- notification failure must not break wp_mail
                error_log('Bit SMTP failure notification error: ' . $e->getMessage());
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

        foreach (array_keys($this->channels) as $key) {
            if (!empty($alerts[$key]['enabled'])) {
                return true;
            }
        }

        return false;
    }
}
