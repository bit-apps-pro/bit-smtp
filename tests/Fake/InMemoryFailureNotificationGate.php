<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Fake;

use BitApps\SMTP\Mail\Notifications\FailureNotificationGate;

/**
 * In-memory FailureNotificationGate that mimics the option-row's insert-once/CAS semantics.
 */
class InMemoryFailureNotificationGate extends FailureNotificationGate
{
    public ?string $state = null;

    /**
     * Store the state only when no incident is open.
     */
    public function acquire(string $state): bool
    {
        if ($this->state !== null) {
            return false;
        }

        $this->state = $state;

        return true;
    }

    /**
     * The stored state, if any.
     */
    public function read(): ?string
    {
        return $this->state;
    }

    /**
     * Swap the state only when it still equals $expected.
     */
    public function replace(string $expected, string $next): bool
    {
        if ($this->state !== $expected) {
            return false;
        }

        $this->state = $next;

        return true;
    }

    /**
     * Remove and return the stored state.
     */
    public function reset(): ?string
    {
        $state       = $this->state;
        $this->state = null;

        return $state;
    }

    /**
     * Remove the state only when it still equals $expected.
     */
    public function delete(string $expected): bool
    {
        if ($this->state !== $expected) {
            return false;
        }

        $this->state = null;

        return true;
    }
}
