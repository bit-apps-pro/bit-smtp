<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;
use Throwable;

/**
 * Delivers an alert to every enabled notification channel, reading the shared `features['alerts']`
 * settings and isolating each channel's failures. The one place that knows how to fan an alert out to
 * email/webhook/slack/telegram, shared by the send-failure notifier and the health notifier.
 */
class AlertChannelDispatcher
{
    private MailConfigService $config;

    private FailureNotificationChannelRegistry $channels;

    public function __construct(MailConfigService $config, FailureNotificationChannelRegistry $channels)
    {
        $this->config   = $config;
        $this->channels = $channels;
    }

    /**
     * Send the notification through each enabled channel; returns true if at least one delivered.
     * A channel's throw (or provider output) is swallowed so it neither aborts the fan-out nor leaks.
     */
    public function dispatch(NotificationMessage $notification): bool
    {
        $alerts = $this->alerts();
        if (!$this->anyChannelEnabled($alerts)) {
            return false;
        }

        $delivered = false;
        foreach ($this->channels->all() as $key => $channel) {
            $settings = isset($alerts[$key]) && \is_array($alerts[$key]) ? $alerts[$key] : [];
            if (empty($settings['enabled'])) {
                continue;
            }

            try {
                $delivered = $channel->send($notification, $settings) || $delivered;
            } catch (Throwable) {
                // Notification failures are intentionally isolated and never expose provider output.
            }
        }

        return $delivered;
    }

    /**
     * True when alerts are enabled and at least one channel is switched on, so a caller can skip work
     * (or, for the failure notifier, avoid consuming its streak lock) when there is nowhere to send.
     */
    public function hasEnabledChannel(): bool
    {
        return $this->anyChannelEnabled($this->alerts());
    }

    /**
     * @return array<string,mixed>
     */
    private function alerts(): array
    {
        $features = $this->config->load()->getFeatures();
        $alerts   = $features['alerts'] ?? [];

        return \is_array($alerts) ? $alerts : [];
    }

    /**
     * @param array<string,mixed> $alerts
     */
    private function anyChannelEnabled(array $alerts): bool
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
