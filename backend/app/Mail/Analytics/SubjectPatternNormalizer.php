<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Analytics;

final class SubjectPatternNormalizer
{
    private const MAX_LENGTH = 160;

    public function normalize(string $subject): string
    {
        $pattern = preg_replace(
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i',
            '<uuid>',
            $subject
        );
        $pattern = preg_replace('/\bhttps?:\/\/[^\s<>]+/i', '<url>', (string) $pattern);
        $pattern = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '<email>', (string) $pattern);
        $pattern = preg_replace('/\b\d{5,}\b/', '<number>', (string) $pattern);
        $pattern = trim((string) preg_replace('/\s+/', ' ', (string) $pattern));

        if ($pattern === '') {
            return '(no subject)';
        }

        return substr($pattern, 0, self::MAX_LENGTH);
    }
}
