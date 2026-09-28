<?php

namespace BitApps\SMTP\Mail\Dispatch;

/**
 * Render email/name pair into display string for From logs and message headers.
 */
class SenderFormatter
{
    /**
     * Format as "Name <email>", bare email, or empty string; pure string formatting.
     */
    public static function format(?string $email, ?string $name): string
    {
        $email = trim((string) $email);
        $name  = trim((string) $name);

        if ($email === '') {
            return '';
        }

        return $name === '' ? $email : $name . ' <' . $email . '>';
    }

    /**
     * Strip control characters while preserving "Name <email>" brackets (unlike sanitize_text_field).
     */
    public static function sanitize(string $sender): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $sender));
    }
}
