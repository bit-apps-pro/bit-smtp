<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Support;

/**
 * Encodes a payload as an application/x-www-form-urlencoded body.
 */
final class FormEncoder implements EncoderInterface
{
    public function encode(array $payload): array
    {
        return ['body' => http_build_query($payload), 'contentType' => 'application/x-www-form-urlencoded'];
    }
}
