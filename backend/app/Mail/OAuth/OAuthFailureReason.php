<?php

namespace BitApps\SMTP\Mail\OAuth;

/**
 * Safe, enumerated OAuth callback failure codes sent to the admin UI (raw provider text is never forwarded).
 */
final class OAuthFailureReason
{
    public const ACCESS_DENIED = 'access_denied';

    public const INVALID_CLIENT = 'invalid_client';

    public const INVALID_GRANT = 'invalid_grant';

    public const REDIRECT_URI_MISMATCH = 'redirect_uri_mismatch';

    public const INVALID_STATE = 'invalid_state';

    public const CONNECTION_NOT_FOUND = 'connection_not_found';

    public const TOKEN_EXCHANGE_FAILED = 'token_exchange_failed';

    public const SAVE_FAILED = 'save_failed';

    public const UNKNOWN = 'unknown';

    private const PROVIDER_ERRORS = [
        'access_denied'         => self::ACCESS_DENIED,
        'consent_required'      => self::ACCESS_DENIED,
        'interaction_required'  => self::ACCESS_DENIED,
        'invalid_client'        => self::INVALID_CLIENT,
        'unauthorized_client'   => self::INVALID_CLIENT,
        'invalid_grant'         => self::INVALID_GRANT,
        'redirect_uri_mismatch' => self::REDIRECT_URI_MISMATCH,
    ];

    /**
     * Map an RFC 6749 `error` value from the provider to a known code, else the given fallback.
     */
    public static function fromProviderError(string $error, string $fallback = self::UNKNOWN): string
    {
        return self::PROVIDER_ERRORS[$error] ?? $fallback;
    }

    /**
     * Map a failed token-endpoint response body to a known code (token_exchange_failed when unrecognised).
     *
     * @param mixed $body decoded token-endpoint response
     */
    public static function fromTokenResponse($body): string
    {
        $error = \is_array($body) && \is_string($body['error'] ?? null) ? $body['error'] : '';

        return self::fromProviderError($error, self::TOKEN_EXCHANGE_FAILED);
    }
}
