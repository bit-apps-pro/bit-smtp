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
     * JSON-encode region to surface control chars as escapes instead of corrupting logs.
     */
    public static function invalidRegion(string $region): self
    {
        return new self('Invalid AWS region: ' . json_encode($region));
    }
}
