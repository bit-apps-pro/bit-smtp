<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook\Signatures;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Webhook\Signatures\ResendWebhookSignatureVerifier;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ResendWebhookSignatureVerifierTest extends BaseUnitTestCase
{
    public function testVerifiesDocumentedSvixSignatureAndRejectsStaleTimestamp(): void
    {
        $key       = 'resend-signing-key';
        $secret    = 'whsec_' . base64_encode($key);
        $id        = 'msg_123';
        $timestamp = (string) time();
        $body      = '{"type":"email.delivered"}';
        $signature = base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, $key, true));
        $verifier  = new ResendWebhookSignatureVerifier();

        $this->assertTrue($verifier->verify(
            WebhookRequest::fromRaw($body, [
                'svix-id'        => $id,
                'svix-timestamp' => $timestamp,
                'svix-signature' => 'v1,' . $signature,
            ]),
            $this->connection($secret)
        ));
        $this->assertFalse($verifier->verify(
            WebhookRequest::fromRaw($body, [
                'svix-id'        => $id,
                'svix-timestamp' => (string) (time() - 301),
                'svix-signature' => 'v1,' . $signature,
            ]),
            $this->connection($secret)
        ));
    }

    private function connection(string $secret): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1', 'provider' => 'resend', 'kind' => 'api',
            'credentials' => ['webhook_signing_secret' => ['source' => 'database', 'value' => $secret]],
        ]);
    }
}
