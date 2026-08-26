<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Aws\Sns;

/**
 * The SSRF allow-list for AWS-controlled SNS URLs (the SigningCertURL we fetch and the SubscribeURL we
 * confirm). Both are attacker-controllable fields in a spoofed POST, so each is validated against an
 * AWS SNS regional host BEFORE any outbound request.
 */
final class SnsEndpoint
{
    /**
     * SNS regional host, including the China partition. Deliberately strict: never substring-match
     * `amazonaws.com`, which `evil-amazonaws.com` or `amazonaws.com.attacker.test` would satisfy.
     */
    private const HOST_PATTERN = '/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?\z/';

    /**
     * True only for an HTTPS URL whose host is an AWS SNS regional endpoint.
     */
    public static function isAwsSnsUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!\is_array($parts)) {
            return false;
        }

        return strtolower((string) ($parts['scheme'] ?? ''))                               === 'https'
            && preg_match(self::HOST_PATTERN, strtolower((string) ($parts['host'] ?? ''))) === 1;
    }
}
