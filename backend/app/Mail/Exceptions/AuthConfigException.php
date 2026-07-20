<?php

namespace BitApps\SMTP\Mail\Exceptions;

use RuntimeException;

class AuthConfigException extends RuntimeException
{
    public static function missing(string $key): self
    {
        return new self("Missing credential: {$key}");
    }
}
