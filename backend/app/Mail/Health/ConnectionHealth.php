<?php

namespace BitApps\SMTP\Mail\Health;

/**
 * Immutable value object for one connection's health record (the Fork-3 schema): a status plus the
 * counters and short, secret-free diagnostics that produced it. The circuit state is derived from
 * status rather than stored, since the two always move in lockstep.
 */
final class ConnectionHealth
{
    private string $status;

    private int $consecutiveFailures;

    private ?string $lastOkAt;

    private ?string $lastError;

    private ?string $lastErrorClass;

    private ?string $lastProbeAt;

    private ?int $oauthExpiresAt;

    private ?string $lastAlertedState;

    private ?string $lastAlertedAt;

    private string $updatedAt;

    private function __construct(
        string $status,
        int $consecutiveFailures,
        ?string $lastOkAt,
        ?string $lastError,
        ?string $lastErrorClass,
        ?string $lastProbeAt,
        ?int $oauthExpiresAt,
        ?string $lastAlertedState,
        ?string $lastAlertedAt,
        string $updatedAt
    ) {
        $this->status              = $status;
        $this->consecutiveFailures = $consecutiveFailures;
        $this->lastOkAt            = $lastOkAt;
        $this->lastError           = $lastError;
        $this->lastErrorClass      = $lastErrorClass;
        $this->lastProbeAt         = $lastProbeAt;
        $this->oauthExpiresAt      = $oauthExpiresAt;
        $this->lastAlertedState    = $lastAlertedState;
        $this->lastAlertedAt       = $lastAlertedAt;
        $this->updatedAt           = $updatedAt;
    }

    /**
     * The zero-data record for a connection nothing has been observed for yet.
     */
    public static function unknown(): self
    {
        return new self(
            HealthStatus::UNKNOWN,
            0,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            gmdate('Y-m-d H:i:s')
        );
    }

    /**
     * Rebuild a record from its persisted array, coercing every field and defaulting anything absent.
     * A legacy `circuit`/`opened_at` key (from before those became derived/removed) is ignored.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            \is_string($data['status'] ?? null) ? $data['status'] : HealthStatus::UNKNOWN,
            (int) ($data['consecutive_failures'] ?? 0),
            self::nullableString($data['last_ok_at'] ?? null),
            self::nullableString($data['last_error'] ?? null),
            self::nullableString($data['last_error_class'] ?? null),
            self::nullableString($data['last_probe_at'] ?? null),
            isset($data['oauth_expires_at']) ? (int) $data['oauth_expires_at'] : null,
            self::nullableString($data['last_alerted_state'] ?? null),
            self::nullableString($data['last_alerted_at'] ?? null),
            \is_string($data['updated_at'] ?? null) && $data['updated_at'] !== '' ? $data['updated_at'] : gmdate('Y-m-d H:i:s')
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'status'               => $this->status,
            'circuit'              => $this->getCircuit(),
            'consecutive_failures' => $this->consecutiveFailures,
            'last_ok_at'           => $this->lastOkAt,
            'last_error'           => $this->lastError,
            'last_error_class'     => $this->lastErrorClass,
            'last_probe_at'        => $this->lastProbeAt,
            'oauth_expires_at'     => $this->oauthExpiresAt,
            'last_alerted_state'   => $this->lastAlertedState,
            'last_alerted_at'      => $this->lastAlertedAt,
            'updated_at'           => $this->updatedAt,
        ];
    }

    /**
     * The public, secret-free projection surfaced to the admin UI: the status plus safe diagnostics,
     * deliberately excluding the internal alert bookkeeping (last_alerted_state/at).
     *
     * @return array<string,mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'status'               => $this->status,
            'circuit'              => $this->getCircuit(),
            'consecutive_failures' => $this->consecutiveFailures,
            'last_ok_at'           => $this->lastOkAt,
            'last_error'           => $this->lastError,
            'last_probe_at'        => $this->lastProbeAt,
            'oauth_expires_at'     => $this->oauthExpiresAt,
        ];
    }

    /**
     * Return a copy with the given persisted-schema fields overridden, so callers apply targeted
     * updates without a wither per field.
     *
     * @param array<string,mixed> $overrides
     */
    public function with(array $overrides): self
    {
        return self::fromArray(array_merge($this->toArray(), $overrides));
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Circuit state derived from status: open exactly when unhealthy, closed otherwise.
     */
    public function getCircuit(): string
    {
        return $this->status === HealthStatus::UNHEALTHY ? HealthStatus::CIRCUIT_OPEN : HealthStatus::CIRCUIT_CLOSED;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function getLastOkAt(): ?string
    {
        return $this->lastOkAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastErrorClass(): ?string
    {
        return $this->lastErrorClass;
    }

    public function getLastProbeAt(): ?string
    {
        return $this->lastProbeAt;
    }

    public function getOauthExpiresAt(): ?int
    {
        return $this->oauthExpiresAt;
    }

    public function getLastAlertedState(): ?string
    {
        return $this->lastAlertedState;
    }

    public function getLastAlertedAt(): ?string
    {
        return $this->lastAlertedAt;
    }

    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    public function isUnhealthy(): bool
    {
        return $this->status === HealthStatus::UNHEALTHY;
    }

    public function isDegraded(): bool
    {
        return $this->status === HealthStatus::DEGRADED;
    }

    /**
     * Coerce a persisted value to a non-empty string, or null for an absent/empty one.
     *
     * @param mixed $value
     */
    private static function nullableString($value): ?string
    {
        return $value !== null && $value !== '' ? (string) $value : null;
    }
}
