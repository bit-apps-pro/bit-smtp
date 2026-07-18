<?php

namespace BitApps\SMTP\Mail\Credentials;

use BitApps\SMTP\Mail\Exceptions\CredentialCipherException;

/**
 * Authenticated (AES-256-GCM) encryption for credentials at rest. Encrypted values are
 * self-identifying via the version prefix so plaintext left over from before this primitive
 * existed, or from a degraded environment, can still be read back untouched.
 */
final class CredentialCipher
{
    public const VERSION_PREFIX = 'bsenc:v1:';

    private const CIPHER = 'aes-256-gcm';

    private const IV_LENGTH = 12;

    private const TAG_LENGTH = 16;

    /**
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

        // Fail loudly: casting a false return to '' would persist a bogus, forever-undecryptable payload.
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
     * Derives a 32-byte AES key from the WP auth salt via HKDF. The `info` string is unique to
     * this primitive so it cannot collide with other salt-derived secrets (e.g. the OAuth state
     * HMAC key), even though both start from the same underlying salt.
     */
    private static function key(): string
    {
        return hash_hkdf('sha256', wp_salt('auth'), 32, 'bit-smtp-credentials-v1');
    }
}
