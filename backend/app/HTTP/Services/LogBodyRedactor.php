<?php

namespace BitApps\SMTP\HTTP\Services;

\defined('ABSPATH') || exit();

/**
 * Applies the `log_store_body` privacy preference to a log's details blob before it is persisted:
 * keeps, redacts, or drops the message body without ever touching subject, recipients, or headers.
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
     * Detail keys holding the (potentially sensitive) message body; only these are governed by the
     * body-storage preference — the subject/recipients/headers are never redacted here. `message` is
     * the live body key; `html` is covered defensively for any future separate HTML-body detail.
     *
     * @var array<int,string>
     */
    private const BODY_KEYS = ['message', 'html'];

    /**
     * Whether $body is real retained content rather than an empty/dropped or redacted body, so
     * callers that re-send from a stored log (resend) never mail a placeholder or blank message.
     */
    public static function isBodyRetained(string $body): bool
    {
        $trimmed = trim($body);

        return $trimmed !== '' && $trimmed !== self::REDACTED_PLACEHOLDER;
    }

    /**
     * Return a copy of $details with the message body kept ('full'), replaced by a placeholder
     * ('redacted'), or removed entirely ('metadata') per $mode. Only body keys are affected; an
     * unrecognized mode is treated as 'full', so an unexpected value never silently drops the body.
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
