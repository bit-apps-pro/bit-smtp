<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Support;

/**
 * Passes a pre-built raw MIME string (payload key `raw`) through untouched as a text/plain body.
 */
final class MimeRawEncoder implements EncoderInterface
{
    public function encode(array $payload): array
    {
        return ['body' => (string) ($payload['raw'] ?? ''), 'contentType' => 'text/plain'];
    }
}
