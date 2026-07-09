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

    public function test_save_settings_with_sentinel_preserves_stored_secret(): void
    {
        $service = $this->freshService();

        // First write stores a real password
        $service->saveSettings($this->v2SettingsArray('conn_1', 'real-secret'));

        // Second write sends the sentinel; stored secret must survive
        $incoming = $this->v2SettingsArray('conn_1', '********');
        $this->freshService()->saveSettings($incoming);

        $stored = $this->freshService()->load()->getConnections()->byId('conn_1');
        $this->assertNotNull($stored);
        $this->assertSame('real-secret', $stored->getCredentials()['password']['value']);
    }

    public function test_save_settings_with_real_password_overwrites(): void
    {
        $service = $this->freshService();
        $service->saveSettings($this->v2SettingsArray('conn_1', 'old-secret'));

        $this->freshService()->saveSettings($this->v2SettingsArray('conn_1', 'new-secret'));

        $stored = $this->freshService()->load()->getConnections()->byId('conn_1');
        $this->assertSame('new-secret', $stored->getCredentials()['password']['value']);
    }

    public function test_api_settings_returns_masked_password(): void
    {
        $service = $this->freshService();
        $service->saveSettings($this->v2SettingsArray('conn_1', 'super-secret'));

        $api = $this->freshService()->apiSettings();

        $this->assertSame('********', $api['connections'][0]['credentials']['password']['value']);
    }

    public function test_save_connection_assigns_id_and_sets_default_on_first(): void
    {
        $service = $this->freshService();
        $service->saveConnection([
            'id'           => '',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'First',
            'enabled'      => true,
            'fromEmail'    => 'a@example.com',
            'fromName'     => 'A',
            'replyToEmail' => '',
            'settings'     => ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'auth' => false, 'username' => '', 'smtp_debug' => false],
            'credentials'  => ['password' => ['source' => 'database', 'value' => 'pw1']],
        ]);

        $loaded = $this->freshService()->load();
        $conn   = $loaded->getConnections()->first();

        $this->assertNotNull($conn);
        $this->assertStringStartsWith('conn_', $conn->getId());
        $this->assertSame($conn->getId(), $loaded->getDefaultConnectionId());
    }

    public function test_save_connection_updates_existing_without_wiping_password(): void
    {
        $service = $this->freshService();
        // Create a connection with a real password
        $service->saveSettings($this->v2SettingsArray('conn_abc', 'keep-me'));

        // Update the same connection sending sentinel
        $this->freshService()->saveConnection([
            'id'           => 'conn_abc',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'Updated',
            'enabled'      => true,
            'fromEmail'    => 'b@example.com',
            'fromName'     => 'B',
            'replyToEmail' => '',
            'settings'     => ['host' => 'new-host.example.com', 'port' => 587, 'encryption' => 'tls', 'auth' => true, 'username' => 'user', 'smtp_debug' => false],
            'credentials'  => ['password' => ['source' => 'database', 'value' => '********']],
        ]);

        $conn = $this->freshService()->load()->getConnections()->byId('conn_abc');
        $this->assertNotNull($conn);
        $this->assertSame('keep-me', $conn->getCredentials()['password']['value']);
        $this->assertSame('new-host.example.com', $conn->setting('host'));
    }

    public function test_save_connection_omitting_credentials_preserves_stored_password(): void
    {
        $service = $this->freshService();
        // Create a connection with a real password stored in the DB.
        $service->saveSettings($this->v2SettingsArray('conn_abc', 'keep-me'));

        // Update the same connection with a payload that omits the credentials key entirely.
        $this->freshService()->saveConnection([
            'id'           => 'conn_abc',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'Updated without credentials',
            'enabled'      => true,
            'fromEmail'    => 'b@example.com',
            'fromName'     => 'B',
            'replyToEmail' => '',
            'settings'     => ['host' => 'new-host.example.com', 'port' => 587, 'encryption' => 'tls', 'auth' => true, 'username' => 'user', 'smtp_debug' => false],
            // credentials key is intentionally absent
        ]);

        $conn = $this->freshService()->load()->getConnections()->byId('conn_abc');
        $this->assertNotNull($conn);
        $this->assertSame(
            'keep-me',
            $conn->getCredentials()['password']['value'],
            'Stored password must survive a saveConnection payload that omits the credentials key.'
        );
        $this->assertSame('new-host.example.com', $conn->setting('host'));
    }

    public function test_delete_connection_removes_it_and_repoints_default(): void
    {
        // Store two connections, conn_1 as default
        $data = $this->v2SettingsArray('conn_1', 'pass1');
        $data['connections'][] = [
            'id'           => 'conn_2',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'Second',
            'enabled'      => true,
            'fromEmail'    => 'b@example.com',
            'fromName'     => 'B',
            'replyToEmail' => '',
            'settings'     => ['host' => 'smtp2.example.com', 'port' => 587, 'encryption' => 'tls', 'auth' => false, 'username' => '', 'smtp_debug' => false],
            'credentials'  => ['password' => ['source' => 'database', 'value' => 'pass2']],
        ];
        $this->freshService()->saveSettings($data);

        // Delete the default connection
        $this->freshService()->deleteConnection('conn_1');

        $loaded = $this->freshService()->load();
        $this->assertNull($loaded->getConnections()->byId('conn_1'));
        $this->assertSame('conn_2', $loaded->getDefaultConnectionId());
    }

    private function v2SettingsArray(string $connId, string $password): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => $connId,
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'           => $connId,
                    'provider'     => 'other_smtp',
                    'kind'         => 'smtp',
                    'name'         => 'Primary',
                    'enabled'      => true,
                    'fromEmail'    => 'from@example.com',
                    'fromName'     => 'From',
                    'replyToEmail' => '',
                    'settings'     => [
                        'host'       => 'smtp.example.com',
                        'port'       => 587,
                        'encryption' => 'tls',
                        'auth'       => true,
                        'username'   => 'user',
                        'smtp_debug' => false,
                    ],
                    'credentials'  => [
                        'password' => ['source' => 'database', 'value' => $password],
                    ],
                ],
            ],
            'features' => [],
        ];
    }

    private function freshService(): MailConfigService
    {
        return new MailConfigService();
    }
}
