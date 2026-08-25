<?php

namespace BitApps\SMTP\Mail\Credentials;

/**
 * Production ConstantReader backed by PHP's global defined()/constant().
 */
final class RuntimeConstantReader implements ConstantReader
{
    /**
     * Return the wp-config constant's value as a string, or null when it is not defined.
     */
    public function read(string $name): ?string
    {
        return \defined($name) ? (string) \constant($name) : null;
    }
}
