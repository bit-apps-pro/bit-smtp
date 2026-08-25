<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Plugin;

/**
 * When the plugin defers a send to core wp_mail (no usable connection), the native-path log listeners
 * must still capture the From/Cc/Bcc that live inside the raw headers — not persist blanks — matching
 * what the dispatch path records.
 *
 * @internal
 *
 * @coversNothing
 */
final class NativeMailLoggingTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Plugin dispatch disabled, so pre_wp_mail defers to core wp_mail (the native path under test).
        $this->storeOptions(['status' => false]);
        Plugin::instance()->mailConfigService()->reload();

        // A fresh capturing MockPHPMailer so core wp_mail "succeeds" without a real network send.
        reset_phpmailer_instance();

        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . (new Log())->getTable());
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

    public function testNativeDeferredSendWithoutAFromHeaderStillLogsANonEmptySender(): void
    {
        wp_mail('to@example.org', 'Native Default Sender', 'Body');

        $log = Log::where('subject', 'Native Default Sender')->first();
        $this->assertInstanceOf(Log::class, $log);

        // The bug this guards: a blank sender. The effective wp_mail default (wordpress@<domain>) fills it.
        $this->assertNotSame('', $log->sender);
        $this->assertStringContainsString('@', (string) $log->sender);
    }
}
