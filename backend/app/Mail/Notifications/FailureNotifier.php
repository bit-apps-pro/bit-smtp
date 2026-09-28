<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotifierInterface;
use WP_Error;

/**
 * Request-path send-failure alerter; tracks incident and queues alerts without stalling.
 */
class FailureNotifier implements FailureNotifierInterface
{
    private const SECONDS_PER_MINUTE = 60;

    /**
     * @var callable():int
     */
    private $clock;

    /**
     * Wire the channel dispatcher (alerts settings and enabled check), incident store, alert queue, and an injectable clock.
     */
    public function __construct(
        private AlertChannelDispatcher $dispatcher,
        private FailureIncidentStore $incidents,
        private AlertScheduler $scheduler,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? 'time';
    }

    /**
     * Track failure; open incident, queue alert, or remind if due.
     */
    public function notifyFailure(WP_Error $error, ?Connection $connection = null): void
    {
        $alerts = $this->dispatcher->alerts();
        if (!$this->dispatcher->hasEnabledChannel($alerts)) {
            return;
        }

        $now      = ($this->clock)();
        $incident = FailureIncident::start(wp_generate_uuid4(), $now, $error, $connection)->withAlertPending($now);
        if ($this->incidents->open($incident)) {
            $this->schedule(FailureNotification::EVENT_OPENED, $incident, $now);

            return;
        }

        $reminderSeconds = self::reminderSeconds($alerts);
        $due             = null;
        $updated         = $this->incidents->update(
            static function (FailureIncident $open) use ($now, $error, $connection, $reminderSeconds, &$due): FailureIncident {
                $open = $open->withFailure($now, $error, $connection);
                $due  = $open->dueAlert($now, $reminderSeconds);

                return $due === null ? $open : $open->withAlertPending($now);
            }
        );

        if ($updated !== null && $due !== null) {
            $this->schedule($due, $updated, $now);
        }
    }

    /**
     * Close incident and queue resolution alert when appropriate.
     */
    public function notifySuccess(): void
    {
        $closed = $this->incidents->close();
        if ($closed === null || !$closed->announcesResolution() || !$this->dispatcher->hasEnabledChannel()) {
            return;
        }

        $now      = ($this->clock)();
        $resolved = $closed->withResolution($now);
        if (!$this->incidents->keepResolved($resolved)) {
            return;
        }
        $this->schedule(FailureNotification::EVENT_RESOLVED, $resolved, $now);
    }

    /**
     * Queue background alert delivery via identifiers only (no personal data).
     */
    private function schedule(string $event, FailureIncident $incident, int $now): void
    {
        $this->scheduler->queue(Config::FAILURE_ALERT_HOOK, [$event, $incident->id(), $incident->sequence()], $now);
    }

    /**
     * Minimum spacing between still-failing reminders from the alerts settings; 0 disables them.
     *
     * @param array{enabled:bool,reminder_minutes:int,channels:list<array<string,mixed>>} $alerts
     */
    private static function reminderSeconds(array $alerts): int
    {
        return $alerts['reminder_minutes'] * self::SECONDS_PER_MINUTE;
    }
}
