<?php

namespace BitApps\SMTP\Mail\Exceptions;

use RuntimeException;

class ProviderNotFoundException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("Provider not found for key: '{$key}'");
    }
}
