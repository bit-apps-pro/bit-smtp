<?php

namespace BitApps\SMTP\Mail\Auth;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\OAuth2ProviderInterface;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Support\ApiRequest;

/**
 * Signs requests with `Authorization: Bearer <access token>`, refreshed on demand by the token provider.
 */
final class OAuth2Strategy extends AbstractAuthStrategy
{
    private OAuth2TokenProvider $tokens;

    private OAuth2ProviderInterface $provider;

    public function __construct(OAuth2TokenProvider $tokens, OAuth2ProviderInterface $provider)
    {
        parent::__construct([]);
        $this->tokens   = $tokens;
        $this->provider = $provider;
    }

    public function apply(ApiRequest $request, Connection $connection): void
    {
        $request->setHeader('Authorization', 'Bearer ' . $this->tokens->accessToken($connection, $this->provider));
    }

    public function type(): string
    {
        return 'oauth2';
    }
}
