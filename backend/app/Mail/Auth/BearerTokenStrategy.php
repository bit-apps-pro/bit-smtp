<?php

namespace BitApps\SMTP\Mail\Auth;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Support\ApiRequest;

/**
 * Signs requests with an `Authorization: Bearer <secret>` header.
 */
final class BearerTokenStrategy extends AbstractAuthStrategy
{
    public function apply(ApiRequest $request, Connection $connection): void
    {
        $credentialKey = $this->config['credentialKey'] ?? 'api_key';

        $request->setHeader('Authorization', 'Bearer ' . $this->requireSecret($connection, $credentialKey));
    }

    public function type(): string
    {
        return 'bearer';
    }
}
