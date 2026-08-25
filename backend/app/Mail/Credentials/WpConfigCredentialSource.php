<?php

namespace BitApps\SMTP\Mail\Credentials;

/**
 * Single source of truth mapping a (connection id, credential key) pair to its wp-config override
 * constant, and reading that constant at resolve time. Lets operators keep a secret out of the DB by
 * defining it in wp-config.php.
 *
 * Naming scheme: BIT_SMTP_<CONNID>_<KEY>, where CONNID and KEY are the connection id and credential
 * key uppercased with every non-alphanumeric character replaced by '_'
 * (e.g. connection "conn_1" password -> BIT_SMTP_CONN_1_PASSWORD, api_key -> BIT_SMTP_CONN_1_API_KEY).
 *
 * Coverage (as of now): the send-auth secrets — the SMTP password (SmtpTransport) and every API
 * credential read through AbstractAuthStrategy (api_key/bearer/basic templates, SES secret_key).
 * NOT yet covered (these read credentials directly, bypassing this source): OAuth refresh/client
 * secrets (OAuth2TokenProvider) and inbound webhook signing secrets. Wiring those through a single
 * credential chokepoint is a follow-up; a defined constant for an uncovered key is silently ignored.
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
     * The override value for a connection credential, or null when no usable constant is defined. A
     * defined-but-empty constant is treated as unset so it can never lock out the DB fallback.
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
