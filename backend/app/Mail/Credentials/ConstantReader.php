<?php

namespace BitApps\SMTP\Mail\Credentials;

/**
 * Reads PHP constants (wp-config.php defines) behind a seam so credential overrides stay unit-testable
 * without having to declare real constants at test time.
 */
interface ConstantReader
{
    /**
     * The defined constant's value cast to string, or null when the constant is not defined.
     */
    public function read(string $name): ?string;
}
