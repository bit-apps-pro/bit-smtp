<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Support;

/**
 * Composite of FormEncoder and MultipartEncoder: stays the light urlencoded form for the common
 * no-attachment send, and auto-upgrades to multipart/form-data the moment a file group (an
 * assoc name=>path array) shows up in the built payload.
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
