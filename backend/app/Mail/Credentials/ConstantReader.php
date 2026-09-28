<?php

namespace BitApps\SMTP\Mail\Credentials;

/**
 * Read PHP constants behind a seam for testable credential overrides.
 */
interface ConstantReader
{
    /**
     * The defined constant's value cast to string, or null when the constant is not defined.
     */
    public function read(string $name): ?string;
}
