<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\CLI\ImportCommand;
use BitApps\SMTP\Config;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Import\ImportService;
use BitApps\SMTP\Mail\Import\PluginImporterRegistry;
use BitApps\SMTP\Tests\Fake\FakeCliReporter;

/**
 * Tests end-to-end import of SMTP plugin settings; verifies persistence, encryption, and CLI dry-run.
 *
 * @internal
 *
 * @coversNothing
 */
final class PluginImportTest extends IntegrationTestCase
{
    private const WP_MAIL_SMTP_OPTION = 'wp_mail_smtp';

    private const EASY_WP_SMTP_OPTION = 'swpsmtp_options';

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(self::WP_MAIL_SMTP_OPTION);
        delete_option(self::EASY_WP_SMTP_OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(self::WP_MAIL_SMTP_OPTION);
        delete_option(self::EASY_WP_SMTP_OPTION);
        parent::tearDown();
    }

    /**
     * WP Mail SMTP's encrypted smtp.pass can't be reversed without the source plugin, so the password is left empty.
     */
    public function testImportingWpMailSmtpMapsFieldsButOmitsThePasswordWhenTheSourceDecryptorIsAbsent(): void
    {
        update_option(self::WP_MAIL_SMTP_OPTION, [
            'mail' => ['from_email' => 'from@example.org', 'from_name' => 'From', 'mailer' => 'smtp'],
            'smtp' => [
                'host'       => 'smtp.example.org',
                'port'       => 465,
                'encryption' => 'ssl',
                'auth'       => true,
                'user'       => 'mailer',
                'pass'       => 'plaintext-secret',
            ],
        ]);

        $connId = $this->service()->import('wp_mail_smtp');
        $this->assertNotNull($connId);

        $connection = (new MailConfigService())->load()->getConnections()->byId($connId);
        $this->assertNotNull($connection);
        $this->assertSame('other_smtp', $connection->getProvider());
        $this->assertSame('smtp', $connection->getKind());
        $this->assertSame('from@example.org', $connection->getFromEmail());
        $this->assertSame('smtp.example.org', $connection->setting('host'));
        $this->assertSame(465, (int) $connection->setting('port'));
        $this->assertSame('ssl', $connection->setting('encryption'));
        $this->assertTrue((bool) $connection->setting('auth'));
        $this->assertSame('mailer', $connection->setting('username'));
        $this->assertSame('', $connection->getCredentials()['password']['value']);
    }

    public function testImportingEasyWpSmtpDecodesPasswordAndPersistsEncrypted(): void
    {
        update_option(self::EASY_WP_SMTP_OPTION, [
            'from_email_field' => 'from@example.org',
            'from_name_field'  => 'From',
            'smtp_settings'    => [
                'host'            => 'smtp.example.org',
                'port'            => 587,
                'type_encryption' => 'tls',
                'autentication'   => 'yes',
                'username'        => 'mailer',
                'password'        => base64_encode('easy-secret'),
            ],
        ]);

        $connId = $this->service()->import('easy_wp_smtp');
        $this->assertNotNull($connId);

        $raw = Config::getOption('options');
        $this->assertStringStartsWith(
            'bsenc:v1:',
            $raw['connections'][0]['credentials']['password']['value']
        );

        $connection = (new MailConfigService())->load()->getConnections()->byId($connId);
        $this->assertNotNull($connection);
        $this->assertSame('tls', $connection->setting('encryption'));
        $this->assertTrue((bool) $connection->setting('auth'));
        $this->assertSame('easy-secret', $connection->getCredentials()['password']['value']);
    }

    public function testAvailableListsOnlyDetectedPlugins(): void
    {
        update_option(self::EASY_WP_SMTP_OPTION, [
            'smtp_settings' => ['host' => 'smtp.example.org'],
        ]);

        $available = $this->service()->available();

        $this->assertSame([['key' => 'easy_wp_smtp', 'label' => 'Easy WP SMTP']], $available);
    }

    public function testCommandDryRunNeitherPersistsNorPrintsTheSecret(): void
    {
        update_option(self::EASY_WP_SMTP_OPTION, [
            'smtp_settings' => ['host' => 'smtp.example.org', 'password' => base64_encode('do-not-print')],
        ]);

        $reporter = new FakeCliReporter();
        (new ImportCommand($this->service()))->run(['easy_wp_smtp'], ['dry-run' => true], $reporter);

        $this->assertCount(0, (new MailConfigService())->load()->getConnections()->all());

        $output = implode("\n", $reporter->lines);
        $this->assertStringContainsString('********', $output);
        $this->assertStringNotContainsString('do-not-print', $output);
    }

    public function testCommandListsDetectedPluginsWhenNoKeyGiven(): void
    {
        update_option(self::WP_MAIL_SMTP_OPTION, [
            'mail' => ['mailer' => 'smtp'],
            'smtp' => ['host' => 'smtp.example.org'],
        ]);

        $reporter = new FakeCliReporter();
        (new ImportCommand($this->service()))->run([], [], $reporter);

        $this->assertCount(1, $reporter->rendered);
        $this->assertSame(['key', 'label'], $reporter->rendered[0]['fields']);
        $this->assertSame('wp_mail_smtp', $reporter->rendered[0]['items'][0]['key']);
    }

    private function service(): ImportService
    {
        return new ImportService(PluginImporterRegistry::withDefaults(), new MailConfigService());
    }
}
