<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Plugin;
use BitSmtpEncryptSecrets;

/**
 * Data-safety guarantees for the MailConfigService facade: legacy config maps correctly, writes are
 * v2, the pre-migration legacy array is backed up exactly once, the legacy shape stays plaintext,
 * empty passwords never wipe the stored secret, and the live send path works end-to-end.
 *
 * @internal
 *
 * @coversNothing
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

    public function testLoadMapsLegacyConfigOntoDefaultConnection(): void
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

    public function testSavingAMigratedLegacyConnectionDoesNotDuplicateIt(): void
    {
        // Regression (found via live E2E): migrate-on-load used a random connection id,
        // so the id the frontend loaded mismatched the save-time re-migration of the still
        // legacy option, and saveConnection appended a second connection instead of updating.
        $this->storeOptions([
            'status'         => true,
            'smtp_host'      => 'legacy.example.org',
            'smtp_user_name' => 'user@example.org',
            'smtp_password'  => 'real-secret',
            'smtp_auth'      => true,
        ]);

        // Mirror the frontend: load (migrates), then save the connection back with the
        // masked (untouched) password sentinel — exactly what ConnectionEditor posts.
        $migrated                                     = $this->freshService()->load()->defaultConnection()->toArray();
        $migrated['credentials']['password']['value'] = '********';
        $this->freshService()->saveConnection($migrated);

        $reloaded = $this->freshService()->load();
        $this->assertCount(1, $reloaded->getConnections()->all());
        $this->assertSame('real-secret', $reloaded->defaultConnection()->getCredentials()['password']['value']);
    }

    public function testLoadNeverWritesTheDb(): void
    {
        $this->storeOptions(['status' => true, 'smtp_host' => 'smtp.example.org']);

        $this->freshService()->load();

        // The migrated v2 shape lives only in memory; the DB keeps the raw legacy array.
        $this->assertArrayNotHasKey('schema_version', Config::getOption('options'));
    }

    public function testSaveFromLegacyPersistsV2Schema(): void
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

    public function testLegacyBackupIsCreatedOnceAndNeverOverwritten(): void
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

    public function testToLegacyShapeReturnsAllKeysWithPlaintextPassword(): void
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

    public function testEmptyPasswordPreservesStoredSecret(): void
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

    public function testNullPasswordPreservesStoredSecret(): void
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

    public function testEnabledConfigWithoutHostLeavesWpDefaultMailUntouched(): void
    {
        // Enabled mid-setup with no host: pre_wp_mail must defer to native wp_mail (return null)
        // rather than take over the send with a hostless, broken SMTP connection.
        $this->storeOptions(['status' => true, 'smtp_host' => '']);
        Plugin::instance()->mailConfigService()->reload();

        $result = Plugin::instance()->smtpProvider()->onPreWpMail(null, [
            'to'      => 'to@example.org',
            'subject' => 'Subject',
            'message' => 'Body',
        ]);

        $this->assertNull($result);
    }

    public function testEndToEndLegacyConfigDeliversThroughV2Path(): void
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

    public function testStoredCredentialValueIsEncryptedAtRestWhileLoadReturnsPlaintext(): void
    {
        $service = $this->freshService();
        $service->saveSettings($this->v2SettingsArray('conn_1', 'real-secret'));

        $raw         = Config::getOption('options');
        $storedValue = $raw['connections'][0]['credentials']['password']['value'];

        $this->assertStringStartsWith('bsenc:v1:', $storedValue);
        $this->assertNotSame('real-secret', $storedValue);

        $loaded = $this->freshService()->load()->getConnections()->byId('conn_1');
        $this->assertNotNull($loaded);
        $this->assertSame('real-secret', $loaded->getCredentials()['password']['value']);
    }

    public function testApiShapeMasksAndLegacyShapeDecryptsAfterEncryptedSave(): void
    {
        $service = $this->freshService();
        $service->saveSettings($this->v2SettingsArray('conn_1', 'legacy-secret'));

        $api = $this->freshService()->apiSettings();
        $this->assertSame('********', $api['connections'][0]['credentials']['password']['value']);

        $legacy = $this->freshService()->toLegacyShape();
        $this->assertSame('legacy-secret', $legacy['smtp_password']);
    }

    public function testFailureWebhookSecretsAreEncryptedMaskedAndPreservedOnSave(): void
    {
        $data                       = $this->v2SettingsArray('conn_1', 'smtp-secret');
        $data['features']['alerts'] = [
            'enabled' => true,
            'email'   => ['enabled' => false, 'recipients' => []],
            'webhook' => [
                'enabled'        => true,
                'url'            => 'https://hooks.example.com/private-token',
                'signing_secret' => 'whsec_abcdefghijklmnopqrstuvwxyz012345',
            ],
        ];

        $this->freshService()->saveSettings($data);

        $raw = Config::getOption('options');
        $this->assertStringStartsWith(
            'bsenc:v1:',
            $raw['features']['alerts']['webhook']['url']
        );
        $this->assertStringStartsWith(
            'bsenc:v1:',
            $raw['features']['alerts']['webhook']['signing_secret']
        );

        $loaded = $this->freshService()->load();
        $this->assertSame(
            'https://hooks.example.com/private-token',
            $loaded->getFeatures()['alerts']['webhook']['url']
        );
        $this->assertSame(
            'whsec_abcdefghijklmnopqrstuvwxyz012345',
            $loaded->getFeatures()['alerts']['webhook']['signing_secret']
        );
        $this->assertSame(
            '********',
            $this->freshService()->apiSettings()['features']['alerts']['webhook']['url']
        );
        $this->assertSame(
            '********',
            $this->freshService()->apiSettings()['features']['alerts']['webhook']['signing_secret']
        );

        $data['features']['alerts']['webhook']['url']            = '********';
        $data['features']['alerts']['webhook']['signing_secret'] = '********';
        $this->freshService()->saveSettings($data);
        $this->assertSame(
            'https://hooks.example.com/private-token',
            $this->freshService()->load()->getFeatures()['alerts']['webhook']['url']
        );
        $this->assertSame(
            'whsec_abcdefghijklmnopqrstuvwxyz012345',
            $this->freshService()->load()->getFeatures()['alerts']['webhook']['signing_secret']
        );
    }

    public function testSlackAndTelegramSecretsAreEncryptedMaskedDecryptedAndPreservedOnSave(): void
    {
        $data                       = $this->v2SettingsArray('conn_1', 'smtp-secret');
        $data['features']['alerts'] = [
            'slack'    => [
                'enabled'     => true,
                'webhook_url' => 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX',
            ],
            'telegram' => [
                'enabled'   => true,
                'bot_token' => '123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz',
                'chat_id'   => '-1001234567890',
            ],
        ];

        $this->freshService()->saveSettings($data);

        $raw = Config::getOption('options');
        $this->assertStringStartsWith('bsenc:v1:', $raw['features']['alerts']['slack']['webhook_url']);
        $this->assertStringStartsWith('bsenc:v1:', $raw['features']['alerts']['telegram']['bot_token']);
        $this->assertSame('-1001234567890', $raw['features']['alerts']['telegram']['chat_id']);

        $loaded = $this->freshService()->load()->getFeatures()['alerts'];
        $this->assertSame(
            'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX',
            $loaded['slack']['webhook_url']
        );
        $this->assertSame('123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz', $loaded['telegram']['bot_token']);
        $this->assertSame('-1001234567890', $loaded['telegram']['chat_id']);

        $api = $this->freshService()->apiSettings()['features']['alerts'];
        $this->assertSame('********', $api['slack']['webhook_url']);
        $this->assertSame('********', $api['telegram']['bot_token']);
        $this->assertSame('-1001234567890', $api['telegram']['chat_id']);

        $data['features']['alerts']['slack']['webhook_url']  = '********';
        $data['features']['alerts']['telegram']['bot_token'] = '********';
        $this->freshService()->saveSettings($data);

        $preserved = $this->freshService()->load()->getFeatures()['alerts'];
        $this->assertSame(
            'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX',
            $preserved['slack']['webhook_url']
        );
        $this->assertSame('123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz', $preserved['telegram']['bot_token']);
    }

    public function testWebhookOnlyAlertsSavePreservesOmittedSlackAndTelegramChannels(): void
    {
        $stored                       = $this->v2SettingsArray('conn_1', 'smtp-secret');
        $stored['features']['alerts'] = [
            'webhook'  => ['enabled' => true, 'url' => 'https://hooks.example.com/failure'],
            'slack'    => [
                'enabled'     => true,
                'webhook_url' => 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX',
            ],
            'telegram' => [
                'enabled'   => true,
                'bot_token' => '123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz',
                'chat_id'   => '-1001234567890',
            ],
        ];
        $this->freshService()->saveSettings($stored);

        $partial                       = $this->v2SettingsArray('conn_1', 'smtp-secret');
        $partial['features']['alerts'] = [
            'webhook' => ['enabled' => false],
        ];
        $this->freshService()->saveSettings($partial);

        $raw = Config::getOption('options')['features']['alerts'];
        $this->assertStringStartsWith('bsenc:v1:', $raw['slack']['webhook_url']);
        $this->assertStringStartsWith('bsenc:v1:', $raw['telegram']['bot_token']);

        $loaded = $this->freshService()->load()->getFeatures()['alerts'];
        $this->assertSame([
            'enabled'     => true,
            'webhook_url' => 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX',
        ], $loaded['slack']);
        $this->assertSame([
            'enabled'   => true,
            'bot_token' => '123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz',
            'chat_id'   => '-1001234567890',
        ], $loaded['telegram']);

        $api = $this->freshService()->apiSettings()['features']['alerts'];
        $this->assertSame('********', $api['slack']['webhook_url']);
        $this->assertSame('********', $api['telegram']['bot_token']);
        $this->assertSame('-1001234567890', $api['telegram']['chat_id']);
    }

    public function testExplicitSlackChannelSaveCanClearItsStoredConfiguration(): void
    {
        $stored                       = $this->v2SettingsArray('conn_1', 'smtp-secret');
        $stored['features']['alerts'] = [
            'slack'    => [
                'enabled'     => true,
                'webhook_url' => 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX',
            ],
            'telegram' => [
                'enabled'   => true,
                'bot_token' => '123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz',
                'chat_id'   => '-1001234567890',
            ],
        ];
        $this->freshService()->saveSettings($stored);

        $partial                       = $this->v2SettingsArray('conn_1', 'smtp-secret');
        $partial['features']['alerts'] = [
            'slack' => ['enabled' => false, 'webhook_url' => ''],
        ];
        $this->freshService()->saveSettings($partial);

        $loaded = $this->freshService()->load()->getFeatures()['alerts'];
        $this->assertSame(['enabled' => false, 'webhook_url' => ''], $loaded['slack']);
        $this->assertSame('123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz', $loaded['telegram']['bot_token']);
    }

    public function testPartialTelegramSavePreservesOmittedTargetFieldsWhileExplicitBlankClearsChatId(): void
    {
        $stored                       = $this->v2SettingsArray('conn_1', 'smtp-secret');
        $stored['features']['alerts'] = [
            'telegram' => [
                'enabled'   => true,
                'bot_token' => '123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz',
                'chat_id'   => '-1001234567890',
            ],
        ];
        $this->freshService()->saveSettings($stored);

        $partial                       = $this->v2SettingsArray('conn_1', 'smtp-secret');
        $partial['features']['alerts'] = [
            'telegram' => ['enabled' => false],
        ];
        $this->freshService()->saveSettings($partial);

        $preserved = $this->freshService()->load()->getFeatures()['alerts']['telegram'];
        $this->assertSame(false, $preserved['enabled']);
        $this->assertSame('123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz', $preserved['bot_token']);
        $this->assertSame('-1001234567890', $preserved['chat_id']);

        $partial['features']['alerts']['telegram']['chat_id'] = '';
        $this->freshService()->saveSettings($partial);

        $this->assertSame('', $this->freshService()->load()->getFeatures()['alerts']['telegram']['chat_id']);
    }

    public function testEncryptSecretsMigrationEncryptsExistingPlaintextInstall(): void
    {
        // Seed the raw option exactly as a pre-encryption install would have it: v2 shape, but the
        // credential value still plain (no bsenc:v1: prefix).
        $this->storeOptions($this->v2SettingsArray('conn_1', 'plain-install-secret'));

        // Mirror a real request: the shared service instance must reflect the seeded option before
        // the migration re-stores it, exactly like Plugin::maybeMigrateDB() runs on a fresh request.
        Plugin::instance()->mailConfigService()->reload();

        if (!class_exists('BitSmtpEncryptSecrets', false)) {
            require_once \dirname(__DIR__, 2) . '/backend/db/Migrations/BitSmtpEncryptSecrets.php';
        }
        (new BitSmtpEncryptSecrets())->up();

        $raw = Config::getOption('options');
        $this->assertStringStartsWith(
            'bsenc:v1:',
            $raw['connections'][0]['credentials']['password']['value']
        );

        $loaded = $this->freshService()->load()->getConnections()->byId('conn_1');
        $this->assertNotNull($loaded);
        $this->assertSame('plain-install-secret', $loaded->getCredentials()['password']['value']);
    }

    public function testMaybeMigrateDbEncryptsWhenDbVersionIsBehindAtCurrentPluginVersion(): void
    {
        // The real upgrade population: an install already at Config::VERSION (so the plugin-version
        // gate is satisfied) but with db_version behind. The encrypt migration must still fire off the
        // db_version gate; otherwise every already-updated install keeps its secrets in plaintext.
        $this->storeOptions($this->v2SettingsArray('conn_1', 'legacy-plaintext'));

        $previousVersion   = Config::getOption('version');
        $previousDbVersion = Config::getOption('db_version');

        Config::updateOption('version', Config::VERSION, true);
        Config::updateOption('db_version', '1.1', true);

        // maybeMigrateDB is capability-gated; user 1 is the admin seeded by the WP test suite.
        wp_set_current_user(1);
        // The migration reuses the shared service; make it reflect the freshly seeded option.
        Plugin::instance()->mailConfigService()->reload();

        try {
            Plugin::maybeMigrateDB();

            $raw = Config::getOption('options');
            $this->assertStringStartsWith(
                'bsenc:v1:',
                $raw['connections'][0]['credentials']['password']['value']
            );

            $loaded = $this->freshService()->load()->getConnections()->byId('conn_1');
            $this->assertNotNull($loaded);
            $this->assertSame('legacy-plaintext', $loaded->getCredentials()['password']['value']);
        } finally {
            wp_set_current_user(0);
            Config::updateOption('version', $previousVersion, true);
            Config::updateOption('db_version', $previousDbVersion, true);
            Plugin::instance()->mailConfigService()->reload();
        }
    }

    public function testConnectionWithEmptyCredentialsRoundTripsWithoutError(): void
    {
        // The encrypt/decrypt walk must tolerate a connection with no credentials at all (e.g. an
        // API-key provider mid-setup, before any secret has been entered).
        $data                                  = $this->v2SettingsArray('conn_1', 'unused');
        $data['connections'][0]['credentials'] = [];

        $this->freshService()->saveSettings($data);

        $loaded = $this->freshService()->load()->getConnections()->byId('conn_1');
        $this->assertNotNull($loaded);
        $this->assertSame([], $loaded->getCredentials());
    }

    public function testCorruptedCiphertextDegradesToEmptyStringWithoutFatal(): void
    {
        // Simulates an unrecoverable credential (e.g. rotated wp_salt): the bsenc:v1: prefix is
        // present but the payload cannot be authenticated. load() must degrade this one value to ''
        // rather than throw and take down the admin screen.
        $data                                                       = $this->v2SettingsArray('conn_1', 'placeholder');
        $data['connections'][0]['credentials']['password']['value'] = 'bsenc:v1:not-valid-base64-payload!!!';
        $this->storeOptions($data);

        $loaded = $this->freshService()->load()->getConnections()->byId('conn_1');

        $this->assertNotNull($loaded);
        $this->assertSame('', $loaded->getCredentials()['password']['value']);
    }

    public function testSaveSettingsWithSentinelPreservesStoredSecret(): void
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

    public function testSaveSettingsWithRealPasswordOverwrites(): void
    {
        $service = $this->freshService();
        $service->saveSettings($this->v2SettingsArray('conn_1', 'old-secret'));

        $this->freshService()->saveSettings($this->v2SettingsArray('conn_1', 'new-secret'));

        $stored = $this->freshService()->load()->getConnections()->byId('conn_1');
        $this->assertSame('new-secret', $stored->getCredentials()['password']['value']);
    }

    public function testApiSettingsReturnsMaskedPassword(): void
    {
        $service = $this->freshService();
        $service->saveSettings($this->v2SettingsArray('conn_1', 'super-secret'));

        $api = $this->freshService()->apiSettings();

        $this->assertSame('********', $api['connections'][0]['credentials']['password']['value']);
    }

    public function testSaveConnectionAssignsIdAndSetsDefaultOnFirst(): void
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

    public function testSaveConnectionUpdatesExistingWithoutWipingPassword(): void
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

    public function testSaveConnectionOmittingCredentialsPreservesStoredPassword(): void
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

    public function testSaveConnectionEditPayloadWithPartialCredentialsPreservesOAuthTokens(): void
    {
        // Seed a connected OAuth connection with all three credential keys, as OAuthController
        // leaves it after a completed consent flow.
        $this->freshService()->saveSettings([
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_oauth',
            'fallback_connection_ids' => [],
            'connections'             => [[
                'id'           => 'conn_oauth',
                'provider'     => 'gmail',
                'kind'         => 'oauth',
                'name'         => 'Gmail',
                'enabled'      => true,
                'fromEmail'    => 'a@example.com',
                'fromName'     => 'A',
                'replyToEmail' => '',
                'settings'     => ['host' => '', 'port' => 0, 'encryption' => 'none', 'auth' => false, 'username' => '', 'smtp_debug' => false],
                'credentials'  => [
                    'client_secret' => ['source' => 'database', 'value' => 'orig-client-secret'],
                    'access_token'  => ['source' => 'database', 'value' => 'orig-access-token'],
                    'refresh_token' => ['source' => 'database', 'value' => 'orig-refresh-token'],
                ],
            ]],
            'features' => [],
        ]);

        // Edit the same connection sending ONLY client_secret — exactly what the frontend posts
        // when editing an already-connected OAuth connection outside a fresh consent flow.
        $this->freshService()->saveConnection([
            'id'           => 'conn_oauth',
            'provider'     => 'gmail',
            'kind'         => 'oauth',
            'name'         => 'Gmail Renamed',
            'enabled'      => true,
            'fromEmail'    => 'a@example.com',
            'fromName'     => 'A',
            'replyToEmail' => '',
            'settings'     => ['host' => '', 'port' => 0, 'encryption' => 'none', 'auth' => false, 'username' => '', 'smtp_debug' => false],
            'credentials'  => [
                'client_secret' => ['source' => 'database', 'value' => 'new-client-secret'],
            ],
        ]);

        $reloaded = $this->freshService()->load()->getConnections()->byId('conn_oauth');
        $this->assertNotNull($reloaded);
        $creds = $reloaded->getCredentials();
        $this->assertSame(
            'orig-access-token',
            $creds['access_token']['value'] ?? null,
            'access_token must survive an edit payload that omits it'
        );
        $this->assertSame(
            'orig-refresh-token',
            $creds['refresh_token']['value'] ?? null,
            'refresh_token must survive an edit payload that omits it'
        );
        $this->assertSame('new-client-secret', $creds['client_secret']['value'] ?? null);
        $this->assertSame('Gmail Renamed', $reloaded->getName());
    }

    public function testSaveConnectionCannotSpoofWebhookProvisioningStatus(): void
    {
        $service = $this->freshService();
        $connId  = $service->upsertConnection([
            'id'           => '',
            'provider'     => 'postmark',
            'kind'         => 'api',
            'name'         => 'Postmark',
            'enabled'      => true,
            'fromEmail'    => 'a@example.com',
            'fromName'     => 'A',
            'replyToEmail' => '',
            'settings'     => [],
            'credentials'  => ['api_key' => ['source' => 'database', 'value' => 'pm-key']],
        ]);
        $this->assertNotNull($connId);

        // Simulate a real recorded outcome, exactly as WebhookProvisioningService::recordOutcome does.
        $this->freshService()->persistConnectionProvisioning($connId, [], [
            'webhook_provisioning_status'     => 'failed',
            'webhook_provisioning_reason'     => 'provider returned 401',
            'webhook_provisioning_updated_at' => 1700000000,
        ]);

        // A crafted connection-save payload tries to overwrite the outcome to fake provider
        // confirmation — this must never survive a normal save.
        $this->freshService()->saveConnection([
            'id'           => $connId,
            'provider'     => 'postmark',
            'kind'         => 'api',
            'name'         => 'Postmark',
            'enabled'      => true,
            'fromEmail'    => 'a@example.com',
            'fromName'     => 'A',
            'replyToEmail' => '',
            'settings'     => [
                'webhook_provisioning_status'     => 'registered',
                'webhook_provisioning_reason'     => '',
                'webhook_provisioning_updated_at' => time(),
            ],
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'pm-key']],
        ]);

        $conn = $this->freshService()->load()->getConnections()->byId($connId);
        $this->assertNotNull($conn);
        $this->assertSame('failed', $conn->getWebhookProvisioningStatus());
        $this->assertSame('provider returned 401', $conn->getWebhookProvisioningReason());
        $this->assertSame(1700000000, $conn->getWebhookProvisioningUpdatedAt());
    }

    public function testProviderSwapCannotStageSpoofedWebhookProvisioningStatus(): void
    {
        // Step 1: save under a non-webhook provider (gmail) with a client-staged forged status.
        // Before the fix this bypassed the managed-field strip and persisted the forgery.
        $connId = $this->freshService()->upsertConnection([
            'id'           => '',
            'provider'     => 'gmail',
            'kind'         => 'api',
            'name'         => 'Staged',
            'enabled'      => true,
            'fromEmail'    => 'a@example.com',
            'fromName'     => 'A',
            'replyToEmail' => '',
            'settings'     => ['webhook_provisioning_status' => 'registered'],
            'credentials'  => [],
        ]);
        $this->assertNotNull($connId);

        // Step 2: re-save the same id as a webhook-capable provider with the webhook disabled, so the
        // strip runs but would restore the staged forgery from the prior stored connection.
        $this->freshService()->saveConnection([
            'id'           => $connId,
            'provider'     => 'postmark',
            'kind'         => 'api',
            'name'         => 'Swapped',
            'enabled'      => true,
            'fromEmail'    => 'a@example.com',
            'fromName'     => 'A',
            'replyToEmail' => '',
            'settings'     => ['webhook_enabled' => false],
            'credentials'  => ['api_key' => ['source' => 'database', 'value' => 'pm-key']],
        ]);

        $conn = $this->freshService()->load()->getConnections()->byId($connId);
        $this->assertNotNull($conn);
        $this->assertSame(
            '',
            $conn->getWebhookProvisioningStatus(),
            'A provider swap must not let a client-staged provisioning status survive.'
        );
    }

    public function testTokenlessOAuthDraftIsNotPromotedToDefault(): void
    {
        // Mirrors the abandoned-consent path (#8): the OAuth draft is persisted (to mint an id for
        // the OAuth state) before any token exists. It must not become the default routing target,
        // since Gmail cannot send without a refresh_token.
        $connId = $this->freshService()->upsertConnection([
            'id'           => '',
            'provider'     => 'gmail',
            'kind'         => 'api',
            'name'         => 'Gmail draft',
            'enabled'      => true,
            'fromEmail'    => 'a@example.com',
            'fromName'     => 'A',
            'replyToEmail' => '',
            'settings'     => ['client_id' => 'abc.apps.googleusercontent.com'],
            'credentials'  => ['client_secret' => ['source' => 'database', 'value' => 'cs']],
        ]);

        $this->assertNotNull($connId);
        $this->assertSame('', $this->freshService()->load()->getDefaultConnectionId());
    }

    public function testOAuthConnectionBecomesDefaultOnceTokensAreStored(): void
    {
        // First save the tokenless draft (not promoted), then re-save the same id with the tokens the
        // OAuth callback stores. Now sendable and still the only connection, it becomes the default.
        $service = $this->freshService();
        $connId  = $service->upsertConnection([
            'id'           => '',
            'provider'     => 'gmail',
            'kind'         => 'api',
            'name'         => 'Gmail',
            'enabled'      => true,
            'fromEmail'    => 'a@example.com',
            'fromName'     => 'A',
            'replyToEmail' => '',
            'settings'     => ['client_id' => 'abc.apps.googleusercontent.com'],
            'credentials'  => ['client_secret' => ['source' => 'database', 'value' => 'cs']],
        ]);
        $this->assertNotNull($connId);
        $this->assertSame('', $this->freshService()->load()->getDefaultConnectionId());

        $this->freshService()->upsertConnection([
            'id'           => $connId,
            'provider'     => 'gmail',
            'kind'         => 'api',
            'name'         => 'Gmail',
            'enabled'      => true,
            'fromEmail'    => 'a@example.com',
            'fromName'     => 'A',
            'replyToEmail' => '',
            'settings'     => ['client_id' => 'abc.apps.googleusercontent.com'],
            'credentials'  => [
                'client_secret' => ['source' => 'database', 'value' => 'cs'],
                'refresh_token' => ['source' => 'database', 'value' => 'rt-from-consent'],
                'access_token'  => ['source' => 'database', 'value' => 'at-from-consent'],
            ],
        ]);

        $this->assertSame($connId, $this->freshService()->load()->getDefaultConnectionId());
    }

    public function testTokenlessOAuthDraftDoesNotStealDefaultFromAWorkingConnection(): void
    {
        // A working SMTP connection is the default; adding an abandoned OAuth draft must leave the
        // working connection as the routing target.
        $service = $this->freshService();
        $service->saveSettings($this->v2SettingsArray('conn_smtp', 'pw'));
        $this->assertSame('conn_smtp', $this->freshService()->load()->getDefaultConnectionId());

        $this->freshService()->upsertConnection([
            'id'           => '',
            'provider'     => 'gmail',
            'kind'         => 'api',
            'name'         => 'Gmail draft',
            'enabled'      => true,
            'fromEmail'    => 'a@example.com',
            'fromName'     => 'A',
            'replyToEmail' => '',
            'settings'     => ['client_id' => 'abc.apps.googleusercontent.com'],
            'credentials'  => ['client_secret' => ['source' => 'database', 'value' => 'cs']],
        ]);

        $this->assertSame('conn_smtp', $this->freshService()->load()->getDefaultConnectionId());
    }

    public function testDeleteConnectionRemovesItAndRepointsDefault(): void
    {
        // Store two connections, conn_1 as default
        $data                  = $this->v2SettingsArray('conn_1', 'pass1');
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

    public function testDeleteConnectionPrunesRoutingRulesTargetingIt(): void
    {
        // Two connections, each targeted by a routing rule; delete conn_1 and its rule must go while
        // conn_2's rule survives.
        $data                  = $this->v2SettingsArray('conn_1', 'pass1');
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
        $data['features']['routing'] = [
            ['connectionId' => 'conn_1', 'conditions' => [['field' => 'source_plugin', 'operator' => 'equals', 'value' => 'woocommerce']]],
            ['connectionId' => 'conn_2', 'conditions' => [['field' => 'source_plugin', 'operator' => 'equals', 'value' => 'edd']]],
        ];
        $this->freshService()->saveSettings($data);

        $this->freshService()->deleteConnection('conn_1');

        $routing = $this->freshService()->load()->getFeatures()['routing'];
        $this->assertCount(1, $routing);
        $this->assertSame('conn_2', $routing[0]['connectionId']);
    }

    public function testDisconnectOAuthClearsTokensButKeepsClientCredentialsAndConnection(): void
    {
        $this->freshService()->saveSettings([
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_oauth',
            'fallback_connection_ids' => [],
            'connections'             => [[
                'id'           => 'conn_oauth',
                'provider'     => 'gmail',
                'kind'         => 'oauth',
                'name'         => 'Gmail',
                'enabled'      => true,
                'fromEmail'    => 'a@example.com',
                'fromName'     => 'A',
                'replyToEmail' => '',
                'settings'     => ['token_expires_at' => 9999999999],
                'credentials'  => [
                    'client_secret' => ['source' => 'database', 'value' => 'orig-client-secret'],
                    'access_token'  => ['source' => 'database', 'value' => 'orig-access-token'],
                    'refresh_token' => ['source' => 'database', 'value' => 'orig-refresh-token'],
                ],
            ]],
            'features' => [],
        ]);

        $this->assertTrue($this->freshService()->disconnectOAuth('conn_oauth'));

        $reloaded = $this->freshService()->load()->getConnections()->byId('conn_oauth');
        $this->assertNotNull($reloaded, 'the connection itself must survive a disconnect');
        $creds = $reloaded->getCredentials();
        $this->assertArrayNotHasKey('access_token', $creds);
        $this->assertArrayNotHasKey('refresh_token', $creds);
        $this->assertSame('orig-client-secret', $creds['client_secret']['value'] ?? null);
        $this->assertArrayNotHasKey('token_expires_at', $reloaded->getSettings());
        // It was the only (and default) connection, so the default clears rather than pointing at
        // the now-unsendable connection.
        $this->assertSame('', $this->freshService()->load()->getDefaultConnectionId());
    }

    public function testDisconnectOAuthLeavesADifferentDefaultUntouched(): void
    {
        // A working SMTP connection is the default; disconnecting a non-default OAuth connection must
        // clear that connection's tokens without repointing the unrelated default.
        $data                  = $this->v2SettingsArray('conn_smtp', 'pw');
        $data['connections'][] = [
            'id'           => 'conn_oauth',
            'provider'     => 'gmail',
            'kind'         => 'oauth',
            'name'         => 'Gmail',
            'enabled'      => true,
            'fromEmail'    => 'a@example.com',
            'fromName'     => 'A',
            'replyToEmail' => '',
            'settings'     => ['token_expires_at' => 9999999999],
            'credentials'  => [
                'client_secret' => ['source' => 'database', 'value' => 'cs'],
                'refresh_token' => ['source' => 'database', 'value' => 'rt'],
            ],
        ];
        $this->freshService()->saveSettings($data);
        $this->assertSame('conn_smtp', $this->freshService()->load()->getDefaultConnectionId());

        $this->assertTrue($this->freshService()->disconnectOAuth('conn_oauth'));

        $loaded = $this->freshService()->load();
        $this->assertSame('conn_smtp', $loaded->getDefaultConnectionId());
        $this->assertArrayNotHasKey('refresh_token', $loaded->getConnections()->byId('conn_oauth')->getCredentials());
    }

    public function testDisconnectOAuthRefusesANonOAuthConnection(): void
    {
        // Clearing tokens on an SMTP connection is meaningless; refuse so a no-op clear can't repoint
        // the default away from a working connection (momus #1).
        $this->freshService()->saveSettings($this->v2SettingsArray('conn_smtp', 'pw'));

        $this->assertFalse($this->freshService()->disconnectOAuth('conn_smtp'));
        $this->assertSame('conn_smtp', $this->freshService()->load()->getDefaultConnectionId());
    }

    public function testDisconnectOAuthRepointsDefaultToAnEnabledSibling(): void
    {
        // conn_oauth is the default; disconnecting it (now unsendable) must move the default to the
        // working SMTP sibling so routing never points at a tokenless OAuth connection.
        $data                   = $this->v2SettingsArray('conn_oauth', 'unused');
        $data['connections'][0] = [
            'id'           => 'conn_oauth',
            'provider'     => 'gmail',
            'kind'         => 'oauth',
            'name'         => 'Gmail',
            'enabled'      => true,
            'fromEmail'    => 'a@example.com',
            'fromName'     => 'A',
            'replyToEmail' => '',
            'settings'     => ['token_expires_at' => 9999999999],
            'credentials'  => [
                'client_secret' => ['source' => 'database', 'value' => 'cs'],
                'access_token'  => ['source' => 'database', 'value' => 'at'],
                'refresh_token' => ['source' => 'database', 'value' => 'rt'],
            ],
        ];
        $data['connections'][] = [
            'id'           => 'conn_smtp',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'SMTP',
            'enabled'      => true,
            'fromEmail'    => 'b@example.com',
            'fromName'     => 'B',
            'replyToEmail' => '',
            'settings'     => ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'auth' => false, 'username' => '', 'smtp_debug' => false],
            'credentials'  => ['password' => ['source' => 'database', 'value' => 'pw']],
        ];
        $this->freshService()->saveSettings($data);
        $this->assertSame('conn_oauth', $this->freshService()->load()->getDefaultConnectionId());

        $this->freshService()->disconnectOAuth('conn_oauth');

        $this->assertSame('conn_smtp', $this->freshService()->load()->getDefaultConnectionId());
    }

    public function testDisconnectOAuthReturnsFalseForUnknownConnection(): void
    {
        $this->assertFalse($this->freshService()->disconnectOAuth('conn_nope'));
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
