<?php

namespace BitApps\SMTP\Mail\Exceptions;

use RuntimeException;

/**
 * Raised on OAuth2 state token verification failure; treat as hard CSRF/tamper (abort before token touch).
 */
class OAuthStateException extends RuntimeException
{
    public static function malformed(): self
    {
        return new self('OAuth state is malformed.');
    }

    public static function invalidSignature(): self
    {
        return new self('OAuth state signature is invalid.');
    }

    public static function expired(): self
    {
        return new self('OAuth state has expired.');
    }
}
