<?php

namespace BitApps\SMTP\Mail\Exceptions;

use RuntimeException;

class AuthConfigException extends RuntimeException
{
    public static function missing(string $key): self
    {
        return new self("Missing credential: {$key}");
    }

    /**
     * The region value is JSON-encoded so control characters (e.g. an injected newline)
     * surface as visible escapes instead of corrupting whatever the message is logged into.
     */
    public static function invalidRegion(string $region): self
    {
        return new self('Invalid AWS region: ' . json_encode($region));
    }
}
