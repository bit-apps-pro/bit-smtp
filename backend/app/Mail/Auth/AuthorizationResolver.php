<?php

namespace BitApps\SMTP\Mail\Auth;

use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Contracts\OAuth2ProviderInterface;
use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use InvalidArgumentException;

/**
 * Maps a provider's declared auth type (see ProviderInterface::authConfig) to the strategy that can sign its requests.
 */
final class AuthorizationResolver
{
    private OAuth2TokenProvider $tokens;

    private SigV4Signer $signer;

    public function __construct(OAuth2TokenProvider $tokens, SigV4Signer $signer)
    {
        $this->tokens = $tokens;
        $this->signer = $signer;
    }

    public function resolve(ProviderInterface $provider, Connection $connection): AuthStrategyInterface
    {
        $authConfig = $provider->authConfig();

        // oauth2 needs the provider's transport (see resolveOAuth2); everything else is
        // connection-independent and shared with descriptor-driven providers.
        if (($authConfig['type'] ?? null) === 'oauth2') {
            return $this->resolveOAuth2($provider);
        }

        return $this->resolveFromConfig($authConfig);
    }

    /**
     * Resolve a connection-independent auth strategy straight from an auth descriptor.
     * oauth2 is intentionally unsupported here: it depends on the provider's transport.
     */
    public function resolveFromConfig(array $authConfig): AuthStrategyInterface
    {
        $type   = $authConfig['type'];
        $params = $authConfig['params'] ?? [];

        switch ($type) {
            case 'bearer':
                return new BearerTokenStrategy($params);

            case 'api_key':
                return new ApiKeyStrategy($params);

            case 'basic':
                return new BasicAuthStrategy($params);

            case 'aws_sigv4':
                return new AwsSigV4Strategy($this->signer, $params['service'] ?? '');

            default:
                throw new InvalidArgumentException(esc_html("No HTTP auth strategy for type: {$type}"));
        }
    }

    private function resolveOAuth2(ProviderInterface $provider): AuthStrategyInterface
    {
        $transport = $provider->transport();

        if (!$transport instanceof OAuth2ProviderInterface) {
            throw new InvalidArgumentException(esc_html('Provider transport must implement OAuth2ProviderInterface for oauth2 auth type: ' . \get_class($transport)));
        }

        return new OAuth2Strategy($this->tokens, $transport);
    }
}
