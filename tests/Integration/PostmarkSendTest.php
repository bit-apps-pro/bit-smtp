<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Credentials\CredentialCipher;
use BitApps\SMTP\Plugin;

/**
 * Drives wp_mail() through the Postmark API transport (mocking the outbound HTTP call) and proves
 * the api_key credential reaches the request as plaintext whether it was stored encrypted-at-rest
 * or as legacy (pre-encryption) plaintext.
 *
 * @internal
 *
 * @coversNothing
 */
final class PostmarkSendTest extends IntegrationTestCase
{
    public function testSendsSuccessfullyWhenTheApiKeyIsEncryptedAtRest(): void
    {
        $apiKey   = 'encrypted-at-rest-server-token';
        $captured = [];
        $filter   = $this->interceptPostmarkRequest($captured);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            Plugin::instance()->mailConfigService()->saveSettings($this->v2($apiKey));

            $raw = Config::getOption('options');
            $this->assertStringStartsWith(
                CredentialCipher::VERSION_PREFIX,
                $raw['connections'][0]['credentials']['api_key']['value'],
                'the api_key must be encrypted on disk'
            );

            $sent = wp_mail('to@example.org', 'Encrypted Postmark Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertPostmarkRequestCarriedTheApiKey($sent, $captured, $apiKey, 'Encrypted Postmark Send');
    }

    public function testSendsSuccessfullyWhenTheApiKeyIsStoredAsLegacyPlaintext(): void
    {
        $apiKey   = 'legacy-plaintext-server-token';
        $captured = [];
        $filter   = $this->interceptPostmarkRequest($captured);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            // Bypasses MailConfigService::store(), mimicking an option row written before encryption existed.
            $this->storeOptions($this->v2($apiKey));
            Plugin::instance()->mailConfigService()->reload();

            $raw = Config::getOption('options');
            $this->assertStringStartsNotWith(
                CredentialCipher::VERSION_PREFIX,
                $raw['connections'][0]['credentials']['api_key']['value'],
                'the api_key must be stored as unencrypted plaintext for the pre-encryption scenario'
            );

            $sent = wp_mail('to@example.org', 'Legacy Plaintext Postmark Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertPostmarkRequestCarriedTheApiKey($sent, $captured, $apiKey, 'Legacy Plaintext Postmark Send');
    }

    /**
     * @param array<string,mixed> $captured
     */
    private function assertPostmarkRequestCarriedTheApiKey(bool $sent, array $captured, string $apiKey, string $subject): void
    {
        $this->assertTrue($sent, 'wp_mail() should report the Postmark send as successful');
        $this->assertNotEmpty($captured, 'the Postmark transport should have issued an outbound HTTP request');
        $this->assertStringContainsString(
            $apiKey,
            (string) wp_json_encode($captured['headers']),
            'load() must decrypt the stored api_key back to plaintext for the X-Postmark-Server-Token header'
        );
        $this->assertStringContainsString('to@example.org', (string) $captured['body'], 'the recipient must be in the JSON body');
        $this->assertStringContainsString($subject, (string) $captured['body'], 'the subject must be in the JSON body');
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
            'default_connection_id'   => 'conn_postmark',
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'           => 'conn_postmark',
                    'provider'     => 'postmark',
                    'kind'         => 'api',
                    'name'         => 'conn_postmark',
                    'enabled'      => true,
                    'fromEmail'    => 'from@example.org',
                    'fromName'     => 'From',
                    'replyToEmail' => '',
                    'settings'     => [],
                    'credentials'  => ['api_key' => ['source' => 'database', 'value' => $apiKey]],
                ],
            ],
            'features' => [],
        ];
    }

    /**
     * @param array<string,mixed> $captured
     */
    private function interceptPostmarkRequest(array &$captured): callable
    {
        return static function ($preempt, $args, $url) use (&$captured) {
            if (strpos($url, 'api.postmarkapp.com') === false) {
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
