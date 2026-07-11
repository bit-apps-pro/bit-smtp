<?php

namespace BitApps\SMTP\Mail\Transport;

use BitApps\SMTP\Mail\Connections\Connection;

/**
 * Base for API transports authenticated by a single bearer API key credential on the connection.
 */
abstract class AbstractApiKeyTransport extends AbstractApiTransport
{
    protected function authHeaders(Connection $connection): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey($connection)];
    }

    protected function apiKey(Connection $connection): string
    {
        return $connection->getCredentials()[$this->credentialKey()]['value'] ?? '';
    }

    protected function credentialKey(): string
    {
        return 'api_key';
    }
}
