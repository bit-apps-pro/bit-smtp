<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook\Signatures;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Webhook\Signatures\MailgunWebhookSignatureVerifier;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class MailgunWebhookSignatureVerifierTest extends BaseUnitTestCase
{
    public function testVerifiesDocumentedTimestampTokenHmac(): void
    {
        $key       = 'signing-key';
        $timestamp = '1720000000';
        $token     = 'random-token';
        $request   = WebhookRequest::fromRaw(json_encode([
            'signature' => [
                'timestamp' => $timestamp,
                'token'     => $token,
                'signature' => hash_hmac('sha256', $timestamp . $token, $key),
            ],
        ]));

        $verifier = new MailgunWebhookSignatureVerifier();
        $this->assertTrue($verifier->verify($request, $this->connection($key)));
        $this->assertFalse($verifier->verify(
            WebhookRequest::fromRaw('{"signature":{"timestamp":"1","token":"x","signature":"invalid"}}'),
            $this->connection($key)
        ));
    }

    private function connection(string $key): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1', 'provider' => 'mailgun', 'kind' => 'api',
            'credentials' => ['webhook_signing_key' => ['source' => 'database', 'value' => $key]],
        ]);
    }
}
