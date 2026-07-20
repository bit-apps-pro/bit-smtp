<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Credentials\CredentialCipher;
use BitApps\SMTP\Plugin;

/**
 * Drives wp_mail() through the Mailgun API transport (mocking the outbound HTTP call) and proves
 * the api_key credential reaches the request as the "api:<key>" Basic auth header, and the
 * {domain} setting reaches the request as the SSRF-validated /v3/{domain}/messages path, whether
 * the api_key was stored encrypted-at-rest or as legacy (pre-encryption) plaintext.
 *
 * @internal
 *
 * @coversNothing
 */
final class MailgunSendTest extends IntegrationTestCase
{
    public function testSendsSuccessfullyWhenTheCredentialsAreEncryptedAtRest(): void
    {
        $apiKey   = 'encrypted-at-rest-api-key';
        $captured = [];
        $filter   = $this->interceptMailgunRequest($captured);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            Plugin::instance()->mailConfigService()->saveSettings($this->v2($apiKey));

            $raw         = Config::getOption('options');
            $credentials = $raw['connections'][0]['credentials'];
            $this->assertStringStartsWith(
                CredentialCipher::VERSION_PREFIX,
                $credentials['api_key']['value'],
                'the api_key must be encrypted on disk'
            );

            $sent = wp_mail('to@example.org', 'Encrypted Mailgun Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertMailgunRequestCarriedTheCredentials($sent, $captured, $apiKey, 'Encrypted Mailgun Send');
    }

    public function testSendsSuccessfullyWhenTheCredentialsAreStoredAsLegacyPlaintext(): void
    {
        $apiKey   = 'legacy-plaintext-api-key';
        $captured = [];
        $filter   = $this->interceptMailgunRequest($captured);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            // Bypasses MailConfigService::store(), mimicking an option row written before encryption existed.
            $this->storeOptions($this->v2($apiKey));
            Plugin::instance()->mailConfigService()->reload();

            $raw         = Config::getOption('options');
            $credentials = $raw['connections'][0]['credentials'];
            $this->assertStringStartsNotWith(
                CredentialCipher::VERSION_PREFIX,
                $credentials['api_key']['value'],
                'the api_key must be stored as unencrypted plaintext for the pre-encryption scenario'
            );

            $sent = wp_mail('to@example.org', 'Legacy Plaintext Mailgun Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertMailgunRequestCarriedTheCredentials($sent, $captured, $apiKey, 'Legacy Plaintext Mailgun Send');
    }

    /**
     * @param array<string,mixed> $captured
     */
    private function assertMailgunRequestCarriedTheCredentials(bool $sent, array $captured, string $apiKey, string $subject): void
    {
        $this->assertTrue($sent, 'wp_mail() should report the Mailgun send as successful');
        $this->assertNotEmpty($captured, 'the Mailgun transport should have issued an outbound HTTP request');

        $this->assertSame('/v3/mg.example.com/messages', parse_url($captured['url'], \PHP_URL_PATH), 'the {domain} setting must reach the request path');

        $authHeader = $captured['headers']['Authorization'] ?? '';
        $this->assertStringStartsWith('Basic ', $authHeader, 'load() must sign the request with HTTP Basic auth');
        $decoded = base64_decode(substr($authHeader, \strlen('Basic ')), true);
        $this->assertSame(
            'api:' . $apiKey,
            $decoded,
            'load() must decrypt the stored credential back to plaintext for the Authorization: Basic header'
        );

        $this->assertStringContainsString('to%40example.org', (string) $captured['body'], 'the recipient must be in the urlencoded body');
        $this->assertStringContainsString(urlencode($subject), (string) $captured['body'], 'the subject must be in the urlencoded body');
        $this->assertEmpty($this->mailpitMessages(), 'the API send must not deliver through SMTP/mailpit');
    }

    /**
     * @return array<string,mixed>
     */
    private function v2(string $apiKey): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_mailgun',
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'           => 'conn_mailgun',
                    'provider'     => 'mailgun',
                    'kind'         => 'api',
                    'name'         => 'conn_mailgun',
                    'enabled'      => true,
                    'fromEmail'    => 'from@example.org',
                    'fromName'     => 'From',
                    'replyToEmail' => '',
                    'settings'     => ['domain' => 'mg.example.com', 'region' => 'us'],
                    'credentials'  => [
                        'api_key' => ['source' => 'database', 'value' => $apiKey],
                    ],
                ],
            ],
            'features' => [],
        ];
    }

    /**
     * @param array<string,mixed> $captured
     */
    private function interceptMailgunRequest(array &$captured): callable
    {
        return static function ($preempt, $args, $url) use (&$captured) {
            if (strpos($url, 'api.mailgun.net') === false) {
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
}
