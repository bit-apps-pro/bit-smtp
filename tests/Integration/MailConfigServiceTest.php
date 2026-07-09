<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Plugin;

/**
 * Data-safety guarantees for the MailConfigService facade: legacy config maps correctly, writes are
 * v2, the pre-migration legacy array is backed up exactly once, the legacy shape stays plaintext,
 * empty passwords never wipe the stored secret, and the live send path works end-to-end.
 */
final class MailConfigServiceTest extends IntegrationTestCase
{
    private const LEGACY_BACKUP_OPTION = 'options_v1_backup';

    protected function setUp(): void
    {
        parent::setUp();
        Config::deleteOption(self::LEGACY_BACKUP_OPTION);
    }

    protected function tearDown(): void
    {
        Config::deleteOption(self::LEGACY_BACKUP_OPTION);
        parent::tearDown();
    }

    public function test_load_maps_legacy_config_onto_default_connection(): void
    {
        $this->storeOptions([
            'status'             => true,
            'smtp_host'          => 'smtp.example.org',
            'port'               => 2525,
            'encryption'         => 'tls',
            're_email_address'   => 'reply@example.org',
            'smtp_auth'          => true,
            'smtp_user_name'     => 'mailer',
            'smtp_password'      => 's3cret',
        ]);

        $connection = $this->freshService()->load()->defaultConnection();

        $this->assertNotNull($connection);
        $this->assertSame('smtp.example.org', $connection->setting('host'));
        $this->assertSame(2525, (int) $connection->setting('port'));
        $this->assertSame('tls', $connection->setting('encryption'));
        $this->assertSame('reply@example.org', $connection->getReplyToEmail());
        $this->assertSame('s3cret', $connection->getCredentials()['password']['value']);
    }

    public function test_load_never_writes_the_db(): void
    {
        $this->storeOptions(['status' => true, 'smtp_host' => 'smtp.example.org']);

        $this->freshService()->load();

        // The migrated v2 shape lives only in memory; the DB keeps the raw legacy array.
        $this->assertArrayNotHasKey('schema_version', Config::getOption('options'));
    }

    public function test_save_from_legacy_persists_v2_schema(): void
    {
        $this->storeOptions(['status' => true, 'smtp_host' => 'legacy.example.org']);

        $this->freshService()->saveFromLegacy([
            'status'    => true,
            'smtp_host' => 'saved.example.org',
            'port'      => 587,
        ]);

        $stored = Config::getOption('options');
        $this->assertSame(2, $stored['schema_version']);
        $this->assertSame('saved.example.org', $stored['connections'][0]['settings']['host']);
    }

    public function test_legacy_backup_is_created_once_and_never_overwritten(): void
    {
        $legacy = ['status' => true, 'smtp_host' => 'original.example.org', 'port' => 25];
        $this->storeOptions($legacy);

        $service = $this->freshService();
        $service->saveFromLegacy(['status' => true, 'smtp_host' => 'first.example.org']);

        $backup = Config::getOption(self::LEGACY_BACKUP_OPTION);
        $this->assertSame($legacy, $backup);

        $service->saveFromLegacy(['status' => true, 'smtp_host' => 'second.example.org']);

        // Backup captures the ORIGINAL legacy array once; later v2 writes must not overwrite it.
        $this->assertSame($legacy, Config::getOption(self::LEGACY_BACKUP_OPTION));
    }

    public function test_to_legacy_shape_returns_all_keys_with_plaintext_password(): void
    {
        $this->storeOptions([
            'status'             => true,
            'from_email_address' => 'from@example.org',
            'from_name'          => 'From Name',
            're_email_address'   => 'reply@example.org',
            'smtp_host'          => 'smtp.example.org',
            'encryption'         => 'ssl',
            'port'               => 465,
            'smtp_auth'          => true,
            'smtp_debug'         => true,
            'smtp_user_name'     => 'mailer',
            'smtp_password'      => 'plaintext-secret',
        ]);

        $legacy = $this->freshService()->toLegacyShape();

        $expectedKeys = [
            'status', 'from_email_address', 'from_name', 're_email_address', 'smtp_host',
            'encryption', 'port', 'smtp_auth', 'smtp_debug', 'smtp_user_name', 'smtp_password',
        ];
        $this->assertSame($expectedKeys, array_keys($legacy));
        $this->assertCount(11, $legacy);
        $this->assertSame('plaintext-secret', $legacy['smtp_password']);
    }

    public function test_empty_password_preserves_stored_secret(): void
    {
        $this->freshService()->saveFromLegacy([
            'status'         => true,
            'smtp_host'      => 'smtp.example.org',
            'smtp_auth'      => true,
            'smtp_user_name' => 'mailer',
            'smtp_password'  => 'keep-me',
        ]);

        // A cold service (production rebuilds it per request) reloads the stored secret from the DB.
        $this->freshService()->saveFromLegacy([
            'status'         => true,
            'smtp_host'      => 'smtp.example.org',
            'smtp_auth'      => true,
            'smtp_user_name' => 'mailer',
            'smtp_password'  => '',
        ]);

        $this->assertSame('keep-me', $this->freshService()->toLegacyShape()['smtp_password']);
    }

    public function test_null_password_preserves_stored_secret(): void
    {
        $this->freshService()->saveFromLegacy([
            'status'         => true,
            'smtp_host'      => 'smtp.example.org',
            'smtp_auth'      => true,
            'smtp_user_name' => 'mailer',
            'smtp_password'  => 'keep-me',
        ]);

        // A nullable smtp_password may arrive as null from the validator; it must not wipe the secret.
        $this->freshService()->saveFromLegacy([
            'status'         => true,
            'smtp_host'      => 'smtp.example.org',
            'smtp_auth'      => true,
            'smtp_user_name' => 'mailer',
            'smtp_password'  => null,
        ]);

        $this->assertSame('keep-me', $this->freshService()->toLegacyShape()['smtp_password']);
    }

    public function test_enabled_config_without_host_leaves_wp_default_mail_untouched(): void
    {
        $this->useRealPhpMailer();
        // Enabled mid-setup with no host: the bridge must not switch PHPMailer to a broken SMTP send.
        $this->storeOptions(['status' => true, 'smtp_host' => '']);
        Plugin::instance()->mailConfigService()->reload();

        global $phpmailer;
        Plugin::instance()->smtpProvider()->configureMailer($phpmailer);

        $this->assertNotSame('smtp', $phpmailer->Mailer);
    }

    public function test_end_to_end_legacy_config_delivers_through_v2_path(): void
    {
        $this->useRealPhpMailer();
        $this->storeOptions([
            'status'             => true,
            'smtp_host'          => self::SMTP_HOST,
            'port'               => self::SMTP_PORT,
            'encryption'         => 'none',
            'smtp_auth'          => false,
            'from_email_address' => 'from@example.org',
            'from_name'          => 'From',
        ]);
        Plugin::instance()->mailConfigService()->reload();

        $sent = wp_mail('to@example.org', 'Subject', 'Body');

        $this->assertTrue($sent);
        $this->assertNotEmpty($this->mailpitMessages());
        $this->assertFalse(Plugin::instance()->smtpProvider()->isFailed());
    }

    private function freshService(): MailConfigService
    {
        return new MailConfigService();
    }
}
