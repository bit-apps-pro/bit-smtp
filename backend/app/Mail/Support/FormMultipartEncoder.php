<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Support;

/**
 * Composite encoder; stays urlencoded for no-attachments, upgrades to multipart.
 */
final class FormMultipartEncoder implements EncoderInterface
{
    public function encode(array $payload): array
    {
        foreach ($payload as $value) {
            if (\is_array($value)) {
                return (new MultipartEncoder())->encode($payload);
            }
        }

        return (new FormEncoder())->encode($payload);
    }
}
