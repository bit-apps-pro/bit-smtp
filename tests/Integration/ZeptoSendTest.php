<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Credentials\CredentialCipher;
use BitApps\SMTP\Plugin;

/**
 * Drives wp_mail() through the ZeptoMail API transport (mocking the outbound HTTP call) and
 * proves the api_key credential reaches the request as the "Zoho-enczapikey" Authorization
 * header whether it was stored encrypted-at-rest or as legacy (pre-encryption) plaintext.
 *
 * @internal
 *
 * @coversNothing
 */
final class ZeptoSendTest extends IntegrationTestCase
{
    public function testSendsSuccessfullyWhenTheCredentialsAreEncryptedAtRest(): void
    {
        $apiKey   = 'encrypted-at-rest-api-key';
        $captured = [];
        $filter   = $this->interceptZeptoRequest($captured);

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

            $sent = wp_mail('to@example.org', 'Encrypted ZeptoMail Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertZeptoRequestCarriedTheCredentials($sent, $captured, $apiKey, 'Encrypted ZeptoMail Send');
    }

    public function testSendsSuccessfullyWhenTheCredentialsAreStoredAsLegacyPlaintext(): void
    {
        $apiKey   = 'legacy-plaintext-api-key';
        $captured = [];
        $filter   = $this->interceptZeptoRequest($captured);

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

            $sent = wp_mail('to@example.org', 'Legacy Plaintext ZeptoMail Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertZeptoRequestCarriedTheCredentials($sent, $captured, $apiKey, 'Legacy Plaintext ZeptoMail Send');
    }

    /**
     * @param array<string,mixed> $captured
     */
    private function assertZeptoRequestCarriedTheCredentials(bool $sent, array $captured, string $apiKey, string $subject): void
    {
        $this->assertTrue($sent, 'wp_mail() should report the ZeptoMail send as successful');
        $this->assertNotEmpty($captured, 'the ZeptoMail transport should have issued an outbound HTTP request');

        $this->assertSame(
            'Zoho-enczapikey ' . $apiKey,
            $captured['headers']['Authorization'] ?? '',
            'load() must decrypt the stored credential back to plaintext for the Authorization header'
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
            'default_connection_id'   => 'conn_zeptomail',
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'           => 'conn_zeptomail',
                    'provider'     => 'zeptomail',
                    'kind'         => 'api',
                    'name'         => 'conn_zeptomail',
                    'enabled'      => true,
                    'fromEmail'    => 'from@example.org',
                    'fromName'     => 'From',
                    'replyToEmail' => '',
                    'settings'     => ['data_center' => 'us'],
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
    private function interceptZeptoRequest(array &$captured): callable
    {
        return static function ($preempt, $args, $url) use (&$captured) {
            if (strpos($url, 'api.zeptomail.com') === false) {
                return $preempt;
            }

            $captured['url']     = $url;
            $captured['headers'] = $args['headers'] ?? [];
            $captured['body']    = $args['body']    ?? '';

            return [
                'headers'  => [],
                'body'     => '',
                'response' => ['code' => 201, 'message' => 'Created'],
                'cookies'  => [],
                'filename' => null,
            ];
        };
    }
}
