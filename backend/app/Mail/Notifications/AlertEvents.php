<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingsRepository;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * The alert subscriptions an admin can opt into via the `notify_events` preference.
 */
final class AlertEvents
{
    /**
     * Send-failure incidents: the opened alert, still-failing reminders, and the resolved alert.
     */
    public const SEND_FAILURE = 'send_failure';

    /**
     * Every subscribable event key: send failures first, then the connection-health events.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return array_merge([self::SEND_FAILURE], HealthNotification::eventKeys());
    }

    /**
     * True when the admin opted into this event; reads the saved preferences unless a repository is given.
     */
    public static function isSubscribed(string $event, ?SettingsRepository $preferences = null): bool
    {
        $events = ($preferences ?? PluginSettings::make())->get('notify_events', self::keys());

        return \is_array($events) && \in_array($event, $events, true);
    }
}
