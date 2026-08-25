<?php

namespace BitApps\SMTP\CLI;

use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\ConnectionHealthService;
use BitApps\SMTP\Mail\Health\HealthProbeRunner;

\defined('ABSPATH') || exit();

/**
 * `wp bit-smtp health check`: actively probe every live connection, then print the refreshed
 * per-connection health as a secret-free table. Reuses HealthProbeRunner::run() and the same public
 * health projection the Connections UI reads.
 */
final class HealthCheckCommand
{
    /**
     * Health columns surfaced to the operator, drawn from ConnectionHealth::toPublicArray() plus the
     * connection id it is keyed by.
     */
    private const FIELDS = [
        'connection_id',
        'status',
        'circuit',
        'consecutive_failures',
        'last_ok_at',
        'last_error',
        'last_probe_at',
    ];

    private HealthProbeRunner $runner;

    private ConnectionHealthService $health;

    public function __construct(HealthProbeRunner $runner, ConnectionHealthService $health)
    {
        $this->runner = $runner;
        $this->health = $health;
    }

    /**
     * Run the probe pass, then render the resulting health map (a notice when no connections exist).
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assocArgs
     */
    public function run(array $args, array $assocArgs, CliReporter $reporter): void
    {
        $this->runner->run();

        $health = $this->health->list();
        if ($health === []) {
            $reporter->line('No connections configured.');

            return;
        }

        $items = [];
        foreach ($health as $connectionId => $record) {
            $items[] = $this->row((string) $connectionId, $record);
        }

        $reporter->renderItems('table', $items, self::FIELDS);
    }

    /**
     * Flatten one connection's public health record into a display row keyed by FIELDS.
     *
     * @return array<string,mixed>
     */
    private function row(string $connectionId, ConnectionHealth $record): array
    {
        $public = $record->toPublicArray();

        return [
            'connection_id'        => $connectionId,
            'status'               => $public['status'],
            'circuit'              => $public['circuit'],
            'consecutive_failures' => $public['consecutive_failures'],
            'last_ok_at'           => $public['last_ok_at']    ?? '',
            'last_error'           => $public['last_error']    ?? '',
            'last_probe_at'        => $public['last_probe_at'] ?? '',
        ];
    }
}
