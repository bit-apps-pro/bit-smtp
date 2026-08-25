<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingsRepository;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\ConnectionHealthService;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Mail\Health\HealthTransition;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * Turns health-status transitions and OAuth-expiry state into alerts, gated by the `notify_events`
 * preference, deduped once per transition via the health record's `last_alerted_state`, and rate-
 * limited by `notify_cooldown_minutes`. Delivers through the shared AlertChannelDispatcher.
 */
class HealthNotifier
{
    /**
     * Warn this far ahead of an OAuth access token's expiry (72 hours). A window, not a WP constant,
     * so the notifier stays free of the WordPress runtime for unit testing. Keep in sync with the
     * frontend's OAUTH_EXPIRY_WARNING_SECONDS in HealthBadge.tsx.
     */
    private const OAUTH_WARNING_WINDOW_SECONDS = 259200;

    private const SECONDS_PER_MINUTE = 60;

    private AlertChannelDispatcher $dispatcher;

    private ConnectionHealthService $health;

    public function __construct(AlertChannelDispatcher $dispatcher, ConnectionHealthService $health)
    {
        $this->dispatcher = $dispatcher;
        $this->health     = $health;
    }

    /**
     * Alert on each status transition that crossed into unhealthy or recovered from it. Given only
     * the transitions a feed produced, so the common healthy-success case reaches here as nothing.
     *
     * @param HealthTransition[] $transitions
     */
    public function notifyTransitions(array $transitions): void
    {
        if ($transitions === []) {
            return;
        }

        $preferences = PluginSettings::make();
        foreach ($transitions as $transition) {
            $event = $this->eventForTransition($transition);
            if ($event !== null) {
                $this->emit($transition->connection(), $event, $preferences);
            }
        }
    }

    /**
     * Alert when a connection's OAuth token is expiring/expired and cannot silently refresh. Level-
     * triggered (evaluated every run), so the per-transition dedup is what keeps it to one alert.
     */
    public function notifyOauthExpiry(Connection $connection): void
    {
        $event = $this->oauthEventFor($connection);
        if ($event !== null) {
            $this->emit($connection, $event, PluginSettings::make());
        }
    }

    /**
     * Gate one event on subscription, transition dedup, and cooldown, then dispatch and — only when a
     * channel actually delivered — persist the dedup marker so the same state does not re-alert.
     */
    private function emit(Connection $connection, string $event, SettingsRepository $preferences): void
    {
        if (!$this->isSubscribed($event, $preferences)) {
            return;
        }

        $record = $this->health->healthFor($connection);

        // Alert once per transition: the marker already records this event's state.
        if ($record->getLastAlertedState() === $event) {
            return;
        }
        if ($this->isCoolingDown($record, $preferences)) {
            return;
        }

        if (!$this->dispatcher->dispatch($this->build($event, $connection, $record))) {
            return;
        }

        $this->health->markAlerted($connection, $event, gmdate('Y-m-d H:i:s'));
    }

    /**
     * The alertable event a status transition represents, or null when the crossing is not alertable.
     */
    private function eventForTransition(HealthTransition $transition): ?string
    {
        if ($transition->after() === HealthStatus::UNHEALTHY) {
            return HealthNotification::EVENT_UNHEALTHY;
        }

        if ($transition->after() === HealthStatus::HEALTHY && $transition->before() === HealthStatus::UNHEALTHY) {
            return HealthNotification::EVENT_RECOVERED;
        }

        return null;
    }

    /**
     * The OAuth-expiry event for a connection, or null. Only connections that carry an expiry and
     * have no refresh token alert: a token with a refresh token self-heals, and a failed refresh
     * surfaces separately as an AUTH health transition.
     */
    private function oauthEventFor(Connection $connection): ?string
    {
        $expiresAt = $connection->oauthExpiresAt();
        if ($expiresAt === null || $this->hasRefreshToken($connection)) {
            return null;
        }

        $now = time();
        if ($expiresAt < $now) {
            return HealthNotification::EVENT_OAUTH_EXPIRED;
        }
        if ($expiresAt <= $now + self::OAUTH_WARNING_WINDOW_SECONDS) {
            return HealthNotification::EVENT_OAUTH_EXPIRING;
        }

        return null;
    }

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

    private function isSubscribed(string $event, SettingsRepository $preferences): bool
    {
        $events = $preferences->get('notify_events', []);

        return \is_array($events) && \in_array($event, $events, true);
    }

    private function isCoolingDown(ConnectionHealth $record, SettingsRepository $preferences): bool
    {
        $cooldownMinutes = (int) $preferences->get('notify_cooldown_minutes', 0);
        $lastAlertedAt   = $record->getLastAlertedAt();
        if ($cooldownMinutes <= 0 || $lastAlertedAt === null) {
            return false;
        }

        return (time() - (int) strtotime($lastAlertedAt)) < $cooldownMinutes * self::SECONDS_PER_MINUTE;
    }

    private function hasRefreshToken(Connection $connection): bool
    {
        $refresh = $connection->getCredentials()['refresh_token']['value'] ?? '';

        return \is_scalar($refresh) && (string) $refresh !== '';
    }
}
