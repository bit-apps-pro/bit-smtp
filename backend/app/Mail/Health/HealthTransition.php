<?php

namespace BitApps\SMTP\Mail\Health;

use BitApps\SMTP\Mail\Connections\Connection;

/**
 * A before/after status pair emitted when a connection's derived health status changed, carrying the
 * connection it belongs to so a downstream alerter can react without recomputing or re-resolving it.
 */
final class HealthTransition
{
    private string $before;

    private string $after;

    private Connection $connection;

    public function __construct(string $before, string $after, Connection $connection)
    {
        $this->before     = $before;
        $this->after      = $after;
        $this->connection = $connection;
    }

    public function before(): string
    {
        return $this->before;
    }

    public function after(): string
    {
        return $this->after;
    }

    public function connection(): Connection
    {
        return $this->connection;
    }
}
