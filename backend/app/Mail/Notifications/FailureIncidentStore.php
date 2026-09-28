<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Config;

/**
 * Persist send-failure incidents via atomic compare-and-swap option rows.
 */
class FailureIncidentStore
{
    public const RESOLVED_OPTION_KEY = 'failure_resolved_incidents';

    private const MAX_UPDATE_ATTEMPTS = 3;

    private const MAX_RESOLVED = 10;

    private FailureNotificationGate $resolvedGate;

    /**
     * Store the open incident in $gate and closed ones in $resolvedGate (its own option row by default).
     */
    public function __construct(private FailureNotificationGate $gate, ?FailureNotificationGate $resolvedGate = null)
    {
        $this->resolvedGate = $resolvedGate ?? new FailureNotificationGate(self::RESOLVED_OPTION_KEY);
    }

    /**
     * Open incident; false if another is open; undecodable rows are replaced.
     */
    public function open(FailureIncident $incident): bool
    {
        $state = self::encode($incident);
        if ($state === null) {
            return false;
        }
        if ($this->gate->acquire($state)) {
            return true;
        }

        $existing = $this->gate->read();

        return $existing !== null && self::decode($existing) === null && $this->gate->replace($existing, $state);
    }

    /**
     * The currently open incident, or null when none is open or its row is unreadable.
     */
    public function current(): ?FailureIncident
    {
        $state = $this->gate->read();

        return $state === null ? null : self::decode($state);
    }

    /**
     * Apply change to open incident with compare-and-swap retry.
     *
     * @param callable(FailureIncident):?FailureIncident $change
     */
    public function update(callable $change): ?FailureIncident
    {
        for ($attempt = 0; $attempt < self::MAX_UPDATE_ATTEMPTS; ++$attempt) {
            $state    = $this->gate->read();
            $incident = $state === null ? null : self::decode($state);
            if ($state === null || $incident === null) {
                return null;
            }

            $next = $change($incident);
            if ($next === null) {
                return $incident;
            }

            $nextState = self::encode($next);
            if ($nextState === null) {
                return null;
            }
            if ($nextState === $state || $this->gate->replace($state, $nextState)) {
                return $next;
            }
        }

        return null;
    }

    /**
     * Close the open incident, returning it when this call closed a readable one.
     */
    public function close(): ?FailureIncident
    {
        $state = $this->gate->reset();

        return $state === null ? null : self::decode($state);
    }

    /**
     * Keep resolved incident for alert delivery; drop oldest beyond MAX_RESOLVED.
     */
    public function keepResolved(FailureIncident $incident): bool
    {
        return $this->changeResolved(static function (array $resolved) use ($incident): array {
            $resolved[$incident->id()] = $incident->toArray();

            return \array_slice($resolved, -self::MAX_RESOLVED, null, true);
        });
    }

    /**
     * The closed incident kept under this id, or null when none (or an unreadable one) is kept.
     */
    public function resolved(string $id): ?FailureIncident
    {
        return FailureIncident::fromArray(self::decodeResolved($this->resolvedGate->read())[$id] ?? null);
    }

    /**
     * Discard the closed incident kept under this id once its resolved alert has been handled.
     */
    public function forgetResolved(string $id): void
    {
        $this->changeResolved(static function (array $resolved) use ($id): array {
            unset($resolved[$id]);

            return $resolved;
        });
    }

    /**
     * Return all prefixed option names for incident state cleanup.
     *
     * @return list<string>
     */
    public static function optionNames(): array
    {
        return [Config::VAR_PREFIX . FailureNotificationGate::OPTION, self::resolvedOptionName()];
    }

    /**
     * The prefixed option name holding closed incidents awaiting their resolved alert.
     */
    public static function resolvedOptionName(): string
    {
        return Config::VAR_PREFIX . self::RESOLVED_OPTION_KEY;
    }

    /**
     * Apply change to resolved incidents via compare-and-swap with retries.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $change
     */
    private function changeResolved(callable $change): bool
    {
        for ($attempt = 0; $attempt < self::MAX_UPDATE_ATTEMPTS; ++$attempt) {
            $state    = $this->resolvedGate->read();
            $resolved = self::decodeResolved($state);
            $next     = $change($resolved);
            if ($next === $resolved || $this->swapResolved($state, $next)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write $next over the exact $state read (create, replace, or delete the row); false on a lost race.
     *
     * @param array<string,mixed> $next
     */
    private function swapResolved(?string $state, array $next): bool
    {
        if ($next === []) {
            return $state === null || $this->resolvedGate->delete($state);
        }

        $nextState = wp_json_encode($next);
        if (!\is_string($nextState)) {
            return false;
        }

        return $state === null ? $this->resolvedGate->acquire($nextState) : $this->resolvedGate->replace($state, $nextState);
    }

    /**
     * The kept closed incidents by id from a stored row; [] when there is no row or it is malformed.
     *
     * @return array<string,mixed>
     */
    private static function decodeResolved(?string $state): array
    {
        $resolved = $state === null ? null : json_decode($state, true);

        return \is_array($resolved) ? $resolved : [];
    }

    /**
     * JSON-encode an incident for the option row; null if encoding fails.
     */
    private static function encode(FailureIncident $incident): ?string
    {
        $state = wp_json_encode($incident->toArray());

        return \is_string($state) ? $state : null;
    }

    /**
     * Decode stored row; null for invalid incidents.
     */
    private static function decode(string $state): ?FailureIncident
    {
        return FailureIncident::fromArray(json_decode($state, true));
    }
}
