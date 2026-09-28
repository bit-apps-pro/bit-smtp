<?php

namespace BitApps\SMTP\Mail\Health;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;

/**
 * Apply Fork-3 health rules to send outcomes; observe-only (never influences dispatch/failover).
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
     * Fold send outcome into health record; skip message-scoped classes; return transition or null.
     */
    public function recordOutcome(Connection $connection, string $failureClass): ?HealthTransition
    {
        if ($this->isMessageScoped($failureClass)) {
            return null;
        }

        return $this->record($connection, $failureClass, null, null);
    }

    /**
     * Fold active probe result into health record; always stamp last_probe_at, bypass throttle.
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
     * Return stored health or fresh 'unknown' record (so alerter always has a record).
     */
    public function healthFor(Connection $connection): ConnectionHealth
    {
        return $this->store->get($connection->getId())
            ?? ConnectionHealth::unknown()->with(['oauth_expires_at' => $connection->oauthExpiresAt()]);
    }

    /**
     * Persist alert dedup marker and timestamp onto health record in one read-modify-write.
     */
    public function markAlerted(Connection $connection, string $alertState, string $alertedAt, bool $isOutageTransition = false): void
    {
        $id        = $connection->getId();
        $records   = $this->store->all();
        $record    = $records[$id] ?? ConnectionHealth::unknown()->with(['oauth_expires_at' => $connection->oauthExpiresAt()]);
        $overrides = [
            'last_alerted_state' => $alertState,
            'last_alerted_at'    => $alertedAt,
            'updated_at'         => $alertedAt,
        ];
        if ($isOutageTransition) {
            $overrides['last_outage_alert'] = $alertState;
        }
        $records[$id] = $record->with($overrides);

        $this->store->putMany($this->pruneToLive($records));
    }

    /**
     * Apply Fork-3 health rules in one read-modify-write; non-null $probeAt marks active probe.
     */
    private function record(Connection $connection, string $failureClass, ?string $probeAt, ?string $error): ?HealthTransition
    {
        $id      = $connection->getId();
        $records = $this->store->all();
        $before  = $records[$id] ?? ConnectionHealth::unknown();
        $now     = gmdate('Y-m-d H:i:s');
        $oauth   = $connection->oauthExpiresAt();

        if ($failureClass === FailureCategory::OK) {
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
                'last_alerted_state'   => null,
                'updated_at'           => $now,
            ]);
        } elseif ($failureClass === FailureCategory::AUTH) {
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
     * Trip connection to unhealthy (circuit derives to open); preserve last error if event carries none.
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
     * Drop records for deleted connections; done in memory to avoid extra option I/O.
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
