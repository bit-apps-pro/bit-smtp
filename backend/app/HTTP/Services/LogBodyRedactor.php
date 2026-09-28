<?php

namespace BitApps\SMTP\HTTP\Services;

\defined('ABSPATH') || exit();

/**
 * Apply log_store_body preference: keep, redact, or drop message body (never headers/recipients).
 */
final class LogBodyRedactor
{
    public const MODE_FULL = 'full';

    public const MODE_REDACTED = 'redacted';

    public const MODE_METADATA = 'metadata';

    /**
     * Placeholder kept in place of a redacted body so the log detail view still renders a body area.
     */
    public const REDACTED_PLACEHOLDER = '[redacted]';

    /**
     * Detail keys for message body (governed by storage preference; subject/recipients/headers exempt).
     *
     * @var array<int,string>
     */
    private const BODY_KEYS = ['message', 'html'];

    /**
     * Check if body is retained (not redacted/dropped) for safe resend.
     */
    public static function isBodyRetained(string $body): bool
    {
        $trimmed = trim($body);

        return $trimmed !== '' && $trimmed !== self::REDACTED_PLACEHOLDER;
    }

    /**
     * Apply mode to body keys; unrecognized mode defaults to 'full' to never silently drop body.
     *
     * @param array<string,mixed> $details
     *
     * @return array<string,mixed>
     */
    public static function apply(array $details, string $mode): array
    {
        if ($mode !== self::MODE_REDACTED && $mode !== self::MODE_METADATA) {
            return $details;
        }

        foreach (self::BODY_KEYS as $key) {
            if (!\array_key_exists($key, $details)) {
                continue;
            }

            if ($mode === self::MODE_METADATA) {
                unset($details[$key]);

                continue;
            }

            $details[$key] = self::REDACTED_PLACEHOLDER;
        }

        return $details;
    }
}
