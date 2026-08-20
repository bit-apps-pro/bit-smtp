<?php

namespace BitApps\SMTP\Mail\OAuth;

use BitApps\SMTP\Mail\Exceptions\OAuthStateException;

/**
 * Signed, expiring `state` token for the OAuth2 consent flow. Binds a connection id + provider to
 * an HMAC signature (keyed by a server-only WP salt) so the callback can prove the redirect it
 * received was one this site initiated (CSRF defence) and was not tampered with in transit.
 */
final class OAuthStateCodec
{
    private const TTL_SECONDS = 900;

    private const SEPARATOR = '.';

    public static function encode(string $connectionId, string $provider): string
    {
        $payload = self::base64UrlEncode((string) json_encode([
            'connection_id' => $connectionId,
            'provider'      => $provider,
            'iat'           => time(),
        ]));

        return $payload . self::SEPARATOR . self::sign($payload);
    }

    /**
     * @throws OAuthStateException on a malformed, forged, or expired state
     *
     * @return array{connection_id: string, provider: string}
     */
    public static function decode(string $state): array
    {
        $parts = explode(self::SEPARATOR, $state);
        if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw OAuthStateException::malformed();
        }

        [$payload, $signature] = $parts;

        if (!hash_equals(self::sign($payload), $signature)) {
            throw OAuthStateException::invalidSignature();
        }

        $decoded = json_decode(self::base64UrlDecode($payload), true);
        if (!\is_array($decoded) || !isset($decoded['connection_id'], $decoded['provider'], $decoded['iat'])) {
            throw OAuthStateException::malformed();
        }

        if (time() - (int) $decoded['iat'] > self::TTL_SECONDS) {
            throw OAuthStateException::expired();
        }

        return [
            'connection_id' => (string) $decoded['connection_id'],
            'provider'      => (string) $decoded['provider'],
        ];
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, self::secret());
    }

    private static function secret(): string
    {
        return wp_salt('auth');
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
