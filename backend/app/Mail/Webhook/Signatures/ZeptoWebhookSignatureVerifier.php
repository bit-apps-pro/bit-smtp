<?php

namespace BitApps\SMTP\Mail\Webhook\Signatures;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Webhook\Signatures\Contracts\WebhookSignatureVerifierInterface;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

final class ZeptoWebhookSignatureVerifier implements WebhookSignatureVerifierInterface
{
    public function verify(WebhookRequest $request, Connection $connection): bool
    {
        $key = (string) ($connection->getCredentials()['webhook_auth_key']['value'] ?? '');
        if ($key === '') {
            return true;
        }
        $header = $request->header('producer-signature');
        if ($header === null) {
            return false;
        }
        parse_str(str_replace(';', '&', urldecode($header)), $parts);
        $timestamp = (int) ($parts['ts'] ?? 0);
        $signature = (string) ($parts['s'] ?? '');
        if ($timestamp <= 0 || $signature === '' || strtolower((string) ($parts['s-algorithm'] ?? '')) !== 'hmacsha256') {
            return false;
        }
        if (abs((int) (microtime(true) * 1000) - $timestamp) > 300000) {
            return false;
        }
        $raw = $request->rawBody();
        if (strpos($raw, '=') !== false) {
            [, $raw] = explode('=', $raw, 2);
            $raw     = urldecode($raw);
        }
        $expected = base64_encode(hash_hmac('sha256', $raw, $key, true));

        return hash_equals($expected, urldecode($signature));
    }
}
