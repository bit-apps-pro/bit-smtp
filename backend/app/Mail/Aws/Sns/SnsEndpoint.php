<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Aws\Sns;

/**
 * SSRF allow-list for AWS SNS URLs; validates against regional hosts before outbound requests.
 */
final class SnsEndpoint
{
    /**
     * SNS regional host pattern including China partition; prevents substring-matching attacks.
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
