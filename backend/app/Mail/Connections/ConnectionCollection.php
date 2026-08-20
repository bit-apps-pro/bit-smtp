<?php

namespace BitApps\SMTP\Mail\Connections;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;

class ConnectionCollection implements IteratorAggregate, Countable
{
    /**
     * @var Connection[]
     */
    private array $connections;

    public function __construct(array $connections)
    {
        foreach ($connections as $element) {
            if (!($element instanceof Connection)) {
                throw new InvalidArgumentException('ConnectionCollection expects only Connection instances.');
            }
        }
        $this->connections = $connections;
    }

    public function all(): array
    {
        return $this->connections;
    }

    public function byId(string $id): ?Connection
    {
        foreach ($this->connections as $connection) {
            if ($connection->getId() === $id) {
                return $connection;
            }
        }

        return null;
    }

    public function enabled(): self
    {
        return new self(array_values(array_filter(
            $this->connections,
            static fn (Connection $c) => $c->isEnabled()
        )));
    }

    public function first(): ?Connection
    {
        return $this->connections[0] ?? null;
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->connections);
    }

    public function count(): int
    {
        return \count($this->connections);
    }
}
