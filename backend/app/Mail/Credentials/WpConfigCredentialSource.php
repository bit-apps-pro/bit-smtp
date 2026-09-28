<?php

namespace BitApps\SMTP\Mail\Credentials;

/**
 * Map (connection id, key) pairs to BIT_SMTP_<CONNID>_<KEY> wp-config constants.
 */
final class WpConfigCredentialSource
{
    private const CONSTANT_PREFIX = 'BIT_SMTP_';

    private ConstantReader $reader;

    public function __construct(?ConstantReader $reader = null)
    {
        $this->reader = $reader ?? new RuntimeConstantReader();
    }

    /**
     * The wp-config constant name that overrides the given connection credential.
     */
    public function constantName(string $connectionId, string $key): string
    {
        return self::CONSTANT_PREFIX . self::segment($connectionId) . '_' . self::segment($key);
    }

    /**
     * Override value for a credential; empty constants treated as unset to allow DB fallback.
     */
    public function resolve(string $connectionId, string $key): ?string
    {
        $value = $this->reader->read($this->constantName($connectionId, $key));

        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * Normalize an id/key segment to an uppercase, underscore-delimited constant fragment.
     */
    private static function segment(string $raw): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '_', strtoupper($raw));
    }
}
