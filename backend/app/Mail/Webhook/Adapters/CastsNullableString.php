<?php

namespace BitApps\SMTP\Mail\Webhook\Adapters;

/**
 * Null-safe scalar-to-string cast shared by webhook adapters that read optional id fields.
 */
trait CastsNullableString
{
    protected function stringOrNull($value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
