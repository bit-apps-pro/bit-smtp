<?php

namespace BitApps\SMTP\Mail\Health;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;

/**
 * Applies the Fork-3 health rules to a connection's send outcomes and exposes the live health map.
 * Observe-only: it records signal for badges/alerts and never influences dispatch/failover.
 */
class ConnectionHealthService
{
    private ConnectionHealthStore $store;

    private MailConfigService $config;

    public function __construct(ConnectionHealthStore $store, MailConfigService $config)
    {
        $this->store  = $store;
        $this->config = $config;
    }

    /**
     * Fold one send outcome into the connection's health record and return the status transition it
     * caused (or null when nothing changed, including for message-scoped and write-throttled outcomes).
     */
    public function recordOutcome(Connection $connection, string $failureClass): ?HealthTransition
    {
        // A bad address or content rejection is the message's fault, not the connection's, so a
        // passive send outcome for those classes says nothing about connection health. (Probes have
        // no message/recipient, so recordProbe deliberately does NOT skip these.)
        if ($this->isMessageScoped($failureClass)) {
            return null;
        }

        // No error string on the passive path: a provider send error can echo a recipient address
        // ("550 <addr> unknown"), and the security bar forbids storing recipients. Only the probe's
        // sanitized connect/auth error (recipient-free) is surfaced as last_error.
        return $this->record($connection, $failureClass, null, null);
    }

    /**
     * Fold an active probe's result into the connection's health record. Reuses the send-outcome trip
     * rules but always stamps last_probe_at and never write-throttles, and surfaces the probe's
     * sanitized error as last_error.
     */
    public function recordProbe(Connection $connection, ProbeResult $result): ?HealthTransition
    {
        $failureClass = $result->isOk()
            ? FailureCategory::OK
            : ($result->getFailureClass() ?? FailureCategory::TRANSIENT);

        return $this->record($connection, $failureClass, gmdate('Y-m-d H:i:s'), $result->getError());
    }

    /**
     * Health for every live connection, filling an `unknown` record for those never observed yet.
     *
     * @return array<string,ConnectionHealth>
     */
    public function list(): array
    {
        $records = $this->store->all();
        $health  = [];
        foreach ($this->liveConnections() as $connection) {
            $id          = $connection->getId();
            $health[$id] = $records[$id] ?? ConnectionHealth::unknown()->with([
                'oauth_expires_at' => $connection->oauthExpiresAt(),
            ]);
        }

        return $health;
    }

    /**
     * Current stored health for a connection, or a fresh `unknown` record mirroring its OAuth expiry
     * when nothing has been observed yet (so an alerter always has a record to read/annotate).
     */
    public function healthFor(Connection $connection): ConnectionHealth
    {
        return $this->store->get($connection->getId())
            ?? ConnectionHealth::unknown()->with(['oauth_expires_at' => $connection->oauthExpiresAt()]);
    }

    /**
     * Persist an alert's dedup marker and timestamp onto the connection's health record (creating it if
     * the connection was never observed), in one read-modify-write.
     */
    public function markAlerted(Connection $connection, string $alertState, string $alertedAt): void
    {
        $id           = $connection->getId();
        $records      = $this->store->all();
        $record       = $records[$id] ?? ConnectionHealth::unknown()->with(['oauth_expires_at' => $connection->oauthExpiresAt()]);
        $records[$id] = $record->with([
            'last_alerted_state' => $alertState,
            'last_alerted_at'    => $alertedAt,
            'updated_at'         => $alertedAt,
        ]);

        $this->store->putMany($this->pruneToLive($records));
    }

