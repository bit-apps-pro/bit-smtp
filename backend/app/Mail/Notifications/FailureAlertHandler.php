<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Config;

/**
 * WP-Cron handler for send-failure alerts; dispatch through channels and track delivery.
 */
class FailureAlertHandler
{
    /**
     * Seconds to wait before each retry of an undelivered resolved alert; its length is the retry cap.
     */
    public const RESOLVED_RETRY_DELAYS = [300, 900, 3600];

    /**
     * @var callable():int
     */
    private $clock;

    /**
     * Wire the channel fan-out, incident store, alert queue, and an injectable clock.
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
     * Cron entry point; validate and route to appropriate delivery handler.
     *
     * @param mixed $event
     * @param mixed $incidentId
     * @param mixed $sequence
     * @param mixed $attempt
     */
    public function handle($event, $incidentId, $sequence, $attempt = 0): void
    {
        if (!\is_string($event) || !FailureNotification::isEvent($event) || !\is_string($incidentId) || $incidentId === '' || !\is_int($sequence)) {
            return;
        }

        if ($event === FailureNotification::EVENT_RESOLVED) {
            if (self::isRetryAttempt($attempt)) {
                $this->deliverResolved($incidentId, $sequence, $attempt);
            }

            return;
        }

        $this->deliverOpenAlert($event, $incidentId, $sequence);
    }

    /**
     * True for a retry attempt within 0..the retry cap.
     *
     * @param mixed $attempt
     */
    private static function isRetryAttempt($attempt): bool
    {
        return \is_int($attempt) && $attempt >= 0 && $attempt <= \count(self::RESOLVED_RETRY_DELAYS);
    }

    /**
     * Deliver resolved alert; discard on success or retry exhaustion, otherwise re-queue.
     */
    private function deliverResolved(string $incidentId, int $sequence, int $attempt): void
    {
        $resolved = $this->incidents->resolved($incidentId);
        if ($resolved === null || !$resolved->matchesAlert($incidentId, $sequence)) {
            return;
        }

        $delivered = $this->dispatcher->dispatch(FailureNotification::fromIncident(FailureNotification::EVENT_RESOLVED, $resolved));
        if ($delivered || $attempt >= \count(self::RESOLVED_RETRY_DELAYS)) {
            $this->incidents->forgetResolved($incidentId);

            return;
        }

        $this->scheduler->queue(
            Config::FAILURE_ALERT_HOOK,
            [FailureNotification::EVENT_RESOLVED, $incidentId, $sequence, $attempt + 1],
            ($this->clock)() + self::RESOLVED_RETRY_DELAYS[$attempt]
        );
    }

    /**
     * Deliver open/reminder alert from latest incident state; record delivery outcome.
     */
    private function deliverOpenAlert(string $event, string $incidentId, int $sequence): void
    {
        $current = $this->incidents->current();
        if ($current === null || !$current->matchesAlert($incidentId, $sequence)) {
            return;
        }

        $delivered = $this->dispatcher->dispatch(FailureNotification::fromIncident($event, $current));
        $now       = ($this->clock)();

        $this->incidents->update(
            static fn (FailureIncident $open): ?FailureIncident => $open->matchesAlert($incidentId, $sequence)
                ? $open->withAlertOutcome($event, $delivered, $now)
                : null
        );
    }
}
