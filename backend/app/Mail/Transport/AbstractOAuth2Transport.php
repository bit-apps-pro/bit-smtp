<?php

namespace BitApps\SMTP\Mail\Transport;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\OAuth2ProviderInterface;

/**
 * Base for OAuth2-authenticated API transports; authentication via OAuth2Strategy applied in subclass constructor.
 */
abstract class AbstractOAuth2Transport extends AbstractApiTransport implements OAuth2ProviderInterface
{
    /**
     * Unused: OAuth2 transports authenticate via the OAuth2Strategy applied in the subclass constructor.
     */
    protected function authHeaders(Connection $connection): array
    {
        return [];
    }
}
