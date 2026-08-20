<?php

namespace BitApps\SMTP\Mail\Dispatch;

/**
 * Renders an email/name pair into the single display string logs and message headers use for From.
 */
class SenderFormatter
{
    /**
     * "Name <email>" when both are present, a bare email when the name is empty, or '' when the
     * email itself is empty. Pure string formatting: no WordPress dependency.
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
     * Strips control characters (including CR/LF) from an assembled sender string while keeping its
     * literal "Name <email>" bracket — unlike sanitize_text_field(), which treats the angle brackets
     * as an HTML tag and drops the address. Pure: no WordPress dependency.
     */
    public static function sanitize(string $sender): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $sender));
    }
}
