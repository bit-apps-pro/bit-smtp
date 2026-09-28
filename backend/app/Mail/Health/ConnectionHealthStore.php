<?php

namespace BitApps\SMTP\Mail\Health;

/**
 * Read/write per-connection health map via standalone option (autoload no, on-demand read).
 */
class ConnectionHealthStore
{
    public const OPTION_NAME = 'bit_smtp_connection_health';

    /**
     * Every stored record, keyed by connection id.
     *
     * @return array<string,ConnectionHealth>
     */
    public function all(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        if (!\is_array($stored)) {
            return [];
        }

        $records = [];
        foreach ($stored as $id => $record) {
            if (\is_string($id) && \is_array($record)) {
                $records[$id] = ConnectionHealth::fromArray($record);
            }
        }

        return $records;
    }

    public function get(string $id): ?ConnectionHealth
    {
        return $this->all()[$id] ?? null;
    }

    public function put(string $id, ConnectionHealth $health): void
    {
        $records      = $this->all();
        $records[$id] = $health;
        $this->persist($records);
    }

    /**
     * Write whole map in one update; caller pays single read-modify-write instead of per-record writes.
     *
     * @param array<string,ConnectionHealth> $records
     */
    public function putMany(array $records): void
    {
        $this->persist($records);
    }

    /**
     * Evict any record whose connection no longer exists, bounding the option to live connections.
     *
     * @param string[] $liveIds
     */
    public function pruneTo(array $liveIds): void
    {
        $records = $this->all();
        $pruned  = array_intersect_key($records, array_flip($liveIds));
        if (\count($pruned) !== \count($records)) {
            $this->persist($pruned);
        }
    }

    public function delete(string $id): void
    {
        $records = $this->all();
        if (!isset($records[$id])) {
            return;
        }

        unset($records[$id]);
        $this->persist($records);
    }

    /**
     * @param array<string,ConnectionHealth> $records
     */
    private function persist(array $records): void
    {
        $payload = [];
        foreach ($records as $id => $health) {
            $payload[$id] = $health->toArray();
        }

        update_option(self::OPTION_NAME, $payload, false);
    }
}
