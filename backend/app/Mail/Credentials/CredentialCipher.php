<?php

namespace BitApps\SMTP\Mail\Credentials;

use BitApps\SMTP\Mail\Exceptions\CredentialCipherException;

/**
 * Authenticated AES-256-GCM encryption for credentials; self-identifying prefix supports legacy plaintext.
 */
final class CredentialCipher
{
    public const VERSION_PREFIX = 'bsenc:v1:';

    private const CIPHER = 'aes-256-gcm';

    private const IV_LENGTH = 12;

    private const TAG_LENGTH = 16;

    /**
     * Encrypt plaintext; fails loudly on cipher error to prevent undecryptable payloads.
     *
     * @throws CredentialCipherException if OpenSSL is present but the AES-256-GCM cipher fails
     */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        if (strpos($plaintext, self::VERSION_PREFIX) === 0) {
            return $plaintext;
        }

        if (!\function_exists('openssl_encrypt')) {
            return $plaintext;
        }

        $iv         = random_bytes(self::IV_LENGTH);
        $tag        = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw CredentialCipherException::encryptionFailed();
        }

        return self::VERSION_PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * @throws CredentialCipherException if `$stored` carries the version prefix but cannot be
     *                                   authenticated as a valid AES-256-GCM payload
     */
    public static function decrypt(string $stored): string
    {
        if (strpos($stored, self::VERSION_PREFIX) !== 0) {
            return $stored;
        }

        $payload = base64_decode(substr($stored, \strlen(self::VERSION_PREFIX)), true);
        if ($payload === false || \strlen($payload) <= self::IV_LENGTH + self::TAG_LENGTH) {
            throw CredentialCipherException::decryptionFailed();
        }

        $iv         = substr($payload, 0, self::IV_LENGTH);
        $tag        = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($payload, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw CredentialCipherException::decryptionFailed();
        }

        return $plaintext;
    }

    /**
     * Derive 32-byte AES key from WP auth salt via HKDF with unique info string.
     */
    private static function key(): string
    {
        return hash_hkdf('sha256', wp_salt('auth'), 32, 'bit-smtp-credentials-v1');
    }
}
