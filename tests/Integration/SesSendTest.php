<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Credentials\CredentialCipher;
use BitApps\SMTP\Plugin;

/**
 * Drives wp_mail() through the Amazon SES SigV4-signed API transport (mocking the outbound HTTP
 * call) and proves the secret_key credential reaches the SigV4 signature as plaintext whether it
 * was stored encrypted-at-rest or as legacy (pre-encryption) plaintext. Because HMAC-SHA256 is
 * one-way, the secret cannot be read back out of the signature directly; instead the test
 * independently recomputes the expected SigV4Signer output with the known secret_key and asserts
 * it reproduces the exact Authorization header the transport sent — which only happens if the
 * transport signed with that same plaintext secret.
 *
 * @internal
 *
 * @coversNothing
 */
final class SesSendTest extends IntegrationTestCase
{
    private const REGION = 'us-east-1';

    private const ACCESS_KEY = 'AKIAEXAMPLESESKEY';

    public function testSendsSuccessfullyWhenTheSecretKeyIsEncryptedAtRest(): void
    {
        $secretKey = 'encrypted-at-rest-secret';
        $captured  = [];
        $filter    = $this->interceptSesRequest($captured);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            Plugin::instance()->mailConfigService()->saveSettings($this->v2($secretKey));

            $raw = Config::getOption('options');
            $this->assertStringStartsWith(
                CredentialCipher::VERSION_PREFIX,
                $raw['connections'][0]['credentials']['secret_key']['value'],
                'the secret_key must be encrypted on disk'
            );

            $sent = wp_mail('to@example.org', 'Encrypted SES Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertSesRequestWasSignedWithTheSecretKey($sent, $captured, $secretKey, 'Encrypted SES Send');
    }

    public function testSendsSuccessfullyWhenTheSecretKeyIsStoredAsLegacyPlaintext(): void
    {
        $secretKey = 'legacy-plaintext-secret';
        $captured  = [];
        $filter    = $this->interceptSesRequest($captured);

        add_filter('pre_http_request', $filter, 10, 3);

        try {
            // Bypasses MailConfigService::store(), mimicking an option row written before encryption existed.
            $this->storeOptions($this->v2($secretKey));
            Plugin::instance()->mailConfigService()->reload();

            $raw = Config::getOption('options');
            $this->assertStringStartsNotWith(
                CredentialCipher::VERSION_PREFIX,
                $raw['connections'][0]['credentials']['secret_key']['value'],
                'the secret_key must be stored as unencrypted plaintext for the pre-encryption scenario'
            );

            $sent = wp_mail('to@example.org', 'Legacy Plaintext SES Send', 'Body');
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertSesRequestWasSignedWithTheSecretKey($sent, $captured, $secretKey, 'Legacy Plaintext SES Send');
    }

    /**
     * @param array<string,mixed> $captured
     */
    private function assertSesRequestWasSignedWithTheSecretKey(bool $sent, array $captured, string $secretKey, string $subject): void
    {
        $this->assertTrue($sent, 'wp_mail() should report the SES send as successful');
        $this->assertNotEmpty($captured, 'the SES transport should have issued an outbound HTTP request');
        $this->assertSame(
            'https://email.' . self::REGION . '.amazonaws.com/v2/email/outbound-emails',
            $captured['url']
        );

        $body           = (string) $captured['body'];
        $authorization  = $this->headerValue($captured['headers'], 'Authorization');
        $amzDate        = $this->headerValue($captured['headers'], 'X-Amz-Date');
        $this->assertNotNull($authorization, 'the request must carry a SigV4 Authorization header');
        $this->assertNotNull($amzDate, 'the request must carry the X-Amz-Date the signature was computed for');
        $this->assertStringStartsWith(
            'AWS4-HMAC-SHA256 Credential=' . self::ACCESS_KEY . '/',
            $authorization,
            'the SigV4 Authorization header must carry the connection access_key'
        );

        $expected = SigV4Signer::sign(
            'POST',
            $captured['url'],
            self::REGION,
            'ses',
            self::ACCESS_KEY,
            $secretKey,
            [
                'Host'                 => 'email.' . self::REGION . '.amazonaws.com',
                'Content-Type'         => 'application/json',
                'X-Amz-Content-Sha256' => hash('sha256', $body),
            ],
            $body,
            $amzDate
        );
        $this->assertSame(
            $expected['Authorization'],
            $authorization,
            'recomputing the SigV4 signature with the connection secret_key must reproduce the exact captured Authorization header'
        );

        $mime = $this->mimeFromRawBody($body);
        $this->assertStringContainsString('to@example.org', $mime, 'the recipient must be in the MIME message');
        $this->assertStringContainsString('Subject: ' . $subject, $mime, 'the subject must be in the MIME message');

        $this->assertEmpty($this->mailpitMessages(), 'the API send must not deliver through SMTP/mailpit');
    }

    /**
     * @return array<string,mixed>
     */
    private function v2(string $secretKey): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_ses',
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'           => 'conn_ses',
                    'provider'     => 'amazon_ses',
                    'kind'         => 'api',
                    'name'         => 'conn_ses',
                    'enabled'      => true,
                    'fromEmail'    => 'from@example.org',
                    'fromName'     => 'From',
                    'replyToEmail' => '',
                    'settings'     => ['access_key' => self::ACCESS_KEY, 'region' => self::REGION],
                    'credentials'  => ['secret_key' => ['source' => 'database', 'value' => $secretKey]],
                ],
            ],
            'features' => [],
        ];
    }

    /**
     * @param array<string,mixed> $captured
     */
    private function interceptSesRequest(array &$captured): callable
    {
        return static function ($preempt, $args, $url) use (&$captured) {
            if (strpos($url, 'email.' . self::REGION . '.amazonaws.com') === false) {
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
     * Decode SES's {"Content":{"Raw":{"Data":"<base64 MIME>"}}} JSON body back into the raw MIME.
     */
    private function mimeFromRawBody(string $body): string
    {
        $decoded = json_decode($body, true);
        $data    = \is_array($decoded) ? (string) ($decoded['Content']['Raw']['Data'] ?? '') : '';

        return (string) base64_decode($data, true);
    }

    /**
     * @param array<string,mixed> $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return (string) $value;
            }
        }

        return null;
    }
}
