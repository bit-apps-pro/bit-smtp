<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Credentials\CredentialCipher;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Plugin;

/**
 * Drives wp_mail() through the SendGrid API transport (mocking the outbound HTTP call) and proves
 * the api_key credential reaches the request as plaintext whether it was stored encrypted-at-rest
 * or as legacy (pre-encryption) plaintext.
 *
 * @internal
 *
 * @coversNothing
 */
final class SendGridSendTest extends IntegrationTestCase
{
    public function testSendsSuccessfullyWhenTheApiKeyIsEncryptedAtRest(): void
    {
        $apiKey   = 'SG.encrypted-at-rest-key';
        $captured = [];
        $filter   = $this->interceptSendGridRequest($captured);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            Plugin::instance()->mailConfigService()->saveSettings($this->v2($apiKey));

            $raw = Config::getOption('options');
            $this->assertStringStartsWith(
                CredentialCipher::VERSION_PREFIX,
                $raw['connections'][0]['credentials']['api_key']['value'],
                'the api_key must be encrypted on disk'
            );

            $sent = wp_mail('to@example.org', 'Encrypted SendGrid Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertSendGridRequestCarriedTheApiKey($sent, $captured, $apiKey, 'Encrypted SendGrid Send');
    }

    public function testSendsSuccessfullyWhenTheApiKeyIsStoredAsLegacyPlaintext(): void
    {
        $apiKey   = 'SG.legacy-plaintext-key';
        $captured = [];
        $filter   = $this->interceptSendGridRequest($captured);

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

            $sent = wp_mail('to@example.org', 'Legacy Plaintext SendGrid Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertSendGridRequestCarriedTheApiKey($sent, $captured, $apiKey, 'Legacy Plaintext SendGrid Send');
    }

    public function testWebhookEnabledSendStoresSendGridCorrelationKeys(): void
    {
        $captured = [];
        $filter   = $this->interceptSendGridRequest($captured);
        $subject  = 'Tracked SendGrid Send';

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            Plugin::instance()->mailConfigService()->saveSettings($this->v2('SG.tracked-key', true));
            $sent = wp_mail('to@example.org', $subject, 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertTrue($sent);
        $body       = json_decode((string) $captured['body'], true);
        $trackingId = $body['personalizations'][0]['custom_args']['bit_tracking_id'] ?? null;
        $this->assertNotEmpty($trackingId);

        $log = Log::where('subject', $subject)->first();
        $this->assertInstanceOf(Log::class, $log);
        $this->assertSame($trackingId, $log->tracking_id);
        $this->assertSame('sg-response-id', $log->message_id);
        // Send-time hand-off floors delivery_status at accepted (#35); a later webhook still upgrades it.
        $this->assertSame('accepted', $log->delivery_status);
    }

    /**
     * @param array<string,mixed> $captured
     */
    private function assertSendGridRequestCarriedTheApiKey(bool $sent, array $captured, string $apiKey, string $subject): void
    {
        $this->assertTrue($sent, 'wp_mail() should report the SendGrid send as successful');
        $this->assertNotEmpty($captured, 'the SendGrid transport should have issued an outbound HTTP request');
        $this->assertStringContainsString(
            'Bearer ' . $apiKey,
            (string) wp_json_encode($captured['headers']),
            'load() must decrypt the stored api_key back to plaintext for the Bearer header'
        );
        $this->assertStringContainsString('to@example.org', (string) $captured['body'], 'the recipient must be in the JSON body');
        $this->assertStringContainsString($subject, (string) $captured['body'], 'the subject must be in the JSON body');
        $this->assertEmpty($this->mailpitMessages(), 'the API send must not deliver through SMTP/mailpit');
    }

    /**
     * @return array<string,mixed>
     */
    private function v2(string $apiKey, bool $webhookEnabled = false): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_sendgrid',
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'           => 'conn_sendgrid',
                    'provider'     => 'sendgrid',
                    'kind'         => 'api',
                    'name'         => 'conn_sendgrid',
                    'enabled'      => true,
                    'fromEmail'    => 'from@example.org',
                    'fromName'     => 'From',
                    'replyToEmail' => '',
                    'settings'     => [
                        'webhook_enabled' => $webhookEnabled,
                        'webhook_secret'  => $webhookEnabled ? 'known-webhook-secret' : '',
                    ],
                    'credentials'  => ['api_key' => ['source' => 'database', 'value' => $apiKey]],
                ],
            ],
            'features' => [],
        ];
    }

    /**
     * @param array<string,mixed> $captured
     */
    private function interceptSendGridRequest(array &$captured): callable
    {
        return static function ($preempt, $args, $url) use (&$captured) {
            if (strpos($url, 'api.sendgrid.com') === false) {
                return $preempt;
            }

            $captured['url']     = $url;
            $captured['headers'] = $args['headers'] ?? [];
            $captured['body']    = $args['body']    ?? '';

            return [
                'headers'  => ['x-message-id' => 'sg-response-id'],
                'body'     => '',
                'response' => ['code' => 202, 'message' => 'Accepted'],
                'cookies'  => [],
                'filename' => null,
            ];
        };
    }
}
