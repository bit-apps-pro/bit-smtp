<?php

namespace BitApps\SMTP\Mail\OAuth;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\OAuth2ProviderInterface;
use BitApps\SMTP\Mail\Exceptions\OAuthException;
use BitApps\SMTP\Mail\Http\ApiClient;

/**
 * Resolve OAuth2 access token; return cached or refresh and persist.
 */
class OAuth2TokenProvider
{
    /**
     * Refresh early so a token doesn't expire mid-flight while being used.
     */
    private const EXPIRY_SKEW_SECONDS = 60;

    private const OAUTH_ERROR_PATTERN = '/^[a-z0-9_.-]{1,64}$/iD';

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

        $response = $this->client->postForm($provider->tokenUrl($connection), [
            'grant_type'    => 'refresh_token',
            'client_id'     => $connection->getSettings()['client_id']                 ?? '',
            'client_secret' => $connection->getCredentials()['client_secret']['value'] ?? '',
            'refresh_token' => $refreshToken,
        ]);

        $body = $response->getBody();
        if (!$response->isOk() || !\is_array($body) || empty($body['access_token'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Int status and a vetted OAuth error code; plain-text message escaped where displayed.
            throw OAuthException::refreshFailed($response->getStatus(), $this->oauthError($body));
        }

        $accessToken     = (string) $body['access_token'];
        $newRefreshToken = (string) ($body['refresh_token'] ?? '');
        $expiresAt       = time() + (int) ($body['expires_in'] ?? 0);

        $this->config->saveConnection($this->withRefreshedToken($connection, $accessToken, $newRefreshToken, $expiresAt));

        return $accessToken;
    }

    /**
     * Update connection with refreshed token; preserve existing refresh_token if provider didn't return one.
     */
    private function withRefreshedToken(Connection $connection, string $accessToken, string $newRefreshToken, int $expiresAt): array
    {
        $updated = $connection->toArray();

        if ($newRefreshToken !== '') {
            $updated['credentials']['refresh_token'] = ['source' => 'database', 'value' => $newRefreshToken];
        }

        $updated['credentials']['access_token']  = ['source' => 'database', 'value' => $accessToken];
        $updated['settings']['token_expires_at'] = $expiresAt;

        return $updated;
    }

    /**
     * The RFC 6749 error code from a token response when it is a plain token; the free-text description is never used, as it can name the account.
     *
     * @param mixed $body decoded token-endpoint response
     */
    private function oauthError($body): string
    {
        $error = \is_array($body) ? ($body['error'] ?? '') : '';

        return \is_string($error) && preg_match(self::OAUTH_ERROR_PATTERN, $error) === 1 ? $error : '';
    }
}
