<?php

namespace BitApps\SMTP\Mail\Credentials;

/**
 * Flatten stored {source, value} credential pairs to the scalar strings provider validators expect.
 */
final class CredentialValues
{
    /**
     * Map each credential key to its scalar value, degrading a missing or non-scalar value to ''.
     *
     * @param array<string,mixed> $credentials
     *
     * @return array<string,string>
     */
    public static function flatten(array $credentials): array
    {
        return array_map([self::class, 'scalar'], $credentials);
    }

    /**
     * Scalar value of one credential, whether stored as a {source, value} pair or already flat.
     *
     * @param mixed $credential
     */
    private static function scalar($credential): string
    {
        $value = \is_array($credential) ? ($credential['value'] ?? '') : $credential;

        return \is_scalar($value) ? (string) $value : '';
    }
}
