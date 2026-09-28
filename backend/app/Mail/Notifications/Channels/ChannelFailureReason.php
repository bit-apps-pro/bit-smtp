<?php

namespace BitApps\SMTP\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Notifications\ChannelDeliveryResult;
use WP_Error;

/**
 * Build admin-safe failure results; redact secrets, URLs, tokens, credentials from details.
 */
final class ChannelFailureReason
{
    private const MAX_TRANSPORT_DETAIL = 160;

    private const REDACTED = '[redacted]';

    /**
     * Secret-shaped patterns and their replacements, applied in order after URL-decoding.
     */
    private const PATTERNS = [
        '~\b[a-z][a-z0-9+.-]*://\S+~i'                                                  => '[URL]',
        '~\b(?:[a-z0-9-]+\.)+[a-z]{2,}(?::\d+)?/\S*~i'                                  => '[URL]',
        '~\d{6,20}\s*:\s*[A-Za-z0-9_-]{20,}~'                                           => '[token]',
        '~\b([a-z_-]*(?:token|secret|signature|password|key|sig))\s*[=:]\s*[^\s&,;]+~i' => '$1=[redacted]',
        '~(?<![\w-])-?\d{9,}(?!\w)~'                                                    => '[id]',
        '~[A-Za-z0-9_-]{24,}~'                                                          => '[redacted]',
    ];

    /**
     * Build result when request never got response; redact transport error and secrets.
     *
     * @param string[] $secrets
     */
    public static function transport(WP_Error $error, array $secrets = []): ChannelDeliveryResult
    {
        $detail = self::redact($error->get_error_message(), self::MAX_TRANSPORT_DETAIL, $secrets);

        if ($detail === '') {
            return ChannelDeliveryResult::failed(__('Could not connect to the notification service.', 'bit-smtp'));
        }

        // translators: %s: connection error reported by WordPress, with URLs and tokens removed
        return ChannelDeliveryResult::failed(\sprintf(__('Could not connect: %s', 'bit-smtp'), $detail));
    }

    /**
     * The HTTP call threw before completing; its message is not trusted, so it is never shown.
     */
    public static function requestFailed(): ChannelDeliveryResult
    {
        return ChannelDeliveryResult::failed(__('The request could not be sent.', 'bit-smtp'));
    }

    /**
     * The endpoint answered with a non-2xx status.
     */
    public static function httpStatus(int $status): ChannelDeliveryResult
    {
        // translators: %d: HTTP status code
        return ChannelDeliveryResult::failed(\sprintf(__('The endpoint responded with HTTP %d.', 'bit-smtp'), $status));
    }

    /**
     * The alert payload could not be JSON-encoded.
     */
    public static function unencodable(): ChannelDeliveryResult
    {
        return ChannelDeliveryResult::failed(__('The alert could not be encoded.', 'bit-smtp'));
    }

    /**
     * Return redacted single-line text; remove secrets and secret-shaped substrings.
     *
     * @param string[] $secrets the channel's own configured secret values
     */
    public static function redact(string $detail, int $max, array $secrets = []): string
    {
        $detail = self::removeSecrets($detail, $secrets);
        $detail = self::removeSecrets(rawurldecode($detail), $secrets);
        foreach (self::PATTERNS as $pattern => $replacement) {
            $detail = (string) preg_replace($pattern, $replacement, $detail);
        }
        $detail = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($detail)));

        return mb_substr($detail, 0, $max);
    }

    /**
     * Replace secrets in multiple forms; process longest first to avoid partial matches.
     *
     * @param string[] $secrets
     */
    private static function removeSecrets(string $detail, array $secrets): string
    {
        $secrets = array_filter($secrets, static fn (string $secret): bool => $secret !== '');
        usort($secrets, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        foreach ($secrets as $secret) {
            $detail = str_replace(array_unique([$secret, rawurlencode($secret), urlencode($secret)]), self::REDACTED, $detail);
        }

        return $detail;
    }
}
