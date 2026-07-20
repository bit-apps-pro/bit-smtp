<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Credentials\CredentialCipher;
use BitApps\SMTP\Plugin;

/**
 * Drives wp_mail() through the SparkPost API transport (mocking the outbound HTTP call) and proves
 * the api_key credential reaches the request as the raw (no "Bearer") Authorization header, whether
 * the api_key was stored encrypted-at-rest or as legacy (pre-encryption) plaintext.
 *
 * @internal
 *
 * @coversNothing
 */
final class SparkPostSendTest extends IntegrationTestCase
{
    public function testSendsSuccessfullyWhenTheCredentialsAreEncryptedAtRest(): void
    {
        $apiKey   = 'encrypted-at-rest-api-key';
        $captured = [];
        $filter   = $this->interceptSparkPostRequest($captured);

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

            $sent = wp_mail('to@example.org', 'Encrypted SparkPost Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertSparkPostRequestCarriedTheCredentials($sent, $captured, $apiKey, 'Encrypted SparkPost Send');
    }

    public function testSendsSuccessfullyWhenTheCredentialsAreStoredAsLegacyPlaintext(): void
    {
        $apiKey   = 'legacy-plaintext-api-key';
        $captured = [];
        $filter   = $this->interceptSparkPostRequest($captured);

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

            $sent = wp_mail('to@example.org', 'Legacy Plaintext SparkPost Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertSparkPostRequestCarriedTheCredentials($sent, $captured, $apiKey, 'Legacy Plaintext SparkPost Send');
    }

    /**
     * @param array<string,mixed> $captured
     */
    private function assertSparkPostRequestCarriedTheCredentials(bool $sent, array $captured, string $apiKey, string $subject): void
    {
        $this->assertTrue($sent, 'wp_mail() should report the SparkPost send as successful');
        $this->assertNotEmpty($captured, 'the SparkPost transport should have issued an outbound HTTP request');

        $this->assertSame('/api/v1/transmissions', parse_url($captured['url'], \PHP_URL_PATH), 'the request must hit the transmissions endpoint');

        $authHeader = $captured['headers']['Authorization'] ?? '';
        $this->assertSame($apiKey, $authHeader, 'load() must decrypt the stored credential back to the raw Authorization header with no Bearer prefix');

        $body = json_decode((string) $captured['body'], true);
        $this->assertSame('to@example.org', $body['recipients'][0]['address']['email'], 'the recipient must be in the JSON body');
        $this->assertSame($subject, $body['content']['subject'], 'the subject must be in the JSON body');
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
            'default_connection_id'   => 'conn_sparkpost',
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'           => 'conn_sparkpost',
                    'provider'     => 'sparkpost',
                    'kind'         => 'api',
                    'name'         => 'conn_sparkpost',
                    'enabled'      => true,
                    'fromEmail'    => 'from@example.org',
                    'fromName'     => 'From',
                    'replyToEmail' => '',
                    'settings'     => ['region' => 'us'],
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
    private function interceptSparkPostRequest(array &$captured): callable
    {
        return static function ($preempt, $args, $url) use (&$captured) {
            if (strpos($url, 'api.sparkpost.com') === false) {
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
