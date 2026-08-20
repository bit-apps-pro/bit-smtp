<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Credentials\CredentialCipher;
use BitApps\SMTP\Plugin;

/**
 * Exercises WpMailBridge with the registered Cloudflare API provider. HTTP is intercepted at
 * WordPress's external-client boundary, leaving provider resolution, secret decryption, payload
 * construction, result handling, and fallback routing live.
 *
 * @internal
 *
 * @coversNothing
 */
final class CloudflareSendTest extends IntegrationTestCase
{
    private const ACCOUNT_ID = '0123456789abcdef0123456789abcdef';

    public function testSendsThroughTheMockedCloudflareEndpoint(): void
    {
        $token    = 'cloudflare-send-token';
        $captured = [];
        $filter   = $this->interceptCloudflareRequest($captured, 200);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            Plugin::instance()->mailConfigService()->saveSettings($this->settings($token));

            $raw = Config::getOption('options');
            $this->assertStringStartsWith(
                CredentialCipher::VERSION_PREFIX,
                $raw['connections'][0]['credentials']['api_token']['value'],
                'The Cloudflare token must be encrypted before it is persisted.'
            );

            $sent = wp_mail('to@example.org', 'Cloudflare send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertTrue($sent, 'A mocked 200 Cloudflare response must be a successful wp_mail send.');
        $this->assertSame(
            'https://api.cloudflare.com/client/v4/accounts/' . self::ACCOUNT_ID . '/email/sending/send',
            $captured['url'] ?? null
        );
        $this->assertStringContainsString(
            'Bearer ' . $token,
            (string) wp_json_encode($captured['headers'] ?? []),
            'The live request must use the token decrypted from the stored connection.'
        );
        $this->assertStringContainsString('to@example.org', (string) ($captured['body'] ?? ''));
        $this->assertStringContainsString('Cloudflare send', (string) ($captured['body'] ?? ''));
        $this->assertEmpty($this->mailpitMessages(), 'Cloudflare API sending must not fall through to SMTP/mailpit.');
    }

    public function testFallsBackAfterCloudflareReturnsAnError(): void
    {
        $captured = [];
        $filter   = $this->interceptCloudflareRequest($captured, 500);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            $this->useRealPhpMailer();
            $settings                              = $this->settings('cloudflare-failing-token');
            $settings['fallback_connection_ids']   = ['conn_smtp_fallback'];
            $settings['connections'][]             = $this->smtpFallbackConnection();
            Plugin::instance()->mailConfigService()->saveSettings($settings);

            $sent = wp_mail('to@example.org', 'Cloudflare fallback', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertTrue($sent, 'WpMailBridge must continue to the configured fallback after a Cloudflare failure.');
        $this->assertNotEmpty($captured, 'The Cloudflare primary must have been attempted before fallback.');
        $this->assertNotEmpty($this->mailpitMessages(), 'The SMTP fallback must deliver after Cloudflare returns an error.');
        $this->assertFalse(Plugin::instance()->smtpProvider()->isFailed());
    }

    public function testFallsBackAfterCloudflareReturnsA200ErrorEnvelope(): void
    {
        $this->assertFallsBackAfterCloudflareResponse('{
            "success": false,
            "errors": [{"code": 1000, "message": "Invalid sender address"}],
            "messages": [],
            "result": null
        }');
    }

    public function testFallsBackAfterCloudflareReturnsAMalformed200Body(): void
    {
        $this->assertFallsBackAfterCloudflareResponse('<html>Cloudflare error page</html>');
    }

    /**
     * @return array<string,mixed>
     */
    private function settings(string $token): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_cloudflare',
            'fallback_connection_ids' => [],
            'connections'             => [[
                'id'           => 'conn_cloudflare',
                'provider'     => 'cloudflare',
                'kind'         => 'api',
                'name'         => 'Cloudflare',
                'enabled'      => true,
                'fromEmail'    => 'from@example.org',
                'fromName'     => 'From',
                'replyToEmail' => '',
                'settings'     => ['account_id' => self::ACCOUNT_ID],
                'credentials'  => ['api_token' => ['source' => 'database', 'value' => $token]],
            ]],
            'features' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function smtpFallbackConnection(): array
    {
        return [
            'id'           => 'conn_smtp_fallback',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'SMTP fallback',
            'enabled'      => true,
            'fromEmail'    => 'from@example.org',
            'fromName'     => 'From',
            'replyToEmail' => '',
            'settings'     => ['host' => self::SMTP_HOST, 'port' => self::SMTP_PORT, 'encryption' => 'none', 'auth' => false],
            'credentials'  => [],
        ];
    }

    /**
     * @param array<string,mixed> $captured
     */
    private function interceptCloudflareRequest(array &$captured, int $status, ?string $body = null): callable
    {
        return static function ($preempt, $args, $url) use (&$captured, $status, $body) {
            if (strpos($url, 'api.cloudflare.com') === false) {
                return $preempt;
            }

            $captured['url']     = $url;
            $captured['headers'] = $args['headers'] ?? [];
            $captured['body']    = $args['body']    ?? '';

            return [
                'headers'  => [],
                'body'     => $body ?? ($status === 200
                    ? '{"success":true,"errors":[],"messages":[],"result":{"message_id":"mocked-id"}}'
                    : '{"success":false,"errors":[{"message":"Cloudflare is unavailable"}],"messages":[],"result":null}'),
                'response' => ['code' => $status, 'message' => $status === 200 ? 'OK' : 'Internal Server Error'],
                'cookies'  => [],
                'filename' => null,
            ];
        };
    }

    private function assertFallsBackAfterCloudflareResponse(string $body): void
    {
        $captured = [];
        $filter   = $this->interceptCloudflareRequest($captured, 200, $body);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            $this->useRealPhpMailer();
            $settings                            = $this->settings('cloudflare-failing-token');
            $settings['fallback_connection_ids'] = ['conn_smtp_fallback'];
            $settings['connections'][]           = $this->smtpFallbackConnection();
            Plugin::instance()->mailConfigService()->saveSettings($settings);

            $sent = wp_mail('to@example.org', 'Cloudflare semantic fallback', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertTrue($sent, 'WpMailBridge must continue to the configured fallback after a non-accepted Cloudflare 200 response.');
        $this->assertNotEmpty($captured, 'The Cloudflare primary must have been attempted before fallback.');
        $this->assertNotEmpty($this->mailpitMessages(), 'The SMTP fallback must deliver after Cloudflare does not accept the message.');
        $this->assertFalse(Plugin::instance()->smtpProvider()->isFailed());
    }
}
