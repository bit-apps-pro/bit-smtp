<?php

namespace BitApps\SMTP\Mail\Auth;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Credentials\WpConfigCredentialSource;
use BitApps\SMTP\Mail\Exceptions\AuthConfigException;

/**
 * Base for auth strategies: credential lookup, required-credential guarding, and template interpolation.
 */
abstract class AbstractAuthStrategy implements AuthStrategyInterface
{
    protected array $config;

    private WpConfigCredentialSource $wpConfig;

    public function __construct(array $config = [], ?WpConfigCredentialSource $wpConfig = null)
    {
        $this->config   = $config;
        $this->wpConfig = $wpConfig ?? new WpConfigCredentialSource();
    }

    /**
     * Resolve credential from wp-config constant (if set) or stored connection value.
     */
    protected function secret(Connection $connection, string $key): string
    {
        $override = $this->wpConfig->resolve($connection->getId(), $key);
        if ($override !== null) {
            return $override;
        }

        return (string) ($connection->getCredentials()[$key]['value'] ?? '');
    }

    protected function requireSecret(Connection $connection, string $key): string
    {
        $value = $this->secret($connection, $key);

        if ($value === '') {
            throw AuthConfigException::missing(esc_html($key));
        }

        return $value;
    }

    /**
     * Replace template placeholders {key} with values from wp-config, credentials, or settings.
     */
    protected function interpolate(string $template, Connection $connection): string
    {
        return preg_replace_callback('/\{([^{}]+)\}/', function (array $matches) use ($connection) {
            $key = $matches[1];

            $override = $this->wpConfig->resolve($connection->getId(), $key);
            if ($override !== null) {
                return $override;
            }

            $credentials = $connection->getCredentials();
            if (isset($credentials[$key]['value'])) {
                return (string) $credentials[$key]['value'];
            }

            return (string) $connection->setting($key, '');
        }, $template);
    }
}
