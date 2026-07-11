<?php

namespace BitApps\SMTP\Mail\Transport;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\OAuth2ProviderInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;

/**
 * Base for OAuth2-authenticated API transports: authorizes requests with a bearer access
 * token resolved (and refreshed as needed) by OAuth2TokenProvider.
 */
abstract class AbstractOAuth2Transport extends AbstractApiTransport implements OAuth2ProviderInterface
{
    private OAuth2TokenProvider $tokens;

    public function __construct(ApiClient $client, OAuth2TokenProvider $tokens)
    {
        parent::__construct($client);
        $this->tokens = $tokens;
    }

    protected function authHeaders(Connection $connection): array
    {
        return ['Authorization' => 'Bearer ' . $this->tokens->accessToken($connection, $this)];
    }
}
