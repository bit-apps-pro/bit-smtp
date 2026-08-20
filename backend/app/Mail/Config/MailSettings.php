<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Config;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Connections\ConnectionCollection;

final class MailSettings
{
    private int $schemaVersion;

    private bool $enabled;

    private string $defaultConnectionId;

    private array $fallbackConnectionIds;

    private ConnectionCollection $connections;

    private array $features;

    private function __construct(
        int $schemaVersion,
        bool $enabled,
        string $defaultConnectionId,
        array $fallbackConnectionIds,
        ConnectionCollection $connections,
        array $features
    ) {
        $this->schemaVersion         = $schemaVersion;
        $this->enabled               = $enabled;
        $this->defaultConnectionId   = $defaultConnectionId;
        $this->fallbackConnectionIds = $fallbackConnectionIds;
        $this->connections           = $connections;
        $this->features              = $features;
    }

    public static function fromArray(array $data): self
    {
        $connections = array_map(
            static function (array $conn): Connection {
                return Connection::fromArray($conn);
            },
            $data['connections'] ?? []
        );

        return new self(
            (int) ($data['schema_version'] ?? 2),
            (bool) ($data['enabled'] ?? false),
            (string) ($data['default_connection_id'] ?? ''),
            (array) ($data['fallback_connection_ids'] ?? []),
            new ConnectionCollection(array_values($connections)),
            (array) ($data['features'] ?? [])
        );
    }

    public function toArray(): array
    {
        return [
            'schema_version'          => $this->schemaVersion,
            'enabled'                 => $this->enabled,
            'default_connection_id'   => $this->defaultConnectionId,
            'fallback_connection_ids' => $this->fallbackConnectionIds,
            'connections'             => array_map(
                static function (Connection $c): array {
                    return $c->toArray();
                },
                $this->connections->all()
            ),
            'features'                => $this->features,
        ];
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getDefaultConnectionId(): string
    {
        return $this->defaultConnectionId;
    }

    public function getFallbackConnectionIds(): array
    {
        return $this->fallbackConnectionIds;
    }

    public function getConnections(): ConnectionCollection
    {
        return $this->connections;
    }

    public function getFeatures(): array
    {
        return $this->features;
    }

    public function defaultConnection(): ?Connection
    {
        return $this->connections->byId($this->defaultConnectionId);
    }
}
