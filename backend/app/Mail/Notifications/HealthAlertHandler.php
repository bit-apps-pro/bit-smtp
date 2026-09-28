<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingsRepository;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\ConnectionHealthService;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * Health alert delivery handler; check subscription, dedup, cooldown, then dispatch.
 */
class HealthAlertHandler
{
    /**
     * OAuth token expiry warning window (72 hours); keep in sync with HealthBadge.tsx.
     */
    private const OAUTH_WARNING_WINDOW_SECONDS = 259200;

    private const SECONDS_PER_MINUTE = 60;

    /**
     * Wire the channel fan-out, the health records, and the connection lookup.
     */
    public function __construct(
        private AlertChannelDispatcher $dispatcher,
        private ConnectionHealthService $health,
        private MailConfigService $config
    ) {
    }

    /**
     * Cron entry point; validate args and skip if connection deleted.
     *
     * @param mixed $event
     * @param mixed $connectionId
     * @param mixed $sequence
     */
    public function handle($event, $connectionId, $sequence): void
    {
        if (!self::isAlertEvent($event) || !\is_string($connectionId) || $connectionId === '' || !\is_int($sequence)) {
            return;
        }

        $connection = $this->config->connectionById($connectionId);
        if ($connection !== null) {
            $this->deliver($connection, $event);
        }
    }

    /**
     * Dispatch event when due and current; persist dedup marker on successful delivery.
     */
    public function deliver(Connection $connection, string $event): void
    {
        $record = $this->health->healthFor($connection);
        if (!$this->isDueFor($event, $record, PluginSettings::make()) || !$this->isCurrent($connection, $event, $record)) {
            return;
        }

        if (!$this->dispatcher->dispatch($this->build($event, $connection, $record))) {
            return;
        }

        $this->health->markAlerted($connection, $event, gmdate('Y-m-d H:i:s'), self::isOutageTransition($event));
    }

    /**
     * Check if event is due for delivery; allows request path to skip queueing no-ops.
     */
    public function isDue(Connection $connection, string $event): bool
    {
        return $this->isDueFor($event, $this->health->healthFor($connection), PluginSettings::make());
    }

    /**
     * Get OAuth expiry event for connection; null for refresh-token-capable or expired.
     */
    public static function oauthEventFor(Connection $connection, int $now): ?string
    {
        $expiresAt = $connection->oauthExpiresAt();
        if ($expiresAt === null || self::hasRefreshToken($connection)) {
            return null;
        }

        if ($expiresAt < $now) {
            return HealthNotification::EVENT_OAUTH_EXPIRED;
        }
        if ($expiresAt <= $now + self::OAUTH_WARNING_WINDOW_SECONDS) {
            return HealthNotification::EVENT_OAUTH_EXPIRING;
        }

        return null;
    }

    /**
     * True when the value names a known health alert event.
     *
     * @param mixed $event
     */
    private static function isAlertEvent($event): bool
    {
        return \is_string($event) && \in_array($event, HealthNotification::eventKeys(), true);
    }

    /**
     * Subscription, per-transition dedup, pairing, cooldown, and channel availability for one event.
     */
    private function isDueFor(string $event, ConnectionHealth $record, SettingsRepository $preferences): bool
    {
        return $this->isSubscribed($event, $preferences)
            && $record->getLastAlertedState() !== $event
            && !$this->isUnpairedRecovery($event, $record, $preferences)
            && !$this->isCoolingDown($record, $preferences)
            && $this->dispatcher->hasEnabledChannel();
    }

    /**
     * Check if recovery alert is unpaired (outage was never announced).
     */
    private function isUnpairedRecovery(string $event, ConnectionHealth $record, SettingsRepository $preferences): bool
    {
        return $event === HealthNotification::EVENT_RECOVERED
            && $this->isSubscribed(HealthNotification::EVENT_UNHEALTHY, $preferences)
            && $record->getLastOutageAlert() !== HealthNotification::EVENT_UNHEALTHY;
    }

    /**
     * True for the unhealthy/recovered events that announce the start or end of an outage.
     */
    private static function isOutageTransition(string $event): bool
    {
        return $event === HealthNotification::EVENT_UNHEALTHY || $event === HealthNotification::EVENT_RECOVERED;
    }

    /**
     * Check if event still describes connection; prevent stale alert delivery.
     */
    private function isCurrent(Connection $connection, string $event, ConnectionHealth $record): bool
    {
        switch ($event) {
            case HealthNotification::EVENT_UNHEALTHY:
                return $record->getStatus() === HealthStatus::UNHEALTHY;

            case HealthNotification::EVENT_RECOVERED:
                return $record->getStatus() === HealthStatus::HEALTHY;

            default:
                return self::oauthEventFor($connection, time()) === $event;
        }
    }

    /**
     * The alert message for an event.
     */
    private function build(string $event, Connection $connection, ConnectionHealth $record): HealthNotification
    {
        switch ($event) {
            case HealthNotification::EVENT_RECOVERED:
                return HealthNotification::recovered($connection, $record);

            case HealthNotification::EVENT_OAUTH_EXPIRING:
                return HealthNotification::oauthExpiring($connection, $record);

            case HealthNotification::EVENT_OAUTH_EXPIRED:
                return HealthNotification::oauthExpired($connection, $record);

            default:
                return HealthNotification::unhealthy($connection, $record);
        }
    }

    /**
     * True when the admin opted into this event in the `notify_events` preference.
     */
    private function isSubscribed(string $event, SettingsRepository $preferences): bool
    {
        $events = $preferences->get('notify_events', []);

        return \is_array($events) && \in_array($event, $events, true);
    }

    /**
     * True while the last alert for this connection is younger than `notify_cooldown_minutes`.
     */
    private function isCoolingDown(ConnectionHealth $record, SettingsRepository $preferences): bool
    {
        $cooldownMinutes = (int) $preferences->get('notify_cooldown_minutes', 0);
        $lastAlertedAt   = $record->getLastAlertedAt();
        if ($cooldownMinutes <= 0 || $lastAlertedAt === null) {
            return false;
        }

        return (time() - (int) strtotime($lastAlertedAt)) < $cooldownMinutes * self::SECONDS_PER_MINUTE;
    }

    /**
     * True when the connection stores a non-empty OAuth refresh token.
     */
    private static function hasRefreshToken(Connection $connection): bool
    {
        $refresh = $connection->getCredentials()['refresh_token']['value'] ?? '';

        return \is_scalar($refresh) && (string) $refresh !== '';
    }
}
