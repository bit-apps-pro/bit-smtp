<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Support;

/**
 * Encodes a payload as a JSON body.
 */
final class JsonEncoder implements EncoderInterface
{
    public function encode(array $payload): array
    {
        return ['body' => (string) json_encode($payload), 'contentType' => 'application/json'];
    }
}
