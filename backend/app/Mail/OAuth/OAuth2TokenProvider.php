<?php

namespace BitApps\SMTP\Mail\OAuth;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\OAuth2ProviderInterface;
use BitApps\SMTP\Mail\Exceptions\OAuthException;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;

/**
 * Resolves a usable OAuth2 access token for a connection: returns the cached token while it
 * still has headroom, otherwise refreshes it via the provider's token endpoint and persists
 * the refreshed token back onto the connection.
 */
class OAuth2TokenProvider
{
    /**
     * Refresh early so a token doesn't expire mid-flight while being used.
     */
    private const EXPIRY_SKEW_SECONDS = 60;

    private ApiClient $client;

    private MailConfigService $config;

    public function __construct(ApiClient $client, MailConfigService $config)
    {
        $this->client = $client;
        $this->config = $config;
    }

    public function accessToken(Connection $connection, OAuth2ProviderInterface $provider): string
    {
        $accessToken = $connection->getCredentials()['access_token']['value'] ?? '';
        $expiresAt   = (int) ($connection->getSettings()['token_expires_at'] ?? 0);

        if ($accessToken !== '' && $expiresAt > time() + self::EXPIRY_SKEW_SECONDS) {
            return $accessToken;
        }

        return $this->refresh($connection, $provider);
    }

    private function refresh(Connection $connection, OAuth2ProviderInterface $provider): string
    {
        $refreshToken = $connection->getCredentials()['refresh_token']['value'] ?? '';
        if ($refreshToken === '') {
            throw OAuthException::missingRefreshToken();
        }

        $response = $this->client->postForm($provider->tokenUrl(), [
            'grant_type'    => 'refresh_token',
            'client_id'     => $connection->getSettings()['client_id']                 ?? '',
            'client_secret' => $connection->getCredentials()['client_secret']['value'] ?? '',
            'refresh_token' => $refreshToken,
        ]);

        $body = $response->getBody();
        if (!$response->isOk() || !\is_array($body) || empty($body['access_token'])) {
            throw OAuthException::refreshFailed($this->errorMessage($response));
        }

        $accessToken = (string) $body['access_token'];
        $expiresAt   = time() + (int) ($body['expires_in'] ?? 0);

        $this->config->saveConnection($this->withRefreshedToken($connection, $accessToken, $expiresAt));

        return $accessToken;
    }

    private function withRefreshedToken(Connection $connection, string $accessToken, int $expiresAt): array
    {
        $updated = $connection->toArray();

        $updated['credentials']['access_token']  = ['source' => 'database', 'value' => $accessToken];
        $updated['settings']['token_expires_at'] = $expiresAt;

        return $updated;
    }

    private function errorMessage(ApiResponse $response): string
    {
        $body = $response->getBody();
        if (\is_array($body) && !empty($body['error_description'])) {
            return (string) $body['error_description'];
        }

        if (\is_array($body) && !empty($body['error'])) {
            return (string) $body['error'];
        }

        return 'HTTP ' . $response->getStatus();
    }
}
