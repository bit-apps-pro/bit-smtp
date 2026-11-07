<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Plugin;
use WP_Error;

/**
 * Exercises the live wp_mail send path end-to-end through WpMailBridge against mailpit.
 *
 * @internal
 *
 * @coversNothing
 */
final class WpMailBridgeTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useRealPhpMailer();
        $this->configureMailpitTransport();
    }

    public function testWpMailDeliversToMailpitAndMarksSendSuccessful(): void
    {
        $sent = wp_mail('to@example.org', 'Subj', 'Body');

        $this->assertTrue($sent);
        $this->assertNotEmpty($this->mailpitMessages());
        $this->assertFalse(Plugin::instance()->smtpProvider()->isFailed());
    }

    /**
     * Debug output is cleared per send; first recipient must not leak into second send's transcript.
     */
    public function testResetForSendClearsDebugOutputBetweenConsecutiveSends(): void
    {
        $bridge = Plugin::instance()->smtpProvider();
        $bridge->setDebug(true);

        wp_mail('first-recipient@example.org', 'First', 'Body');
        $firstDebug = $bridge->getDebugOutput();
        $this->assertNotEmpty($firstDebug, 'debug capture should populate on the first send');
        $this->assertStringContainsStringIgnoringCase('first-recipient@example.org', implode('', $firstDebug));

        wp_mail('second-recipient@example.org', 'Second', 'Body');
        $secondDebug = implode('', $bridge->getDebugOutput());

        $this->assertStringNotContainsStringIgnoringCase('first-recipient@example.org', $secondDebug);
        $this->assertStringContainsStringIgnoringCase('second-recipient@example.org', $secondDebug);
    }

    public function testCcAndBccHeadersAreCapturedOnTheLogRow(): void
    {
        $this->truncateTables((new Log())->getTable());

        wp_mail(
            'to@example.org',
            'CcBcc RoundTrip',
            'Body',
            ['Cc: cc@example.org', 'Bcc: bcc@example.org']
        );

        $log = Log::where('subject', 'CcBcc RoundTrip')->first();
        $this->assertInstanceOf(Log::class, $log);
        $this->assertSame(['cc@example.org'], $log->cc);
        $this->assertSame(['bcc@example.org'], $log->bcc);
    }

    public function testAllInvalidToFailsWithoutDeferringToNativeMail(): void
    {
        $this->truncateTables((new Log())->getTable());
        $nativeRan = false;
        $failure   = null;
        $native    = static function () use (&$nativeRan): void {
            $nativeRan = true;
        };
        $listener = static function (WP_Error $error) use (&$failure): void {
            $failure = $error;
        };
        add_action('phpmailer_init', $native);
        add_action('wp_mail_failed', $listener);

        try {
            $sent = wp_mail('john@typo', 'All invalid', 'Body', ['Cc: real@example.org']);
        } finally {
            remove_action('phpmailer_init', $native);
            remove_action('wp_mail_failed', $listener);
        }

        $this->assertFalse($sent);
        $this->assertFalse($nativeRan, 'core wp_mail must not take over and send the Cc through mail()');
        $this->assertEmpty($this->mailpitMessages());
        $this->assertInstanceOf(WP_Error::class, $failure);
        $this->assertStringContainsString('No valid recipient address', $failure->get_error_message());

        $log = Log::where('subject', 'All invalid')->first();
        $this->assertInstanceOf(Log::class, $log);
        $this->assertSame(Log::ERROR, (int) $log->status);
        $this->assertSame(FailureCategory::INVALID_RECIPIENT, $log->failure_class);
        $this->assertSame(['real@example.org'], $log->cc);
        $this->assertNotSame('', (string) $log->sender);
    }

    public function testEmptyToKeepsTheNativeFallback(): void
    {
        $nativeRan = false;
        $native    = static function () use (&$nativeRan): void {
            $nativeRan = true;
        };
        add_action('phpmailer_init', $native);

        try {
            wp_mail('', 'Empty to', 'Body');
        } finally {
            remove_action('phpmailer_init', $native);
        }

        $this->assertTrue($nativeRan);
    }

    /**
     * Store SMTP config and refresh the cached service to apply new options.
     */
    private function configureMailpitTransport(): void
    {
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
    }
}
