<?php

namespace BitApps\SMTP\Mail\OAuth;

/**
 * Reads the signed-in account's email from an OpenID Connect id_token returned by the token endpoint.
 *
 * The signature is not checked: OIDC Core §3.1.3.7 allows TLS server validation instead when the token
 * comes straight from the token endpoint, and the value is only displayed, never used for authorization.
 */
final class IdTokenAccount
{
    private const EMAIL_CLAIMS = ['email', 'preferred_username'];

    /**
     * Email from the token response's id_token, or '' when absent or not an email address.
     *
     * @param array<string,mixed> $tokenResponse
     */
    public static function email(array $tokenResponse): string
    {
        $claims = self::claims((string) ($tokenResponse['id_token'] ?? ''));

        foreach (self::EMAIL_CLAIMS as $claim) {
            $value = $claims[$claim] ?? null;
            if (\is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Decoded JWT payload claims, or [] when malformed.
     *
     * @return array<string,mixed>
     */
    private static function claims(string $jwt): array
    {
        $segments = explode('.', $jwt);
        if (\count($segments) !== 3) {
            return [];
        }

        $json   = base64_decode(strtr($segments[1], '-_', '+/'), true);
        $claims = $json !== false ? json_decode($json, true) : null;

        return \is_array($claims) ? $claims : [];
    }
}
