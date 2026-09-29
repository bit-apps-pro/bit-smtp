<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Exceptions\InvalidAlertChannelException;
use BitApps\SMTP\Plugin;
use BitSmtpEncryptSecrets;

/**
 * Tests MailConfigService data-safety: legacy mapping, v2 writes, backup, encryption, end-to-end send.
 *
 * @internal
 *
 * @coversNothing
 */
final class MailConfigServiceTest extends IntegrationTestCase
{
    private const LEGACY_BACKUP_OPTION = 'options_v1_backup';

    private const WHSEC = 'whsec_abcdefghijklmnopqrstuvwxyz012345';

    private const SLACK_URL = 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXX';

    private const BOT_TOKEN = '123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz';

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
        $this->storeOptions([
            'status'         => true,
            'smtp_host'      => 'legacy.example.org',
            'smtp_user_name' => 'user@example.org',
            'smtp_password'  => 'real-secret',
            'smtp_auth'      => true,
        ]);

        $migrated                                     = $this->freshService()->load()->defaultConnection()->toArray();
        $migrated['credentials']['password']['value'] = '********';
        $this->freshService()->saveConnection($migrated);

        $reloaded = $this->freshService()->load();
        $this->assertCount(1, $reloaded->getConnections()->all());
        $this->assertSame('real-secret', $reloaded->defaultConnection()->getCredentials()['password']['value']);
    }

    /**
     * Load never persists the migrated v2 shape; the DB keeps the raw legacy array.
     */
    public function testLoadNeverWritesTheDb(): void
    {
        $this->storeOptions(['status' => true, 'smtp_host' => 'smtp.example.org']);

        $this->freshService()->load();

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

    /**
     * A cold service (rebuilt per request) reloads the stored secret from the DB.
     */
    public function testEmptyPasswordPreservesStoredSecret(): void
    {
        $this->freshService()->saveFromLegacy([
            'status'         => true,
            'smtp_host'      => 'smtp.example.org',
            'smtp_auth'      => true,
            'smtp_user_name' => 'mailer',
            'smtp_password'  => 'keep-me',
        ]);

        $this->freshService()->saveFromLegacy([
            'status'         => true,
            'smtp_host'      => 'smtp.example.org',
            'smtp_auth'      => true,
            'smtp_user_name' => 'mailer',
            'smtp_password'  => '',
        ]);

        $this->assertSame('keep-me', $this->freshService()->toLegacyShape()['smtp_password']);
    }

    /**
     * A nullable smtp_password must not wipe the stored secret.
     */
    public function testNullPasswordPreservesStoredSecret(): void
    {
        $this->freshService()->saveFromLegacy([
            'status'         => true,
            'smtp_host'      => 'smtp.example.org',
            'smtp_auth'      => true,
            'smtp_user_name' => 'mailer',
            'smtp_password'  => 'keep-me',
        ]);

        $this->freshService()->saveFromLegacy([
            'status'         => true,
            'smtp_host'      => 'smtp.example.org',
            'smtp_auth'      => true,
            'smtp_user_name' => 'mailer',
            'smtp_password'  => null,
        ]);

        $this->assertSame('keep-me', $this->freshService()->toLegacyShape()['smtp_password']);
    }

    /**
     * Enabled mid-setup without a host defers to native wp_mail rather than sending over broken SMTP.
     */
    public function testEnabledConfigWithoutHostLeavesWpDefaultMailUntouched(): void
    {
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
        $data['features']['alerts'] = $this->alerts([
            $this->alertInstance('ch_hook001', 'webhook', [
                'url'            => 'https://hooks.example.com/private-token',
                'signing_secret' => self::WHSEC,
            ]),
        ]);

        $this->freshService()->saveSettings($data);

        $raw = Config::getOption('options')['features']['alerts']['channels'][0]['settings'];
        $this->assertStringStartsWith('bsenc:v1:', $raw['url']);
        $this->assertStringStartsWith('bsenc:v1:', $raw['signing_secret']);

        $expected = ['url' => 'https://hooks.example.com/private-token', 'signing_secret' => self::WHSEC];
        $this->assertSame($expected, $this->loadedAlertSettings(0));
        $this->assertSame(
            ['url' => 'https://hooks.example.com/private-token', 'signing_secret' => '********'],
            $this->freshService()->apiSettings()['features']['alerts']['channels'][0]['settings']
        );

        $data['features']['alerts']['channels'][0]['settings'] = ['url' => 'https://hooks.example.com/private-token', 'signing_secret' => '********'];
        $this->freshService()->saveSettings($data);
        $this->assertSame($expected, $this->loadedAlertSettings(0));
    }

    public function testAnEchoedSentinelInAnEnabledChannelsUrlIsRejectedNotRestored(): void
    {
        $data                       = $this->v2SettingsArray('conn_1', 'smtp-secret');
        $data['features']['alerts'] = $this->alerts([
            $this->alertInstance('ch_hook001', 'webhook', ['url' => 'https://hooks.example.com/private-token', 'signing_secret' => self::WHSEC]),
        ]);
        $this->freshService()->saveSettings($data);

        $data['features']['alerts']['channels'][0]['settings'] = ['url' => '********', 'signing_secret' => '********'];

        $this->expectException(InvalidAlertChannelException::class);
        $this->expectExceptionMessage('needs a valid webhook URL');

        $this->freshService()->saveSettings($data);
    }

    public function testSameTypeInstancesAreEncryptedMaskedAndRestoredIndependently(): void
    {
        $data                       = $this->v2SettingsArray('conn_1', 'smtp-secret');
        $data['features']['alerts'] = $this->alerts([
            $this->alertInstance('ch_slackaa', 'slack', ['webhook_url' => self::SLACK_URL . 'A']),
            $this->alertInstance('ch_slackbb', 'slack', ['webhook_url' => self::SLACK_URL . 'B']),
            $this->alertInstance('ch_tele001', 'telegram', ['bot_token' => self::BOT_TOKEN, 'chat_id' => '-1001234567890']),
        ]);

        $this->freshService()->saveSettings($data);

        $raw = Config::getOption('options')['features']['alerts']['channels'];
        $this->assertStringStartsWith('bsenc:v1:', $raw[0]['settings']['webhook_url']);
        $this->assertStringStartsWith('bsenc:v1:', $raw[1]['settings']['webhook_url']);
        $this->assertStringStartsWith('bsenc:v1:', $raw[2]['settings']['bot_token']);
        $this->assertSame('-1001234567890', $raw[2]['settings']['chat_id']);

        $api = $this->freshService()->apiSettings()['features']['alerts']['channels'];
        $this->assertSame(self::SLACK_URL . 'A', $api[0]['settings']['webhook_url']);
        $this->assertSame(self::SLACK_URL . 'B', $api[1]['settings']['webhook_url']);
        $this->assertSame('********', $api[2]['settings']['bot_token']);
        $this->assertSame('-1001234567890', $api[2]['settings']['chat_id']);

        $data['features']['alerts']['channels'] = array_reverse($api);
        $this->freshService()->saveSettings($data);

        $loaded = $this->freshService()->load()->getFeatures()['alerts']['channels'];
        $this->assertSame(['ch_tele001', 'ch_slackbb', 'ch_slackaa'], array_column($loaded, 'id'));
        $this->assertSame(self::BOT_TOKEN, $loaded[0]['settings']['bot_token']);
        $this->assertSame(self::SLACK_URL . 'B', $loaded[1]['settings']['webhook_url']);
        $this->assertSame(self::SLACK_URL . 'A', $loaded[2]['settings']['webhook_url']);
    }

    public function testTheSavedChannelListIsAuthoritativeSoOmittedInstancesAreDeleted(): void
    {
        $data                       = $this->v2SettingsArray('conn_1', 'smtp-secret');
        $data['features']['alerts'] = $this->alerts([
            $this->alertInstance('ch_slackaa', 'slack', ['webhook_url' => self::SLACK_URL]),
            $this->alertInstance('ch_tele001', 'telegram', ['bot_token' => self::BOT_TOKEN, 'chat_id' => '-1001234567890']),
        ]);
        $this->freshService()->saveSettings($data);

        $data['features']['alerts']['channels'] = [
            $this->alertInstance('ch_tele001', 'telegram', ['bot_token' => '********', 'chat_id' => '-1001234567890']),
        ];
        $this->freshService()->saveSettings($data);

        $loaded = $this->freshService()->load()->getFeatures()['alerts']['channels'];
        $this->assertSame(['ch_tele001'], array_column($loaded, 'id'));
        $this->assertSame(self::BOT_TOKEN, $loaded[0]['settings']['bot_token']);
    }

    public function testPartialTelegramSavePreservesOmittedTargetFieldsWhileExplicitBlankClearsChatId(): void
    {
        $data                       = $this->v2SettingsArray('conn_1', 'smtp-secret');
        $data['features']['alerts'] = $this->alerts([
            $this->alertInstance('ch_tele001', 'telegram', ['bot_token' => self::BOT_TOKEN, 'chat_id' => '-1001234567890']),
        ]);
        $this->freshService()->saveSettings($data);

        $data['features']['alerts']['channels'][0]['enabled']  = false;
        $data['features']['alerts']['channels'][0]['settings'] = [];
        $this->freshService()->saveSettings($data);

        $this->assertFalse($this->freshService()->load()->getFeatures()['alerts']['channels'][0]['enabled']);
        $this->assertSame(['bot_token' => self::BOT_TOKEN, 'chat_id' => '-1001234567890'], $this->loadedAlertSettings(0));

        $data['features']['alerts']['channels'][0]['settings'] = ['chat_id' => ''];
        $this->freshService()->saveSettings($data);

        $this->assertSame('', $this->loadedAlertSettings(0)['chat_id']);
    }

    public function testEncryptSecretsMigrationEncryptsExistingPlaintextInstall(): void
    {
        $this->storeOptions($this->v2SettingsArray('conn_1', 'plain-install-secret'));

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
        $this->storeOptions($this->v2SettingsArray('conn_1', 'legacy-plaintext'));

        $previousVersion   = Config::getOption('version');
        $previousDbVersion = Config::getOption('db_version');

        Config::updateOption('version', Config::VERSION, true);
        Config::updateOption('db_version', '1.1', true);

        wp_set_current_user(1);
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

    /**
     * Encrypt/decrypt must tolerate connections with no credentials at all.
     */
    public function testConnectionWithEmptyCredentialsRoundTripsWithoutError(): void
    {
        $data                                  = $this->v2SettingsArray('conn_1', 'unused');
        $data['connections'][0]['credentials'] = [];

        $this->freshService()->saveSettings($data);

        $loaded = $this->freshService()->load()->getConnections()->byId('conn_1');
        $this->assertNotNull($loaded);
        $this->assertSame([], $loaded->getCredentials());
    }

    /**
     * Unrecoverable ciphertext degrades to empty string rather than crashing the admin screen.
     */
    public function testCorruptedCiphertextDegradesToEmptyStringWithoutFatal(): void
    {
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
        $service->saveSettings($this->v2SettingsArray('conn_1', 'real-secret'));

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
        $service->saveSettings($this->v2SettingsArray('conn_abc', 'keep-me'));

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
        $service->saveSettings($this->v2SettingsArray('conn_abc', 'keep-me'));

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

        $this->freshService()->persistConnectionProvisioning($connId, [], [
            'webhook_provisioning_status'     => 'failed',
            'webhook_provisioning_reason'     => 'provider returned 401',
            'webhook_provisioning_updated_at' => 1700000000,
        ]);

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

    public function testSaveOAuthGrantStoresTokensAndAccountInOneWrite(): void
    {
        $connId = $this->oauthDraftId();
        $writes = 0;
        $count  = static function () use (&$writes): void {
            ++$writes;
        };
        add_action('update_option_' . Config::withPrefix('options'), $count);

        try {
            $saved = $this->freshService()->saveOAuthGrant($this->oauthGrant($connId, 'at-1', 'owner@example.com'));
        } finally {
            remove_action('update_option_' . Config::withPrefix('options'), $count);
        }

        $this->assertTrue($saved);
        $this->assertSame(1, $writes);
        $stored = $this->freshService()->connectionById($connId);
        $this->assertNotNull($stored);
        $this->assertSame('at-1', $stored->getCredentials()['access_token']['value']);
        $this->assertSame('rt-1', $stored->getCredentials()['refresh_token']['value']);
        $this->assertSame('owner@example.com', $stored->setting('oauth_account'));
        $this->assertSame($connId, $this->freshService()->load()->getDefaultConnectionId());
    }

    public function testSaveOAuthGrantTreatsAnUnchangedStoredGrantAsSaved(): void
    {
        $connId = $this->oauthDraftId();
        $grant  = $this->oauthGrant($connId, 'at-1', 'owner@example.com');
        $this->assertTrue($this->freshService()->saveOAuthGrant($grant));

        $this->assertTrue($this->withOptionWritesDiscarded(fn (): bool => $this->freshService()->saveOAuthGrant($grant)));
    }

    public function testSaveOAuthGrantReportsFailureWhenTheGrantWasNotStored(): void
    {
        $connId = $this->oauthDraftId();

        $saved = $this->withOptionWritesDiscarded(fn (): bool => $this->freshService()->saveOAuthGrant($this->oauthGrant($connId, 'at-1', 'owner@example.com')));

        $this->assertFalse($saved);
        $stored = $this->freshService()->connectionById($connId);
        $this->assertNotNull($stored);
        $this->assertArrayNotHasKey('access_token', $stored->getCredentials());
    }

    public function testRegularConnectionSaveStillCannotSpoofTheOAuthAccount(): void
    {
        $connId = $this->oauthDraftId();
        $this->assertTrue($this->freshService()->saveOAuthGrant($this->oauthGrant($connId, 'at-1', 'owner@example.com')));

        $this->freshService()->saveConnection($this->oauthGrant($connId, 'at-1', 'attacker@example.com'));

        $stored = $this->freshService()->connectionById($connId);
        $this->assertNotNull($stored);
        $this->assertSame('owner@example.com', $stored->setting('oauth_account'));
    }

    public function testTokenlessOAuthDraftDoesNotStealDefaultFromAWorkingConnection(): void
    {
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

        $this->freshService()->deleteConnection('conn_1');

        $loaded = $this->freshService()->load();
        $this->assertNull($loaded->getConnections()->byId('conn_1'));
        $this->assertSame('conn_2', $loaded->getDefaultConnectionId());
    }

    public function testDeleteConnectionPrunesRoutingRulesTargetingIt(): void
    {
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
        $this->assertSame('', $this->freshService()->load()->getDefaultConnectionId());
    }

    public function testDisconnectOAuthLeavesADifferentDefaultUntouched(): void
    {
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

    /**
     * Clearing tokens on non-OAuth connections is refused to avoid accidental default repointing.
     */
    public function testDisconnectOAuthRefusesANonOAuthConnection(): void
    {
        $this->freshService()->saveSettings($this->v2SettingsArray('conn_smtp', 'pw'));

        $this->assertFalse($this->freshService()->disconnectOAuth('conn_smtp'));
        $this->assertSame('conn_smtp', $this->freshService()->load()->getDefaultConnectionId());
    }

    public function testDisconnectOAuthRepointsDefaultToAnEnabledSibling(): void
    {
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

    /**
     * An enabled alerts blob holding the given channel instances.
     */
    private function alerts(array $channels): array
    {
        return ['enabled' => true, 'channels' => $channels];
    }

    /**
     * One enabled alert channel instance.
     */
    private function alertInstance(string $id, string $type, array $settings): array
    {
        return ['id' => $id, 'type' => $type, 'name' => '', 'enabled' => true, 'settings' => $settings];
    }

    /**
     * The decrypted settings of the stored alert instance at the given position.
     */
    private function loadedAlertSettings(int $index): array
    {
        return $this->freshService()->load()->getFeatures()['alerts']['channels'][$index]['settings'];
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

    /**
     * Store a tokenless Gmail OAuth draft and return its id.
     */
    private function oauthDraftId(): string
    {
        $connId = $this->freshService()->upsertConnection($this->oauthGrant('', '', ''));
        $this->assertNotNull($connId);

        return $connId;
    }

    /**
     * Gmail connection payload as the OAuth callback builds it; an empty access token yields a tokenless draft.
     *
     * @return array<string,mixed>
     */
    private function oauthGrant(string $connId, string $accessToken, string $account): array
    {
        $credentials = ['client_secret' => ['source' => 'database', 'value' => 'cs']];
        if ($accessToken !== '') {
            $credentials['access_token']  = ['source' => 'database', 'value' => $accessToken];
            $credentials['refresh_token'] = ['source' => 'database', 'value' => 'rt-1'];
        }

        return [
            'id'           => $connId,
            'provider'     => 'gmail',
            'kind'         => 'api',
            'name'         => 'Gmail',
            'enabled'      => true,
            'fromEmail'    => 'a@example.com',
            'fromName'     => 'A',
            'replyToEmail' => '',
            'settings'     => ['client_id' => 'abc.apps.googleusercontent.com', 'oauth_account' => $account],
            'credentials'  => $credentials,
        ];
    }

    /**
     * Run a callback while every write to the mail-config option is discarded, as update_option() does for a no-op or failed write.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withOptionWritesDiscarded(callable $callback)
    {
        $keepOld = static fn ($value, $old) => $old;
        add_filter('pre_update_option_' . Config::withPrefix('options'), $keepOld, 10, 2);

        try {
            return $callback();
        } finally {
            remove_filter('pre_update_option_' . Config::withPrefix('options'), $keepOld, 10);
        }
    }
}
