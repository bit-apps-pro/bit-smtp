<?php

namespace BitApps\SMTP\Mail\Credentials;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\CredentialResolverInterface;

/**
 * Resolve connection credentials at send time; wp-config constants override stored values.
 */
final class ConnectionSecretResolver
{
    private CredentialResolverInterface $storedResolver;

    private WpConfigCredentialSource $wpConfig;

    public function __construct(CredentialResolverInterface $storedResolver, ?WpConfigCredentialSource $wpConfig = null)
    {
        $this->storedResolver = $storedResolver;
        $this->wpConfig       = $wpConfig ?? new WpConfigCredentialSource();
    }

    /**
     * Resolve one credential; wp-config constant wins over stored value.
     */
    public function resolve(Connection $connection, string $key): ?string
    {
        $override = $this->wpConfig->resolve($connection->getId(), $key);
        if ($override !== null) {
            return $override;
        }

        $credentials = $connection->getCredentials();
        if (!isset($credentials[$key]) || !\is_array($credentials[$key])) {
            return null;
        }

        return $this->storedResolver->resolve(Credential::fromArray($credentials[$key]));
    }
}
