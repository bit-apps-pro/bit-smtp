<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Plugin;

/**
 * Exercises the pre_wp_mail dispatch loop end-to-end: priority fallback, all-fail, the disabled
 * escape hatch, single-connection BC, and third-party phpmailer_init preservation, against mailpit.
 *
 * @internal
 *
 * @coversNothing
 */
final class WpMailFallbackTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLogs();
    }

    public function testFallsBackToNextConnectionWhenPrimaryIsUnreachable(): void
    {
        $this->storeV2([
            $this->connection('conn_primary', '127.0.0.1', 2),
            $this->connection('conn_fallback', self::SMTP_HOST, self::SMTP_PORT),
        ], 'conn_primary', ['conn_fallback']);

        $sent = wp_mail('to@example.org', 'Fallback Subject', 'Body');

        $this->assertTrue($sent);
        $this->assertNotEmpty($this->mailpitMessages(), 'the fallback connection should deliver to mailpit');
        $this->assertFalse(Plugin::instance()->smtpProvider()->isFailed());

        $logs = $this->logs();
        $this->assertCount(2, $logs, 'both the failed primary and successful fallback attempts should be logged');
        $this->assertSame(Log::SUCCESS, $logs[0]->status, 'newest log is the successful fallback');
        $this->assertSame(Log::ERROR, $logs[1]->status, 'older log is the failed primary');
    }

    public function testReturnsFalseAndDeliversNothingWhenAllConnectionsFail(): void
    {
        $this->storeV2([
            $this->connection('conn_primary', '127.0.0.1', 2),
            $this->connection('conn_fallback', '127.0.0.1', 3),
        ], 'conn_primary', ['conn_fallback']);

        $sent = wp_mail('to@example.org', 'Doomed', 'Body');

        $this->assertFalse($sent);
        $this->assertEmpty($this->mailpitMessages());
        $this->assertTrue(Plugin::instance()->smtpProvider()->isFailed());

        $logs = $this->logs();
        $this->assertCount(2, $logs, 'every failed attempt should be logged');
        $this->assertSame(Log::ERROR, $logs[0]->status);
        $this->assertSame(Log::ERROR, $logs[1]->status);
    }

    public function testSingleConnectionDeliversPreservingBackwardCompatibility(): void
    {
        $this->storeV2([
            $this->connection('conn_only', self::SMTP_HOST, self::SMTP_PORT),
        ], 'conn_only', []);

        $sent = wp_mail('to@example.org', 'Single', 'Body');

        $this->assertTrue($sent);
        $this->assertNotEmpty($this->mailpitMessages());
        $this->assertFalse(Plugin::instance()->smtpProvider()->isFailed());
        $this->assertCount(1, $this->logs(), 'a single-connection dispatch must log exactly one row');
    }

    public function testNativePathSendIsStillLoggedWhenRoutingIsDisabled(): void
    {
        // BC: with SMTP routing disabled but logging on, the plugin logged native wp_mail sends via
        // the wp_mail_succeeded/failed actions. That must survive the pre_wp_mail refactor.
        $this->useRealPhpMailer();
        $this->storeV2([
            $this->connection('conn_only', self::SMTP_HOST, self::SMTP_PORT),
        ], 'conn_only', [], false);

        wp_mail('to@example.org', 'Native', 'Body');

        $this->assertCount(1, $this->logs(), 'a native-path send must still produce a log row');
    }

    public function testDisabledPluginDefersToNativeWpMail(): void
    {
        // Connection points at mailpit, but the plugin is disabled: pre_wp_mail must return null and
        // our transport must not run, so nothing reaches mailpit through our path.
        $this->storeV2([
            $this->connection('conn_only', self::SMTP_HOST, self::SMTP_PORT),
        ], 'conn_only', [], false);

        $result = Plugin::instance()->smtpProvider()->onPreWpMail(null, [
            'to'      => 'to@example.org',
            'subject' => 'Disabled',
            'message' => 'Body',
        ]);

        $this->assertNull($result);

        wp_mail('to@example.org', 'Disabled', 'Body');
        $this->assertEmpty($this->mailpitMessages());
    }

    public function testResendUpdatesItsExistingLogOnceWithTheFinalOutcome(): void
    {
        // Seed a previously-failed log, then resend it over a primary that fails and a fallback that
        // succeeds: the originating row must be updated once to SUCCESS, never duplicated per attempt.
        Plugin::instance()->logger()->save(Log::ERROR, [
            'subject' => 'Seeded',
            'to'      => ['to@example.org'],
            'message' => 'Body',
        ], ['boom']);
        $seedId = $this->logs()[0]->id;

        $this->storeV2([
            $this->connection('conn_primary', '127.0.0.1', 2),
            $this->connection('conn_fallback', self::SMTP_HOST, self::SMTP_PORT),
        ], 'conn_primary', ['conn_fallback']);

        Plugin::instance()->smtpProvider()->retry()->setRetryLogId((int) $seedId)->setDebug(true);
        $sent = wp_mail('to@example.org', 'Seeded', 'Body');

        $this->assertTrue($sent);
        $this->assertNotEmpty($this->mailpitMessages());
        $this->assertFalse(Plugin::instance()->smtpProvider()->isFailed());

        $this->assertCount(1, $this->logs(), 'a resend must update in place, not insert new rows');
        $updated = Plugin::instance()->logger()->get((int) $seedId);
        $this->assertNotNull($updated);
        $this->assertSame(Log::SUCCESS, $updated->status);
        $this->assertSame(1, $updated->retry_count, 'exactly one update should have run for the resend');

        // The bridge is a shared singleton; clear the debug flag so it does not leak into later tests.
        Plugin::instance()->smtpProvider()->setDebug(false);
    }

    public function testThirdPartyPhpmailerInitListenerReachesTheWire(): void
    {
        $this->storeV2([
            $this->connection('conn_only', self::SMTP_HOST, self::SMTP_PORT),
        ], 'conn_only', []);

        $listener = static function ($mailer): void {
            $mailer->addCustomHeader('X-Thirdparty-Init', 'applied');
        };
        add_action('phpmailer_init', $listener);

        $sent = wp_mail('to@example.org', 'Init Hook', 'Body');
        remove_action('phpmailer_init', $listener);

        $this->assertTrue($sent);

        $delivered = $this->latestMailpitMessage();
        $this->assertNotNull($delivered);

        $headers = $this->mailpitHeaders($delivered['ID']);
        $this->assertSame('applied', $headers['X-Thirdparty-Init'][0] ?? null);
    }

    /**
     * @param array<int,array<string,mixed>> $connections
     * @param string[]                       $fallbackIds
     */
    private function storeV2(array $connections, string $defaultId, array $fallbackIds, bool $enabled = true): void
    {
        $this->storeOptions([
            'schema_version'          => 2,
            'enabled'                 => $enabled,
            'default_connection_id'   => $defaultId,
            'fallback_connection_ids' => $fallbackIds,
            'connections'             => $connections,
            'features'                => [],
        ]);

        Plugin::instance()->mailConfigService()->reload();
    }

    /**
     * @return array<string,mixed>
     */
    private function connection(string $id, string $host, int $port): array
    {
        return [
            'id'           => $id,
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => $id,
            'enabled'      => true,
            'fromEmail'    => 'from@example.org',
            'fromName'     => 'From',
            'replyToEmail' => '',
            'settings'     => ['host' => $host, 'port' => $port, 'encryption' => 'none', 'auth' => false],
            'credentials'  => [],
        ];
    }

    /**
     * @return Log[] newest first
     */
    private function logs(): array
    {
        return Log::desc()->get();
    }

    private function clearLogs(): void
    {
        global $wpdb;
        $table = (new Log())->getTable();
        $wpdb->query("TRUNCATE TABLE {$table}");
    }
}
