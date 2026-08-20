<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Credentials\CredentialCipher;
use BitApps\SMTP\Plugin;

/**
 * Drives wp_mail() through the Gmail OAuth2 API transport (mocking the outbound HTTP call) and
 * proves the access_token credential reaches the request as plaintext whether it was stored
 * encrypted-at-rest or as legacy (pre-encryption) plaintext. The connection carries a far-future
 * token_expires_at so OAuth2TokenProvider serves the cached token without an extra refresh call.
 *
 * @internal
 *
 * @coversNothing
 */
final class GmailSendTest extends IntegrationTestCase
{
    private const SEND_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    public function testSendsSuccessfullyWhenTheAccessTokenIsEncryptedAtRest(): void
    {
        $accessToken = 'ya29.encrypted-at-rest-token';
        $captured    = [];
        $filter      = $this->interceptGmailRequest($captured);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            Plugin::instance()->mailConfigService()->saveSettings($this->v2($accessToken));

            $raw = Config::getOption('options');
            $this->assertStringStartsWith(
                CredentialCipher::VERSION_PREFIX,
                $raw['connections'][0]['credentials']['access_token']['value'],
                'the access_token must be encrypted on disk'
            );

            $sent = wp_mail('to@example.org', 'Encrypted Gmail Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertGmailRequestCarriedTheAccessToken($sent, $captured, $accessToken, 'Encrypted Gmail Send');
    }

    public function testSendsSuccessfullyWhenTheAccessTokenIsStoredAsLegacyPlaintext(): void
    {
        $accessToken = 'ya29.legacy-plaintext-token';
        $captured    = [];
        $filter      = $this->interceptGmailRequest($captured);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            // Bypasses MailConfigService::store(), mimicking an option row written before encryption existed.
            $this->storeOptions($this->v2($accessToken));
            Plugin::instance()->mailConfigService()->reload();

            $raw = Config::getOption('options');
            $this->assertStringStartsNotWith(
                CredentialCipher::VERSION_PREFIX,
                $raw['connections'][0]['credentials']['access_token']['value'],
                'the access_token must be stored as unencrypted plaintext for the pre-encryption scenario'
            );

            $sent = wp_mail('to@example.org', 'Legacy Plaintext Gmail Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertGmailRequestCarriedTheAccessToken($sent, $captured, $accessToken, 'Legacy Plaintext Gmail Send');
    }

    /**
     * @param array<string,mixed> $captured
     */
    private function assertGmailRequestCarriedTheAccessToken(bool $sent, array $captured, string $accessToken, string $subject): void
    {
        $this->assertTrue($sent, 'wp_mail() should report the Gmail send as successful');
        $this->assertNotEmpty($captured, 'the Gmail transport should have issued an outbound HTTP request');

        // A cached, future-dated token must be used directly: the only outbound call is the send,
        // never a call to the OAuth token endpoint to refresh.
        $this->assertArrayNotHasKey('token_url', $captured, 'a cached token must not trigger an OAuth refresh call');
        $this->assertSame(self::SEND_ENDPOINT, $captured['url'], 'the only outbound request must be the Gmail send');

        $this->assertStringContainsString(
            'Bearer ' . $accessToken,
            (string) wp_json_encode($captured['headers']),
            'the cached access_token must be sent as a Bearer token, decrypted back to plaintext'
        );

        $mime = $this->mimeFromRawBody((string) $captured['body']);
        $this->assertStringContainsString('to@example.org', $mime, 'the recipient must be in the MIME message');
        $this->assertStringContainsString('Subject: ' . $subject, $mime, 'the subject must be in the MIME message');

        $this->assertEmpty($this->mailpitMessages(), 'the API send must not deliver through SMTP/mailpit');
    }

    /**
     * @return array<string,mixed>
     */
    private function v2(string $accessToken): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_gmail',
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'           => 'conn_gmail',
                    'provider'     => 'gmail',
                    'kind'         => 'api',
                    'name'         => 'conn_gmail',
                    'enabled'      => true,
                    'fromEmail'    => 'from@example.org',
                    'fromName'     => 'From',
                    'replyToEmail' => '',
                    'settings'     => ['token_expires_at' => time() + 3600],
                    'credentials'  => ['access_token' => ['source' => 'database', 'value' => $accessToken]],
                ],
            ],
            'features' => [],
        ];
    }

    /**
     * Intercepts the Gmail send call, and separately flags any OAuth token-endpoint call so the
     * test can prove a cached token short-circuits the refresh path.
     *
     * @param array<string,mixed> $captured
     */
    private function interceptGmailRequest(array &$captured): callable
    {
        return static function ($preempt, $args, $url) use (&$captured) {
            if (strpos($url, 'oauth2.googleapis.com') !== false) {
                $captured['token_url'] = $url;

                return $preempt;
            }

            if (strpos($url, 'gmail.googleapis.com') === false) {
                return $preempt;
            }

            $captured['url']     = $url;
            $captured['headers'] = $args['headers'] ?? [];
            $captured['body']    = $args['body']    ?? '';

            return [
                'headers'  => [],
                'body'     => '',
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        };
    }

    /**
     * Decode Gmail's {"raw": "<base64url MIME>"} JSON body back into the raw MIME string.
     */
    private function mimeFromRawBody(string $body): string
    {
        $decoded = json_decode($body, true);
        $raw     = \is_array($decoded) ? (string) ($decoded['raw'] ?? '') : '';

        $base64  = strtr($raw, '-_', '+/');
        $base64 .= str_repeat('=', (4 - \strlen($base64) % 4) % 4);

        return (string) base64_decode($base64, true);
    }
}
