<?php

namespace BitApps\SMTP\Mail\Webhook\Signatures;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Webhook\Signatures\Contracts\WebhookSignatureVerifierInterface;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

final class MailgunWebhookSignatureVerifier implements WebhookSignatureVerifierInterface
{
    public function verify(WebhookRequest $request, Connection $connection): bool
    {
        $key       = (string) ($connection->getCredentials()['webhook_signing_key']['value'] ?? '');
        $payload   = $request->decoded();
        $signature = \is_array($payload['signature'] ?? null) ? $payload['signature'] : [];
        if ($key === '') {
            return true;
        }
        if (empty($signature['timestamp']) || empty($signature['token']) || empty($signature['signature'])) {
            return false;
        }

        return hash_equals((string) $signature['signature'], hash_hmac('sha256', $signature['timestamp'] . $signature['token'], $key));
    }
}
