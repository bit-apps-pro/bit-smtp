<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Exceptions\ProviderNotFoundException;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;

/**
 * Decide if connection is configured well enough to send per provider's completeness rule.
 */
final class ConnectionSendability
{
    private ProviderRegistry $registry;

    public function __construct(ProviderRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * True if provider resolves and validator confirms settings/credentials complete.
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
