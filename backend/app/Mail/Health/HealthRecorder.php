<?php

namespace BitApps\SMTP\Mail\Health;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Notifications\HealthNotifier;

/**
 * Thin adapter feeding send outcome into health engine; test-fakeable, disabled feature = zero work.
 */
class HealthRecorder
{
    private ConnectionHealthService $service;

    private ?HealthNotifier $notifier;

    public function __construct(ConnectionHealthService $service, ?HealthNotifier $notifier = null)
    {
        $this->service  = $service;
        $this->notifier = $notifier;
    }

    /**
     * Record send outcome; return transition when health changed (alerts flushed separately).
     */
    public function record(Connection $connection, string $failureClass): ?HealthTransition
    {
        return $this->service->recordOutcome($connection, $failureClass);
    }

    /**
     * Deliver transitions to notifier after failover loop to prevent channel latency between attempts.
     *
     * @param HealthTransition[] $transitions
     */
    public function notify(array $transitions): void
    {
        if ($transitions !== [] && $this->notifier !== null) {
            $this->notifier->notifyTransitions($transitions);
        }
    }
}
