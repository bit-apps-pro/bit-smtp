<?php

namespace BitApps\SMTP\Mail\Credentials;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\CredentialResolverInterface;

/**
 * Resolves a connection's credential at send time, giving a wp-config constant precedence over the
 * stored (DB) value. Composes the existing stored-credential resolver so the DB/decrypt path stays
 * untouched whenever no override constant is defined.
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
     * Resolve one credential for a connection. A wp-config constant wins and is returned verbatim
     * (it is already plaintext and must never pass through CredentialCipher); otherwise the stored
     * value is resolved through the unchanged DB path.
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
