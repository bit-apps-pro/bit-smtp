<?php

namespace BitApps\SMTP\Mail\Webhook\Signatures;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Webhook\Signatures\Contracts\WebhookSignatureVerifierInterface;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

final class SendGridWebhookSignatureVerifier implements WebhookSignatureVerifierInterface
{
    public function verify(WebhookRequest $request, Connection $connection): bool
    {
        $settings = $connection->getSettings();
        if (empty($settings['webhook_signature_enabled'])) {
            return true;
        }

        $signature = $request->header('X-Twilio-Email-Event-Webhook-Signature');
        $timestamp = $request->header('X-Twilio-Email-Event-Webhook-Timestamp');
        $publicKey = (string) ($settings['webhook_public_key'] ?? '');
        $decoded   = $signature !== null ? base64_decode($signature, true) : false;

        if ($timestamp === null || $timestamp === '' || $decoded === false || $publicKey === '') {
            return false;
        }

        $key = openssl_pkey_get_public($publicKey);
        if ($key === false) {
            return false;
        }

        return openssl_verify($timestamp . $request->rawBody(), $decoded, $key, OPENSSL_ALGO_SHA256) === 1;
    }
}
