<?php

namespace BitApps\SMTP\Mail\Health;

use BitApps\SMTP\Mail\Connections\Connection;

/**
 * Before/after status pair on derived health change; carries connection for alerter reaction.
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
