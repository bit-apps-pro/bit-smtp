<?php

namespace BitApps\SMTP\Mail\Health;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Notifications\HealthNotifier;

/**
 * Thin adapter that feeds a send outcome into the health engine. WpMailBridge holds it as a
 * nullable, construction-gated dependency (mirroring FailureNotifierInterface) so it stays
 * test-fakeable and a disabled feature never exercises it.
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
     * Record one connection's send outcome (a cheap DB write); returns the status transition when the
     * health changed. Recording only — alerts are flushed separately so no channel HTTP runs inside
     * the dispatch/failover loop.
     */
    public function record(Connection $connection, string $failureClass): ?HealthTransition
    {
        return $this->service->recordOutcome($connection, $failureClass);
    }

    /**
     * Deliver the transitions collected across a dispatch to the notifier, called AFTER the failover
     * loop so a slow channel never adds latency between send attempts (mirrors notifyOutcome).
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
