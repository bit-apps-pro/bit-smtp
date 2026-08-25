<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Import;

use BitApps\SMTP\Mail\Import\WpMailSmtpImporter;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
final class WpMailSmtpImporterTest extends BaseUnitTestCase
{
    public function testMapsConfiguredSmtpMailerOntoOtherSmtpConnection(): void
    {
        $this->stubOption([
            'mail' => [
                'from_email'     => 'from@example.org',
                'from_name'      => 'From Name',
                'mailer'         => 'smtp',
                'reply_to_email' => 'reply@example.org',
            ],
            'smtp' => [
                'host'       => 'smtp.example.org',
                'port'       => 465,
                'encryption' => 'ssl',
                'auth'       => true,
                'user'       => 'mailer',
                'pass'       => 's3cret',
            ],
        ]);

        $connection = (new WpMailSmtpImporter())->toConnection();

        $this->assertNotNull($connection);
        $this->assertSame('other_smtp', $connection['provider']);
        $this->assertSame('smtp', $connection['kind']);
        $this->assertSame('from@example.org', $connection['fromEmail']);
        $this->assertSame('From Name', $connection['fromName']);
        $this->assertSame('reply@example.org', $connection['replyToEmail']);
        $this->assertSame('smtp.example.org', $connection['settings']['host']);
        $this->assertSame(465, $connection['settings']['port']);
        $this->assertSame('ssl', $connection['settings']['encryption']);
        $this->assertTrue($connection['settings']['auth']);
        $this->assertSame('mailer', $connection['settings']['username']);
        // With WP Mail SMTP's own class absent (as here / when the source plugin is inactive) the stored
        // pass may be ciphertext we can't safely reverse, so the password is left empty rather than
        // persisted broken — the rest of the connection still imports. Its source stays 'database'.
        $this->assertSame('', $connection['credentials']['password']['value']);
        $this->assertSame('database', $connection['credentials']['password']['source']);
    }

    public function testDetectsWhenSmtpMailerIsConfigured(): void
    {
        $this->stubOption([
            'mail' => ['mailer' => 'smtp'],
            'smtp' => ['host' => 'smtp.example.org'],
        ]);

        $this->assertTrue((new WpMailSmtpImporter())->detect());
    }

    public function testDoesNotImportNonSmtpMailer(): void
    {
        $this->stubOption([
            'mail' => ['mailer' => 'mailgun'],
            'smtp' => ['host' => 'smtp.example.org'],
        ]);

        $importer = new WpMailSmtpImporter();
        $this->assertFalse($importer->detect());
        $this->assertNull($importer->toConnection());
    }

    public function testDoesNotImportWhenHostMissing(): void
    {
        $this->stubOption(['mail' => ['mailer' => 'smtp'], 'smtp' => ['host' => '']]);

        $this->assertFalse((new WpMailSmtpImporter())->detect());
    }

    public function testReturnsNullWhenOptionAbsent(): void
    {
        Functions\when('get_option')->justReturn([]);

        $importer = new WpMailSmtpImporter();
        $this->assertFalse($importer->detect());
        $this->assertNull($importer->toConnection());
    }

    public function testEmptyEncryptionMapsToNone(): void
    {
        $this->stubOption([
            'mail' => ['mailer' => 'smtp'],
            'smtp' => ['host' => 'smtp.example.org', 'encryption' => ''],
        ]);

        $connection = (new WpMailSmtpImporter())->toConnection();
        $this->assertSame('none', $connection['settings']['encryption']);
    }

    /**
     * @param array<string,mixed> $option
     */
    private function stubOption(array $option): void
    {
        Functions\when('get_option')->alias(static function (string $key, $default = false) use ($option) {
            return $key === 'wp_mail_smtp' ? $option : $default;
        });
    }
}
