<?php

namespace BitApps\SMTP\Mail\Auth;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Exceptions\AuthConfigException;

/**
 * Base for auth strategies: credential lookup, required-credential guarding, and template interpolation.
 */
abstract class AbstractAuthStrategy implements AuthStrategyInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    protected function secret(Connection $connection, string $key): string
    {
        return (string) ($connection->getCredentials()[$key]['value'] ?? '');
    }

    protected function requireSecret(Connection $connection, string $key): string
    {
        $value = $this->secret($connection, $key);

        if ($value === '') {
            throw AuthConfigException::missing($key);
        }

        return $value;
    }

    protected function interpolate(string $template, Connection $connection): string
    {
        return preg_replace_callback('/\{([^{}]+)\}/', function (array $matches) use ($connection) {
            $key         = $matches[1];
            $credentials = $connection->getCredentials();

            if (isset($credentials[$key]['value'])) {
                return (string) $credentials[$key]['value'];
            }

            return (string) $connection->setting($key, '');
        }, $template);
    }
}
