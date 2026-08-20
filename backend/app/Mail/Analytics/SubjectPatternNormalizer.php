<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Analytics;

final class SubjectPatternNormalizer
{
    private const MAX_LENGTH = 160;

    /**
     * Only fixed, generic email-category terms survive into an analytics pattern. Every other
     * free-text token is treated as customer/application content and redacted before persistence.
     * This deliberately favours privacy over a more specific (but potentially identifying) label.
     *
     * @var array<int,string>
     */
    private const SAFE_WORDS = [
        'account', 'action', 'alert', 'at', 'billing', 'blocked', 'bounce', 'bounced', 'bulk',
        'comment', 'customer', 'deferred', 'delivered', 'delivery', 'email', 'error', 'failed',
        'for', 'form', 'from', 'invoice', 'login', 'message', 'new', 'notification', 'number',
        'of', 'on', 'order', 'password', 'payment', 'plugin', 'receipt', 'reference', 'reset',
        'saved', 'security', 'shipment', 'shipping', 'spam', 'status', 'subscription', 'to',
        'update', 'url', 'user', 'uuid', 'verification', 'welcome', 'your',
    ];

    public function normalize(string $subject): string
    {
        $pattern = preg_replace(
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
            '<uuid>',
            $subject
        );
        $pattern = preg_replace('/\bhttps?:\/\/[^\s<>]+/i', '<url>', (string) $pattern);
        $pattern = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '<email>', (string) $pattern);
        $pattern = preg_replace('/\b\d{5,}\b/', '<number>', (string) $pattern);
        $pattern = preg_replace_callback(
            '/\b[\p{L}\p{N}][\p{L}\p{N}\p{M}_-]*\b/u',
            static function (array $match): string {
                return \in_array(strtolower($match[0]), self::SAFE_WORDS, true) ? $match[0] : '<text>';
            },
            (string) $pattern
        );
        $pattern = trim((string) preg_replace('/\s+/', ' ', (string) $pattern));

        if ($pattern === '') {
            return '(no subject)';
        }

        return substr($pattern, 0, self::MAX_LENGTH);
    }
}
