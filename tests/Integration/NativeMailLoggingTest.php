<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Plugin;

/**
 * Verifies native-path logging captures From/Cc/Bcc from headers when dispatch is unavailable.
 *
 * @internal
 *
 * @coversNothing
 */
final class NativeMailLoggingTest extends IntegrationTestCase
{
    /**
     * Disables plugin dispatch and installs a capturing MockPHPMailer so core wp_mail runs natively without a network send.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storeOptions(['status' => false]);
        Plugin::instance()->mailConfigService()->reload();

        reset_phpmailer_instance();

        $this->truncateTables((new Log())->getTable());
    }

    public function testNativeDeferredSendLogsTheSenderAndCcBccFromHeaders(): void
    {
        wp_mail(
            'to@example.org',
            'Native Deferred Log',
            'Body',
            [
                'From: Sender <sender@example.org>',
                'Cc: cc@example.org',
                'Bcc: bcc@example.org',
            ]
        );

        $log = Log::where('subject', 'Native Deferred Log')->first();
        $this->assertInstanceOf(Log::class, $log);

        $this->assertSame('Sender <sender@example.org>', $log->sender);
        $this->assertSame(['cc@example.org'], $log->cc);
        $this->assertSame(['bcc@example.org'], $log->bcc);
    }

    /**
     * Guards the blank-sender bug: the effective wp_mail default sender must fill it.
     */
    public function testNativeDeferredSendWithoutAFromHeaderStillLogsANonEmptySender(): void
    {
        wp_mail('to@example.org', 'Native Default Sender', 'Body');

        $log = Log::where('subject', 'Native Default Sender')->first();
        $this->assertInstanceOf(Log::class, $log);

        $this->assertNotSame('', $log->sender);
        $this->assertStringContainsString('@', (string) $log->sender);
    }
}
