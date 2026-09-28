<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications\Support;

use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\ConnectionHealthStore;

/**
 * In-memory ConnectionHealthStore double for health-alert testing without WordPress options.
 */
final class InMemoryHealthStore extends ConnectionHealthStore
{
    /**
     * @var array<string,ConnectionHealth>
     */
    public array $records = [];

    /**
     * Every stored record by connection id.
     */
    public function all(): array
    {
        return $this->records;
    }

    /**
     * The stored record for a connection, if any.
     */
    public function get(string $id): ?ConnectionHealth
    {
        return $this->records[$id] ?? null;
    }

    /**
     * Store one record.
     */
    public function put(string $id, ConnectionHealth $health): void
    {
        $this->records[$id] = $health;
    }

    /**
     * Replace every record.
     */
    public function putMany(array $records): void
    {
        $this->records = $records;
    }

    /**
     * Keep only the given connections' records.
     */
    public function pruneTo(array $liveIds): void
    {
        $this->records = array_intersect_key($this->records, array_flip($liveIds));
    }

    /**
     * Remove one record.
     */
    public function delete(string $id): void
    {
        unset($this->records[$id]);
    }
}