    /**
     * Apply the Fork-3 health rules for one outcome in a single read-modify-write. A non-null $probeAt
     * marks an active probe: it stamps last_probe_at, bypasses the success write-throttle, and carries
     * the sanitized $error. Prune to live connections happens in memory, so this is one read + one write.
     */
    private function record(Connection $connection, string $failureClass, ?string $probeAt, ?string $error): ?HealthTransition
    {
        $id      = $connection->getId();
        $records = $this->store->all();
        $before  = $records[$id] ?? ConnectionHealth::unknown();
        $now     = gmdate('Y-m-d H:i:s');
        $oauth   = $connection->oauthExpiresAt();

        if ($failureClass === FailureCategory::OK) {
            // Steady-state healthy success: no state change and a fresh last_ok_at, so skip the write —
            // but an active probe always persists, to keep its last_probe_at proof current.
            if ($probeAt === null && $this->isAlreadyHealthy($before) && $this->okWriteThrottled($before, $now)) {
                return null;
            }
            $after = $before->with([
                'status'               => HealthStatus::HEALTHY,
                'consecutive_failures' => 0,
                'last_ok_at'           => $now,
                'last_error'           => null,
                'last_error_class'     => null,
                'last_probe_at'        => $probeAt ?? $before->getLastProbeAt(),
                'oauth_expires_at'     => $oauth,
                // Clear the edge-dedup marker on recovery so a LATER outage alerts again (keep
                // last_alerted_at for the cooldown throttle). Otherwise the marker stays stuck at
                // 'connection_unhealthy' and every outage after the first is silently suppressed.
                'last_alerted_state'   => null,
                'updated_at'           => $now,
            ]);
        } elseif ($failureClass === FailureCategory::AUTH) {
            // A bad credential won't self-heal, so trip on the first failure regardless of the count.
            $after = $this->tripped($before, $failureClass, $oauth, $now, $probeAt, $error);
        } else {
            $consecutive = $before->getConsecutiveFailures() + 1;
            $after       = $consecutive >= HealthStatus::HEALTH_FAILURE_THRESHOLD
                ? $this->tripped($before, $failureClass, $oauth, $now, $probeAt, $error)
                : $this->degraded($before, $failureClass, $oauth, $now, $consecutive, $probeAt, $error);
        }

        $records[$id] = $after;
        $this->store->putMany($this->pruneToLive($records));

        return $before->getStatus() !== $after->getStatus()
            ? new HealthTransition($before->getStatus(), $after->getStatus(), $connection)
            : null;
    }

    /**
     * Trip a connection to unhealthy (circuit derives to open). Preserves the last known error when the
     * current event carries none (a passive send failure has no recipient-safe error).
     */
    private function tripped(ConnectionHealth $before, string $failureClass, ?int $oauth, string $now, ?string $probeAt, ?string $error): ConnectionHealth
    {
        return $before->with([
            'status'               => HealthStatus::UNHEALTHY,
            'consecutive_failures' => $before->getConsecutiveFailures() + 1,
            'last_error'           => $error ?? $before->getLastError(),
            'last_error_class'     => $failureClass,
            'last_probe_at'        => $probeAt ?? $before->getLastProbeAt(),
            'oauth_expires_at'     => $oauth,
            'updated_at'           => $now,
        ]);
    }

    /**
     * Mark a connection amber: one or more recent failures that have not yet crossed the trip threshold.
     */
    private function degraded(ConnectionHealth $before, string $failureClass, ?int $oauth, string $now, int $consecutive, ?string $probeAt, ?string $error): ConnectionHealth
    {
        return $before->with([
            'status'               => HealthStatus::DEGRADED,
            'consecutive_failures' => $consecutive,
            'last_error'           => $error ?? $before->getLastError(),
            'last_error_class'     => $failureClass,
            'last_probe_at'        => $probeAt ?? $before->getLastProbeAt(),
            'oauth_expires_at'     => $oauth,
            'updated_at'           => $now,
        ]);
    }

    /**
     * Message-scoped failures say nothing about the connection's health, so they never touch it.
     */
    private function isMessageScoped(string $failureClass): bool
    {
        return $failureClass === FailureCategory::INVALID_RECIPIENT
            || $failureClass === FailureCategory::PERMANENT;
    }

    private function isAlreadyHealthy(ConnectionHealth $record): bool
    {
        return $record->getStatus() === HealthStatus::HEALTHY;
    }

    private function okWriteThrottled(ConnectionHealth $record, string $now): bool
    {
        $lastOkAt = $record->getLastOkAt();
        if ($lastOkAt === null) {
            return false;
        }

        return ((int) strtotime($now) - (int) strtotime($lastOkAt)) < HealthStatus::HEALTH_OK_WRITE_THROTTLE;
    }

    /**
     * Drop any record whose connection no longer exists, bounding the option to live connections; done
     * in memory as part of the single write so eviction costs no extra option I/O.
     *
     * @param array<string,ConnectionHealth> $records
     *
     * @return array<string,ConnectionHealth>
     */
    private function pruneToLive(array $records): array
    {
        return array_intersect_key($records, array_flip($this->liveIds()));
    }

    /**
     * @return Connection[]
     */
    private function liveConnections(): array
    {
        return $this->config->load()->getConnections()->all();
    }

    /**
     * @return string[]
     */
    private function liveIds(): array
    {
        return array_map(static fn (Connection $connection): string => $connection->getId(), $this->liveConnections());
    }
}
