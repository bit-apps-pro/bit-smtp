<?php

namespace BitApps\SMTP\Mail\Transport;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\OAuth2ProviderInterface;

/**
 * Base for OAuth2-authenticated API transports. Authentication is applied by an OAuth2Strategy
 * wired into the concrete transport's constructor; this base only marks the transport as an
 * OAuth2 provider (authUrl/tokenUrl/scopes) so the OAuth authorization flow can drive it.
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
