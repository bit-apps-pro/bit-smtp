<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Import;

use BitApps\SMTP\Mail\Import\EasyWpSmtpImporter;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
final class EasyWpSmtpImporterTest extends BaseUnitTestCase
{
    public function testMapsConfiguredBlobOntoOtherSmtpConnection(): void
    {
        $this->stubOption([
            'from_email_field' => 'from@example.org',
            'from_name_field'  => 'From Name',
            'smtp_settings'    => [
                'host'            => 'smtp.example.org',
                'port'            => 587,
                'type_encryption' => 'tls',
                'autentication'   => 'yes',
                'username'        => 'mailer',
                'password'        => base64_encode('s3cret'),
            ],
        ]);

        $connection = (new EasyWpSmtpImporter())->toConnection();

        $this->assertNotNull($connection);
        $this->assertSame('other_smtp', $connection['provider']);
        $this->assertSame('smtp', $connection['kind']);
        $this->assertSame('from@example.org', $connection['fromEmail']);
        $this->assertSame('From Name', $connection['fromName']);
        $this->assertSame('smtp.example.org', $connection['settings']['host']);
        $this->assertSame(587, $connection['settings']['port']);
        $this->assertSame('tls', $connection['settings']['encryption']);
        $this->assertTrue($connection['settings']['auth']);
        $this->assertSame('mailer', $connection['settings']['username']);
        // Base64-encoded storage is decoded back to plaintext, then encrypted at rest on save.
        $this->assertSame('s3cret', $connection['credentials']['password']['value']);
        $this->assertSame('database', $connection['credentials']['password']['source']);
    }

    public function testAuthNoMapsToFalse(): void
    {
        $this->stubOption([
            'smtp_settings' => ['host' => 'smtp.example.org', 'autentication' => 'no'],
        ]);

        $connection = (new EasyWpSmtpImporter())->toConnection();
        $this->assertFalse($connection['settings']['auth']);
    }

    public function testNonBase64PasswordIsDroppedWhenTheDecryptorIsUnavailable(): void
    {
        // Not a clean base64 round-trip => likely AES ciphertext Easy WP SMTP would reverse with its own
        // key. Without swpsmtp_get_password() loaded we can't reverse it, so it is dropped rather than
        // persisted as a broken credential.
        $this->stubOption([
            'smtp_settings' => ['host' => 'smtp.example.org', 'password' => 'not~base64~cipher'],
        ]);

        $connection = (new EasyWpSmtpImporter())->toConnection();
        $this->assertSame('', $connection['credentials']['password']['value']);
    }

    public function testBase64AesCiphertextIsDroppedAsUnreversible(): void
    {
        // An AES password is stored base64(ciphertext): clean base64, but decodes to binary (not valid
        // UTF-8). Without swpsmtp_get_password() we can't reverse it, so it's dropped, not persisted broken.
        $ciphertext = "\xff\xfe\xfd\x80\x81\x82\x00\x13";
        $this->stubOption([
            'smtp_settings' => ['host' => 'smtp.example.org', 'password' => base64_encode($ciphertext)],
        ]);

        $connection = (new EasyWpSmtpImporter())->toConnection();
        $this->assertSame('', $connection['credentials']['password']['value']);
    }

    public function testDetectsOnlyWhenHostConfigured(): void
    {
        $this->stubOption(['smtp_settings' => ['host' => 'smtp.example.org']]);
        $this->assertTrue((new EasyWpSmtpImporter())->detect());
    }

    public function testReturnsNullWhenUnconfigured(): void
    {
        Functions\when('get_option')->justReturn([]);

        $importer = new EasyWpSmtpImporter();
        $this->assertFalse($importer->detect());
        $this->assertNull($importer->toConnection());
    }

    /**
     * @param array<string,mixed> $option
     */
    private function stubOption(array $option): void
    {
        Functions\when('get_option')->alias(static function (string $key, $default = false) use ($option) {
            return $key === 'swpsmtp_options' ? $option : $default;
        });
    }
}
