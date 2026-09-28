<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\AlertSettingsNormalizer;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;
use Throwable;

/**
 * Deliver alerts to enabled channels; the single fan-out point for failure and health notifiers.
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
     * Send notification through each enabled instance; return true if at least one succeeded.
     */
    public function dispatch(NotificationMessage $notification): bool
    {
        $delivered = false;
        foreach ($this->enabledInstances($this->alerts()) as $instance) {
            $channel = $this->channels->get($instance['type']);
            if ($channel === null) {
                continue;
            }

            try {
                $delivered = $channel->send($notification, $instance['settings'])->ok() || $delivered;
            } catch (Throwable) {
            }
        }

        return $delivered;
    }

    /**
     * Check if alerts enabled and at least one instance is active; pass already-normalized alerts to avoid re-normalizing.
     *
     * @param null|array{enabled:bool,reminder_minutes:int,channels:list<array{id:string,type:string,name:string,enabled:bool,settings:array<string,mixed>}>} $alerts
     */
    public function hasEnabledChannel(?array $alerts = null): bool
    {
        return $this->enabledInstances($alerts ?? $this->alerts()) !== [];
    }

    /**
     * The normalized alerts settings, loaded fresh from config.
     *
     * @return array{enabled:bool,reminder_minutes:int,channels:list<array{id:string,type:string,name:string,enabled:bool,settings:array<string,mixed>}>}
     */
    public function alerts(): array
    {
        return AlertSettingsNormalizer::normalize($this->config->load()->getFeatures()['alerts'] ?? []);
    }

    /**
     * The switched-on instances of a registered type, or none while the alerts master switch is off.
     *
     * @param array{enabled:bool,reminder_minutes:int,channels:list<array{id:string,type:string,name:string,enabled:bool,settings:array<string,mixed>}>} $alerts
     *
     * @return list<array{id:string,type:string,name:string,enabled:bool,settings:array<string,mixed>}>
     */
    private function enabledInstances(array $alerts): array
    {
        if (!$alerts['enabled']) {
            return [];
        }

        return array_values(array_filter(
            $alerts['channels'],
            fn (array $instance): bool => $instance['enabled'] && $this->channels->get($instance['type']) !== null
        ));
    }
}
