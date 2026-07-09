<?php

namespace BitApps\SMTP\Mail\Exceptions;

use RuntimeException;

class DuplicateProviderException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("Provider already registered for key: '{$key}'");
    }
}
