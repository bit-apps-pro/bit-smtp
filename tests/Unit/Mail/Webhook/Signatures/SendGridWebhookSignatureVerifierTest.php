<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook\Signatures;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Webhook\Signatures\SendGridWebhookSignatureVerifier;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class SendGridWebhookSignatureVerifierTest extends BaseUnitTestCase
{
    public function testVerifiesRawBodySignature(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $private);
        $details   = openssl_pkey_get_details($key);
        $body      = '[{"event":"processed"}]';
        $timestamp = '1720000000';
        openssl_sign($timestamp . $body, $signature, $key, OPENSSL_ALGO_SHA256);

        $request = WebhookRequest::fromRaw($body, [
            'X-Twilio-Email-Event-Webhook-Timestamp' => $timestamp,
            'X-Twilio-Email-Event-Webhook-Signature' => base64_encode($signature),
        ]);
        $connection = Connection::fromArray([
            'id'       => 'conn_1', 'provider' => 'sendgrid', 'kind' => 'api',
            'settings' => ['webhook_signature_enabled' => true, 'webhook_public_key' => $details['key']],
        ]);

        $this->assertTrue((new SendGridWebhookSignatureVerifier())->verify($request, $connection));
        $this->assertNotEmpty($private);
    }

    public function testRejectsInvalidSignature(): void
    {
        $connection = Connection::fromArray([
            'id'       => 'conn_1', 'provider' => 'sendgrid', 'kind' => 'api',
            'settings' => ['webhook_signature_enabled' => true, 'webhook_public_key' => 'invalid'],
        ]);

        $this->assertFalse((new SendGridWebhookSignatureVerifier())->verify(
            WebhookRequest::fromRaw('{}', ['X-Twilio-Email-Event-Webhook-Timestamp' => '1', 'X-Twilio-Email-Event-Webhook-Signature' => 'bad']),
            $connection
        ));
    }
}
