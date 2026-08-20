<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook\Signatures;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Webhook\Signatures\ZeptoWebhookSignatureVerifier;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ZeptoWebhookSignatureVerifierTest extends BaseUnitTestCase
{
    public function testVerifiesDocumentedProducerSignatureOverFormDataValue(): void
    {
        $key       = 'webhook-auth-key';
        $json      = '{"event_name":["hardbounce"]}';
        $timestamp = (string) (int) (microtime(true) * 1000);
        $signature = base64_encode(hash_hmac('sha256', $json, $key, true));
        $header    = \sprintf(
            'ts=%s;s=%s;s-algorithm=HmacSHA256',
            $timestamp,
            rawurlencode($signature)
        );
        $connection = Connection::fromArray([
            'id'          => 'conn_1', 'provider' => 'zeptomail', 'kind' => 'api',
            'credentials' => ['webhook_auth_key' => ['source' => 'database', 'value' => $key]],
        ]);

        $verifier = new ZeptoWebhookSignatureVerifier();
        $this->assertTrue($verifier->verify(
            WebhookRequest::fromRaw('data=' . rawurlencode($json), ['producer-signature' => $header]),
            $connection
        ));
        $this->assertFalse($verifier->verify(
            WebhookRequest::fromRaw('data=' . rawurlencode('{"changed":true}'), ['producer-signature' => $header]),
            $connection
        ));
    }
}
