<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Exceptions\ProviderNotFoundException;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;

/**
 * Decides whether a connection is configured well enough to attempt a send right now, per its
 * provider's own completeness rule. Shared by routing planning and skip tracing so "sendable" means
 * exactly one thing across the dispatch path.
 */
final class ConnectionSendability
{
    private ProviderRegistry $registry;

    public function __construct(ProviderRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Whether the connection's provider resolves and its validator finds the settings/credentials
     * complete. An unresolved provider is never sendable.
     */
    public function isSendable(Connection $connection): bool
    {
        try {
            $provider = $this->registry->get($connection->getProvider());
        } catch (ProviderNotFoundException $e) {
            return false;
        }

        return $provider->validator()->validate($connection->getSettings(), $connection->getCredentials()) === [];
    }
}
