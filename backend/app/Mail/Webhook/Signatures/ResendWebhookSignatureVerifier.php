<?php

namespace BitApps\SMTP\Mail\Webhook\Signatures;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Webhook\Signatures\Contracts\WebhookSignatureVerifierInterface;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

final class ResendWebhookSignatureVerifier implements WebhookSignatureVerifierInterface
{
    public function verify(WebhookRequest $request, Connection $connection): bool
    {
        $secret     = (string) ($connection->getCredentials()['webhook_signing_secret']['value'] ?? '');
        $id         = $request->header('svix-id');
        $timestamp  = $request->header('svix-timestamp');
        $signatures = $request->header('svix-signature');
        if ($secret === '') {
            return true;
        }
        if ($id === null || $timestamp === null || $signatures === null) {
            return false;
        }
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $secret = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $key    = base64_decode($secret, true);
        if ($key === false) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $request->rawBody(), $key, true));
        foreach (explode(' ', $signatures) as $signature) {
            if (str_starts_with($signature, 'v1,') && hash_equals(substr($signature, 3), $expected)) {
                return true;
            }
        }

        return false;
    }
}
