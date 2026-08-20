<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Credentials\CredentialCipher;
use BitApps\SMTP\Plugin;

/**
 * Drives wp_mail() through the Microsoft 365 Graph OAuth2 API transport (mocking the outbound HTTP
 * call) and proves the access_token reaches graph.microsoft.com as a Bearer token whether it was
 * stored encrypted-at-rest or as legacy plaintext, and that an expired token triggers a refresh
 * against login.microsoftonline.com whose rotated refresh_token is persisted (Microsoft rotates the
 * refresh_token on every refresh).
 *
 * @internal
 *
 * @coversNothing
 */
final class Microsoft365SendTest extends IntegrationTestCase
{
    private const SEND_ENDPOINT = 'https://graph.microsoft.com/v1.0/me/sendMail';

    private const CONNECTION_ID = 'conn_ms365';

    public function testSendsSuccessfullyWhenTheAccessTokenIsEncryptedAtRest(): void
    {
        $accessToken = 'EwB.encrypted-at-rest-token';
        $captured    = [];
        $filter      = $this->interceptGraphRequest($captured);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            Plugin::instance()->mailConfigService()->saveSettings($this->cachedTokenConfig($accessToken));

            $raw = Config::getOption('options');
            $this->assertStringStartsWith(
                CredentialCipher::VERSION_PREFIX,
                $raw['connections'][0]['credentials']['access_token']['value'],
                'the access_token must be encrypted on disk'
            );

            $sent = wp_mail('to@example.org', 'Encrypted M365 Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertGraphRequestCarriedTheAccessToken($sent, $captured, $accessToken, 'Encrypted M365 Send');
    }

    public function testSendsSuccessfullyWhenTheAccessTokenIsStoredAsLegacyPlaintext(): void
    {
        $accessToken = 'EwB.legacy-plaintext-token';
        $captured    = [];
        $filter      = $this->interceptGraphRequest($captured);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            // Bypasses MailConfigService::store(), mimicking an option row written before encryption existed.
            $this->storeOptions($this->cachedTokenConfig($accessToken));
            Plugin::instance()->mailConfigService()->reload();

            $raw = Config::getOption('options');
            $this->assertStringStartsNotWith(
                CredentialCipher::VERSION_PREFIX,
                $raw['connections'][0]['credentials']['access_token']['value'],
                'the access_token must be stored as unencrypted plaintext for the pre-encryption scenario'
            );

            $sent = wp_mail('to@example.org', 'Legacy Plaintext M365 Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertGraphRequestCarriedTheAccessToken($sent, $captured, $accessToken, 'Legacy Plaintext M365 Send');
    }

    public function testExpiredTokenRefreshesAndPersistsRotatedRefreshToken(): void
    {
        $newAccessToken  = 'EwB.freshly-minted-access-token';
        $rotatedRefresh  = 'M.rotated-refresh-token';
        $captured        = [];
        $filter          = $this->interceptGraphRequest($captured, $newAccessToken, $rotatedRefresh);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            Plugin::instance()->mailConfigService()->saveSettings($this->expiredTokenConfig('M.original-refresh-token'));

            $sent = wp_mail('to@example.org', 'Refreshed M365 Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertTrue($sent, 'wp_mail() should report the refreshed Microsoft 365 send as successful');

        // The expired token forced a refresh call before the send.
        $this->assertArrayHasKey('token_url', $captured, 'an expired token must trigger the OAuth token endpoint');
        $this->assertStringContainsString('login.microsoftonline.com', (string) $captured['token_url']);
        $this->assertSame(self::SEND_ENDPOINT, $captured['url'], 'the send must go to the Graph sendMail endpoint');

        // The send carried the freshly minted access token, never the expired one.
        $this->assertStringContainsString(
            'Bearer ' . $newAccessToken,
            (string) wp_json_encode($captured['headers']),
            'the send must use the refreshed access_token'
        );

        // Microsoft rotates the refresh_token on every refresh: the new one must be persisted (encrypted).
        Plugin::instance()->mailConfigService()->reload();
        $stored = Plugin::instance()->mailConfigService()->connectionById(self::CONNECTION_ID);
        $this->assertNotNull($stored);
        $this->assertSame(
            $rotatedRefresh,
            $stored->getCredentials()['refresh_token']['value'] ?? null,
            'the rotated refresh_token must be persisted for the next refresh'
        );

        $raw = Config::getOption('options');
        $this->assertStringStartsWith(
            CredentialCipher::VERSION_PREFIX,
            $raw['connections'][0]['credentials']['refresh_token']['value'],
            'the rotated refresh_token must be encrypted at rest'
        );

        $this->assertEmpty($this->mailpitMessages(), 'the API send must not deliver through SMTP/mailpit');
    }

    /**
     * @param array<string,mixed> $captured
     */
    private function assertGraphRequestCarriedTheAccessToken(bool $sent, array $captured, string $accessToken, string $subject): void
    {
        $this->assertTrue($sent, 'wp_mail() should report the Microsoft 365 send as successful');
        $this->assertNotEmpty($captured, 'the Microsoft 365 transport should have issued an outbound HTTP request');

        // A cached, future-dated token must be used directly: the only outbound call is the send.
        $this->assertArrayNotHasKey('token_url', $captured, 'a cached token must not trigger an OAuth refresh call');
        $this->assertSame(self::SEND_ENDPOINT, $captured['url'], 'the only outbound request must be the Graph send');

        $this->assertStringContainsString(
            'Bearer ' . $accessToken,
            (string) wp_json_encode($captured['headers']),
            'the cached access_token must be sent as a Bearer token, decrypted back to plaintext'
        );

        $mime = (string) base64_decode((string) $captured['body'], true);
        $this->assertStringContainsString('to@example.org', $mime, 'the recipient must be in the MIME message');
        $this->assertStringContainsString('Subject: ' . $subject, $mime, 'the subject must be in the MIME message');

        $this->assertEmpty($this->mailpitMessages(), 'the API send must not deliver through SMTP/mailpit');
    }

    /**
     * @return array<string,mixed>
     */
    private function cachedTokenConfig(string $accessToken): array
    {
        return $this->config([
            'settings'    => ['token_expires_at' => time() + 3600, 'tenant' => 'common'],
            'credentials' => ['access_token' => ['source' => 'database', 'value' => $accessToken]],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function expiredTokenConfig(string $refreshToken): array
    {
        return $this->config([
            'settings'    => ['token_expires_at' => time() - 100, 'tenant' => 'common', 'client_id' => 'client-id-123'],
            'credentials' => [
                'access_token'  => ['source' => 'database', 'value' => 'EwB.stale-access-token'],
                'refresh_token' => ['source' => 'database', 'value' => $refreshToken],
                'client_secret' => ['source' => 'database', 'value' => 'client-secret-xyz'],
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $connectionOverrides
     *
     * @return array<string,mixed>
     */
    private function config(array $connectionOverrides): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => self::CONNECTION_ID,
            'fallback_connection_ids' => [],
            'connections'             => [
                array_merge([
                    'id'           => self::CONNECTION_ID,
                    'provider'     => 'microsoft365',
                    'kind'         => 'api',
                    'name'         => self::CONNECTION_ID,
                    'enabled'      => true,
                    'fromEmail'    => 'from@example.org',
                    'fromName'     => 'From',
                    'replyToEmail' => '',
                ], $connectionOverrides),
            ],
            'features' => [],
        ];
    }

    /**
     * Intercepts the Graph send call, and (when refresh values are given) answers the OAuth token
     * endpoint with a new access_token plus a rotated refresh_token so the test can prove both the
     * cached-token short-circuit and the refresh-rotation persistence.
     *
     * @param array<string,mixed> $captured
     */
    private function interceptGraphRequest(array &$captured, ?string $refreshedAccessToken = null, ?string $rotatedRefreshToken = null): callable
    {
        return static function ($preempt, $args, $url) use (&$captured, $refreshedAccessToken, $rotatedRefreshToken) {
            if (strpos($url, 'login.microsoftonline.com') !== false) {
                $captured['token_url'] = $url;

                return [
                    'headers'  => ['content-type' => 'application/json'],
                    'body'     => (string) wp_json_encode([
                        'access_token'  => $refreshedAccessToken,
                        'refresh_token' => $rotatedRefreshToken,
                        'expires_in'    => 3600,
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }

            if (strpos($url, 'graph.microsoft.com') === false) {
                return $preempt;
            }

            $captured['url']     = $url;
            $captured['headers'] = $args['headers'] ?? [];
            $captured['body']    = $args['body']    ?? '';

            return [
                'headers'  => [],
                'body'     => '',
                'response' => ['code' => 202, 'message' => 'Accepted'],
                'cookies'  => [],
                'filename' => null,
            ];
        };
    }
}
