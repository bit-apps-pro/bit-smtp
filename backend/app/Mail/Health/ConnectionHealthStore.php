<?php

namespace BitApps\SMTP\Mail\Health;

/**
 * Read/write the per-connection health map. Backed by a single standalone option (autoload `no`,
 * read on demand — never on the hot page-load path), via plain get_option/update_option rather than
 * Config::getOption which would re-prefix the key. Mirrors how PluginSettings treats its own option.
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
     * Write the whole map in one option update, so a caller that has already read + mutated (and
     * pruned) the records in memory pays a single read-modify-write instead of one per record.
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
