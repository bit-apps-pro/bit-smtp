<?php

namespace BitApps\SMTP\Mail\Exceptions;

use RuntimeException;

class CredentialCipherException extends RuntimeException
{
    public static function encryptionFailed(): self
    {
        return new self('Credential encryption failed: the OpenSSL cipher is unavailable or rejected the input.');
    }

    public static function decryptionFailed(): self
    {
        return new self('Credential decryption failed: the stored value is malformed or was tampered with.');
    }
}
