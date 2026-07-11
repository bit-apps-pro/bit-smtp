<?php

namespace BitApps\SMTP\Mail\Exceptions;

use RuntimeException;

/**
 * Raised whenever an OAuth2 `state` token fails verification. Callers must treat any instance as a
 * hard CSRF/tamper failure and abort before touching tokens.
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
