<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Credentials;

use BitApps\SMTP\Mail\Credentials\CredentialCipher;
use BitApps\SMTP\Mail\Exceptions\CredentialCipherException;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
class CredentialCipherTest extends BaseUnitTestCase
{
    private const SALT = 'unit-test-auth-salt';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_salt')->justReturn(self::SALT);
    }

    public function testDecryptReversesEncryptForTheSamePlaintext(): void
    {
        $secret = 'smtp-app-password-123';

        $this->assertSame($secret, CredentialCipher::decrypt(CredentialCipher::encrypt($secret)));
    }

    public function testEncryptedValueDiffersFromPlaintextAndCarriesVersionPrefix(): void
    {
        $secret     = 'smtp-app-password-123';
        $ciphertext = CredentialCipher::encrypt($secret);

        $this->assertNotSame($secret, $ciphertext);
        $this->assertStringStartsWith('bsenc:v1:', $ciphertext);
    }

    public function testTwoEncryptCallsOnTheSameInputProduceDifferentCiphertext(): void
    {
        $secret = 'smtp-app-password-123';

        $this->assertNotSame(CredentialCipher::encrypt($secret), CredentialCipher::encrypt($secret));
    }

    public function testEncryptOfEmptyStringReturnsEmptyString(): void
    {
        $this->assertSame('', CredentialCipher::encrypt(''));
    }

    public function testDecryptOfEmptyStringReturnsEmptyString(): void
    {
        $this->assertSame('', CredentialCipher::decrypt(''));
    }

    public function testDecryptOfUnprefixedLegacyPlaintextPassesThroughUnchanged(): void
    {
        $this->assertSame('plain-legacy-value', CredentialCipher::decrypt('plain-legacy-value'));
    }

    public function testEncryptIsIdempotentOnAlreadyEncryptedValue(): void
    {
        $encrypted = CredentialCipher::encrypt('smtp-app-password-123');

        $this->assertSame($encrypted, CredentialCipher::encrypt($encrypted));
    }

    public function testDecryptOfTamperedCiphertextThrowsCredentialCipherException(): void
    {
        $encrypted = CredentialCipher::encrypt('smtp-app-password-123');
        $payload   = base64_decode(substr($encrypted, \strlen('bsenc:v1:')), true);

        // Flip a byte inside the ciphertext region (after the 12-byte IV + 16-byte tag) so the GCM tag no longer verifies.
        $payload[29] = \chr(\ord($payload[29]) ^ 0xFF);
        $tampered    = 'bsenc:v1:' . base64_encode($payload);

        $this->expectException(CredentialCipherException::class);
        CredentialCipher::decrypt($tampered);
    }

    public function testDecryptOfPrefixedNonBase64PayloadThrowsCredentialCipherException(): void
    {
        $this->expectException(CredentialCipherException::class);
        CredentialCipher::decrypt('bsenc:v1:not@@base64');
    }

    public function testDecryptOfPrefixedTruncatedPayloadThrowsCredentialCipherException(): void
    {
        $this->expectException(CredentialCipherException::class);
        CredentialCipher::decrypt('bsenc:v1:' . base64_encode('short'));
    }
}
