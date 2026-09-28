<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Mail\Health\HealthTransition;

/**
 * Turn health transitions and OAuth expiry into alerts; queue or deliver per context.
 */
class HealthNotifier
{
    /**
     * @var callable():int
     */
    private $clock;

    /**
     * Wire the delivery half, the alert queue, and an injectable clock.
     */
    public function __construct(
        private HealthAlertHandler $handler,
        private AlertScheduler $scheduler,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? 'time';
    }

    /**
     * Alert on transitions that cross into/from unhealthy status.
     *
     * @param HealthTransition[] $transitions
     */
    public function notifyTransitions(array $transitions): void
    {
        foreach ($transitions as $transition) {
            $event = self::eventForTransition($transition);
            if ($event !== null) {
                $this->raise($transition->connection(), $event);
            }
        }
    }

    /**
     * Alert on OAuth expiry when token cannot silently refresh (level-triggered).
     */
    public function notifyOauthExpiry(Connection $connection): void
    {
        $event = HealthAlertHandler::oauthEventFor($connection, ($this->clock)());
        if ($event !== null) {
            $this->raise($connection, $event);
        }
    }

    /**
     * Deliver now in WP-Cron context, otherwise queue event if due and not pending.
     */
    private function raise(Connection $connection, string $event): void
    {
        $this->scheduler->deliverOrQueue(
            fn () => $this->handler->deliver($connection, $event),
            fn (): bool => $this->handler->isDue($connection, $event),
            Config::HEALTH_ALERT_HOOK,
            [$event, $connection->getId()],
            ($this->clock)()
        );
    }

    /**
     * The alertable event a status transition represents, or null when the crossing is not alertable.
     */
    private static function eventForTransition(HealthTransition $transition): ?string
    {
        if ($transition->after() === HealthStatus::UNHEALTHY) {
            return HealthNotification::EVENT_UNHEALTHY;
        }

        if ($transition->after() === HealthStatus::HEALTHY && $transition->before() === HealthStatus::UNHEALTHY) {
            return HealthNotification::EVENT_RECOVERED;
        }

        return null;
    }
}
