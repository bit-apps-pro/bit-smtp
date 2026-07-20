<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Support;

/**
 * Serializes a request payload into a wire body plus its Content-Type.
 */
interface EncoderInterface
{
    /**
     * @return array{body: string, contentType: string}
     */
    public function encode(array $payload): array;
}
