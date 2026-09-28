<?php

namespace BitApps\SMTP\Mail\Tracking;

\defined('ABSPATH') || exit();

/**
 * Stateless, tamper-evident tokens for tracking URLs using HMAC-SHA256 with server-only key; no TTL or open-redirect surface.
 */
final class TokenSigner
{
    private const SEPARATOR = '.';

    /**
     * Sign payload into URL-safe base64url(json).hmac token.
     *
     * @param array<string,mixed> $payload
     */
    public static function sign(array $payload): string
    {
        $encoded = self::base64UrlEncode((string) json_encode($payload));

        return $encoded . self::SEPARATOR . self::signature($encoded);
    }

    /**
     * Verify token and return payload using constant-time comparison to prevent timing attacks.
     *
     * @return null|array<string,mixed>
     */
    public static function verify(string $token): ?array
    {
        $parts = explode(self::SEPARATOR, $token);
        if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$encoded, $signature] = $parts;

        if (!hash_equals(self::signature($encoded), $signature)) {
            return null;
        }

        $decoded = json_decode(self::base64UrlDecode($encoded), true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Compute HMAC-SHA256 signature over encoded payload using dedicated key.
     */
    private static function signature(string $encoded): string
    {
        return hash_hmac('sha256', $encoded, self::key());
    }

    /**
     * Derive 32-byte key from WP auth salt via HKDF with tracking-specific info string to prevent collisions.
     */
    private static function key(): string
    {
        return hash_hkdf('sha256', wp_salt('auth'), 32, 'bit-smtp-tracking-v1');
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = \strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
