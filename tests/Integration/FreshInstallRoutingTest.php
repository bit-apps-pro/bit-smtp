<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Plugin;

/**
 * Proves wp_mail routes through Bit SMTP whenever an enabled, sendable connection exists, with no global on/off flag.
 *
 * @internal
 *
 * @coversNothing
 */
final class FreshInstallRoutingTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables((new Log())->getTable());
        Plugin::instance()->mailConfigService()->reload();
    }

    public function testConnectionSavedOnAFreshInstallCarriesWpMailToMailpit(): void
    {
        $connectionId = Plugin::instance()->mailConfigService()->upsertConnection($this->mailpitConnection(true));
        $this->assertNotNull($connectionId);

        $sent = wp_mail('to@example.org', 'Fresh install', 'Body');

        $this->assertTrue($sent);
        $this->assertCount(1, $this->mailpitMessages(), 'the saved connection must deliver to mailpit');
        $this->assertSame('Mailpit', $this->latestLog()->connection, 'Bit SMTP, not native wp_mail, must carry the send');
    }

    public function testLegacyStoredGlobalFlagSetToFalseNoLongerBlocksRouting(): void
    {
        $this->storeOptions([
            'schema_version'          => 2,
            'enabled'                 => false,
            'default_connection_id'   => 'conn_mailpit',
            'fallback_connection_ids' => [],
            'connections'             => [['id' => 'conn_mailpit'] + $this->mailpitConnection(true)],
            'features'                => [],
        ]);
        Plugin::instance()->mailConfigService()->reload();

        $sent = wp_mail('to@example.org', 'Old flag', 'Body');

        $this->assertTrue($sent);
        $this->assertCount(1, $this->mailpitMessages());
        $this->assertSame('Mailpit', $this->latestLog()->connection);
    }

    public function testOnlyDisabledConnectionsLeaveWpMailToWordPress(): void
    {
        Plugin::instance()->mailConfigService()->upsertConnection($this->mailpitConnection(false));

        $result = Plugin::instance()->smtpProvider()->onPreWpMail(null, [
            'to'      => 'to@example.org',
            'subject' => 'Disabled',
            'message' => 'Body',
        ]);

        $this->assertNull($result);
    }

    public function testLegacyOptionWithSmtpOffMigratesToADisabledConnectionThatDoesNotRoute(): void
    {
        $this->storeOptions([
            'status'     => false,
            'smtp_host'  => self::SMTP_HOST,
            'port'       => self::SMTP_PORT,
            'encryption' => 'none',
            'smtp_auth'  => false,
        ]);
        $service = Plugin::instance()->mailConfigService()->reload();

        $this->assertFalse($service->load()->getConnections()->first()->isEnabled());
        $this->assertNull(Plugin::instance()->smtpProvider()->onPreWpMail(null, [
            'to'      => 'to@example.org',
            'subject' => 'Legacy off',
            'message' => 'Body',
        ]));
    }

    /**
     * Other SMTP connection payload pointed at the docker mailpit, as the connection form posts it.
     *
     * @return array<string,mixed>
     */
    private function mailpitConnection(bool $enabled): array
    {
        return [
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'Mailpit',
            'enabled'      => $enabled,
            'fromEmail'    => 'from@example.org',
            'fromName'     => 'From',
            'replyToEmail' => '',
            'settings'     => ['host' => self::SMTP_HOST, 'port' => self::SMTP_PORT, 'encryption' => 'none', 'auth' => false],
            'credentials'  => [],
        ];
    }

    /**
     * Most recent log row written by the send under test.
     */
    private function latestLog(): Log
    {
        $logs = Log::desc()->get()->all();
        $this->assertNotEmpty($logs, 'the send must be logged');

        return $logs[0];
    }
}
