<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Fake;

/**
 * In-memory gate whose every write loses to a concurrent request, for exercising give-up paths.
 */
final class LosingFailureNotificationGate extends InMemoryFailureNotificationGate
{
    /**
     * Always lose the create election.
     */
    public function acquire(string $state): bool
    {
        return false;
    }

    /**
     * Always lose the compare-and-swap.
     */
    public function replace(string $expected, string $next): bool
    {
        return false;
    }

    /**
     * Always lose the compare-and-delete.
     */
    public function delete(string $expected): bool
    {
        return false;
    }
}
