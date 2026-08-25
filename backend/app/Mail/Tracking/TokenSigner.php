<?php

namespace BitApps\SMTP\Mail\Tracking;

\defined('ABSPATH') || exit();

/**
 * Stateless, tamper-evident tokens for open/click tracking URLs. A payload is HMAC-SHA256 signed
 * with a server-only key so the public tracking endpoints can prove a token was minted by this site
 * (no DB lookup, no open-redirect surface). No TTL: late opens are legitimate and expiry is bounded
 * by log retention instead. Mirrors OAuthStateCodec's signed-token idiom.
 */
final class TokenSigner
{
    private const SEPARATOR = '.';

    /**
     * Sign an arbitrary payload into a URL-safe `base64url(json).hmac` token.
     *
     * @param array<string,mixed> $payload
     */
    public static function sign(array $payload): string
    {
        $encoded = self::base64UrlEncode((string) json_encode($payload));

        return $encoded . self::SEPARATOR . self::signature($encoded);
    }

    /**
     * Verify a token and return its payload, or null when malformed, forged, or foreign-signed.
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

        // Constant-time compare so a mismatch can't be timing-probed byte by byte.
        if (!hash_equals(self::signature($encoded), $signature)) {
            return null;
        }

        $decoded = json_decode(self::base64UrlDecode($encoded), true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * HMAC-SHA256 over the encoded payload, keyed by this primitive's dedicated key.
     */
    private static function signature(string $encoded): string
    {
        return hash_hmac('sha256', $encoded, self::key());
    }

    /**
     * Derive a 32-byte key from the WP auth salt via HKDF. The `info` string is unique to tracking so
     * it can't collide with the credential-cipher or OAuth-state keys derived from the same salt —
     * the same anti-collision convention CredentialCipher::key() documents.
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
