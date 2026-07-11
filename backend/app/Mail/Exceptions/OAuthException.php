<?php

namespace BitApps\SMTP\Mail\Exceptions;

use RuntimeException;

class OAuthException extends RuntimeException
{
    public static function missingRefreshToken(): self
    {
        return new self('OAuth refresh failed: no refresh token is stored for this connection.');
    }

    public static function refreshFailed(string $reason): self
    {
        return new self("OAuth token refresh failed: {$reason}");
    }
}
