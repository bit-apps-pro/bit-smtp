<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Credentials\CredentialCipher;
use BitApps\SMTP\Plugin;

/**
 * Drives wp_mail() through the Mailjet API transport (mocking the outbound HTTP call) and proves
 * both the api_key and secret_key credentials reach the request as the Basic auth header
 * whether they were stored encrypted-at-rest or as legacy (pre-encryption) plaintext.
 *
 * @internal
 *
 * @coversNothing
 */
final class MailjetSendTest extends IntegrationTestCase
{
    public function testSendsSuccessfullyWhenTheCredentialsAreEncryptedAtRest(): void
    {
        $apiKey    = 'encrypted-at-rest-api-key';
        $secretKey = 'encrypted-at-rest-secret-key';
        $captured  = [];
        $filter    = $this->interceptMailjetRequest($captured);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            Plugin::instance()->mailConfigService()->saveSettings($this->v2($apiKey, $secretKey));

            $raw         = Config::getOption('options');
            $credentials = $raw['connections'][0]['credentials'];
            $this->assertStringStartsWith(
                CredentialCipher::VERSION_PREFIX,
                $credentials['api_key']['value'],
                'the api_key must be encrypted on disk'
            );
            $this->assertStringStartsWith(
                CredentialCipher::VERSION_PREFIX,
                $credentials['secret_key']['value'],
                'the secret_key must be encrypted on disk'
            );

            $sent = wp_mail('to@example.org', 'Encrypted Mailjet Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertMailjetRequestCarriedTheCredentials($sent, $captured, $apiKey, $secretKey, 'Encrypted Mailjet Send');
    }

    public function testSendsSuccessfullyWhenTheCredentialsAreStoredAsLegacyPlaintext(): void
    {
        $apiKey    = 'legacy-plaintext-api-key';
        $secretKey = 'legacy-plaintext-secret-key';
        $captured  = [];
        $filter    = $this->interceptMailjetRequest($captured);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            // Bypasses MailConfigService::store(), mimicking an option row written before encryption existed.
            $this->storeOptions($this->v2($apiKey, $secretKey));
            Plugin::instance()->mailConfigService()->reload();

            $raw         = Config::getOption('options');
            $credentials = $raw['connections'][0]['credentials'];
            $this->assertStringStartsNotWith(
                CredentialCipher::VERSION_PREFIX,
                $credentials['api_key']['value'],
                'the api_key must be stored as unencrypted plaintext for the pre-encryption scenario'
            );
            $this->assertStringStartsNotWith(
                CredentialCipher::VERSION_PREFIX,
                $credentials['secret_key']['value'],
                'the secret_key must be stored as unencrypted plaintext for the pre-encryption scenario'
            );

            $sent = wp_mail('to@example.org', 'Legacy Plaintext Mailjet Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertMailjetRequestCarriedTheCredentials($sent, $captured, $apiKey, $secretKey, 'Legacy Plaintext Mailjet Send');
    }

    /**
     * @param array<string,mixed> $captured
     */
    private function assertMailjetRequestCarriedTheCredentials(bool $sent, array $captured, string $apiKey, string $secretKey, string $subject): void
    {
        $this->assertTrue($sent, 'wp_mail() should report the Mailjet send as successful');
        $this->assertNotEmpty($captured, 'the Mailjet transport should have issued an outbound HTTP request');

        $authHeader = $captured['headers']['Authorization'] ?? '';
        $this->assertStringStartsWith('Basic ', $authHeader, 'load() must sign the request with HTTP Basic auth');
        $decoded = base64_decode(substr($authHeader, \strlen('Basic ')), true);
        $this->assertSame(
            $apiKey . ':' . $secretKey,
            $decoded,
            'load() must decrypt both stored credentials back to plaintext for the Authorization: Basic header'
        );

        $this->assertStringContainsString('to@example.org', (string) $captured['body'], 'the recipient must be in the JSON body');
        $this->assertStringContainsString($subject, (string) $captured['body'], 'the subject must be in the JSON body');

        $body    = json_decode((string) $captured['body'], true);
        $message = $body['Messages'][0] ?? [];
        $this->assertNotEmpty(
            $message['CustomID'] ?? '',
            'webhook tracking must use Mailjet Send API v3.1 CustomID'
        );
        $this->assertArrayNotHasKey(
            'CustomCampaign',
            $message,
            'per-message tracking must not be modeled as a Mailjet campaign'
        );
        $this->assertArrayNotHasKey(
            'X-Mailjet-Campaign',
            $message['Headers'] ?? [],
            'Mailjet rejects X-Mailjet-Campaign in the generic Headers collection'
        );
        $this->assertEmpty($this->mailpitMessages(), 'the API send must not deliver through SMTP/mailpit');
    }

    /**
     * @return array<string,mixed>
     */
    private function v2(string $apiKey, string $secretKey): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_mailjet',
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'           => 'conn_mailjet',
                    'provider'     => 'mailjet',
                    'kind'         => 'api',
                    'name'         => 'conn_mailjet',
                    'enabled'      => true,
                    'fromEmail'    => 'from@example.org',
                    'fromName'     => 'From',
                    'replyToEmail' => '',
                    'settings'     => [],
                    'credentials'  => [
                        'api_key'    => ['source' => 'database', 'value' => $apiKey],
                        'secret_key' => ['source' => 'database', 'value' => $secretKey],
                    ],
                ],
            ],
            'features' => [],
        ];
    }

    /**
     * @param array<string,mixed> $captured
     */
    private function interceptMailjetRequest(array &$captured): callable
    {
        return static function ($preempt, $args, $url) use (&$captured) {
            if (strpos($url, 'api.mailjet.com') === false) {
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
