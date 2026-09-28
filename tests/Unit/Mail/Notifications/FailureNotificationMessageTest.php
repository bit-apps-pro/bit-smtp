<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Mail\Notifications\FailureIncident;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\FailureNotificationMessage;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class FailureNotificationMessageTest extends BaseUnitTestCase
{
    private const STARTED_AT = 1700000000;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_bloginfo')->justReturn('Example Site');
        Functions\when('home_url')->justReturn('https://example.test/');
        Functions\when('wp_date')->alias(static fn (string $format, int $timestamp): string => (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'))->format($format));
    }

    public function testRendersDeterministicBoundedPlainTextWithoutMailBody(): void
    {
        $notification = $this->opened(new WP_Error('wp_mail_failed', str_repeat('failure ', 1000), [
            'to'       => array_fill(0, 30, 'recipient@example.test'),
            'subject'  => str_repeat('subject ', 1000),
            'message'  => 'private email body',
            'attempts' => array_fill(0, 20, [
                'connection' => str_repeat('connection ', 100),
                'status'     => 'failed',
                'error'      => str_repeat('provider detail ', 100),
            ]),
        ]));

        $first  = FailureNotificationMessage::plainText($notification);
        $second = FailureNotificationMessage::plainText($notification);

        $this->assertSame($first, $second);
        $this->assertLessThanOrEqual(FailureNotificationMessage::MAX_LENGTH, \strlen($first));
        $this->assertStringStartsWith("Sending is failing\nSite: Example Site", $first);
        $this->assertStringNotContainsString('private email body', $first);
    }

    public function testOpenedTextCarriesTheFailureDetails(): void
    {
        $text = FailureNotificationMessage::plainText($this->opened(new WP_Error('wp_mail_failed', 'Timed out', [
            'to'      => 'one@example.com,two@example.com',
            'subject' => 'Report',
        ])));

        $this->assertSame(implode("\n", [
            'Sending is failing',
            'Site: Example Site',
            'URL: https://example.test/',
            'Failed at: 2023-11-14T22:13:20+00:00',
            'Error [wp_mail_failed]: Timed out',
            'Connection: WordPress default mailer',
            'Subject: Report',
            'Recipients: one@example.com, two@example.com',
        ]), $text);
    }

    public function testReminderTextCarriesTheRunningTotals(): void
    {
        $error    = new WP_Error('wp_mail_failed', 'Timed out');
        $incident = FailureIncident::start('incident-1', self::STARTED_AT, $error, null)
            ->withFailure(self::STARTED_AT + 600, new WP_Error('smtp_auth', 'Auth rejected'), null);

        $text = FailureNotificationMessage::plainText(FailureNotification::fromIncident(FailureNotification::EVENT_REMINDER, $incident));

        $this->assertSame(implode("\n", [
            'Still failing — 2 emails failed since 22:13 UTC',
            'Site: Example Site',
            'URL: https://example.test/',
            'Failing since: 2023-11-14T22:13:20+00:00',
            'Last failure: 2023-11-14T22:23:20+00:00',
            'Failed emails: 2',
            'Last error [smtp_auth]: Auth rejected',
            'Last connection: WordPress default mailer',
        ]), $text);
    }

    public function testResolvedTextCarriesTheFinalTotalsAndResolutionTime(): void
    {
        $incident = FailureIncident::start('incident-1', self::STARTED_AT, new WP_Error('wp_mail_failed', 'Timed out'), null)
            ->withResolution(self::STARTED_AT + 4380);

        $text = FailureNotificationMessage::plainText(FailureNotification::fromIncident(FailureNotification::EVENT_RESOLVED, $incident));

        $this->assertStringStartsWith("Resolved — 1 email failed over 1h 13m\n", $text);
        $this->assertStringContainsString("Resolved at: 2023-11-14T23:26:20+00:00\nFailed emails: 1", $text);
    }

    public function testLabelsAnExplicitTestNotification(): void
    {
        $notification = FailureNotification::forTest();

        $this->assertTrue($notification->isTest());
        $this->assertStringStartsWith('[TEST] Sending is failing', FailureNotificationMessage::plainText($notification));
    }

    public function testKeepsUtf8ValidAtADynamicFieldByteBoundary(): void
    {
        $text = FailureNotificationMessage::plainText($this->opened(new WP_Error('wp_mail_failed', 'Failed', [
            'subject' => str_repeat('a', 296) . '€' . 'abcd',
        ])));

        $this->assertLessThanOrEqual(FailureNotificationMessage::MAX_LENGTH, \strlen($text));
        $this->assertSame(1, preg_match('//u', $text));
        $this->assertStringContainsString('Subject: ' . str_repeat('a', 296) . '...', $text);
    }

    public function testKeepsUtf8ValidWhenTheTotalByteLimitTruncates(): void
    {
        $text = FailureNotificationMessage::plainText($this->opened(new WP_Error('wp_mail_failed', 'Failed', [
            'to'      => array_fill(0, 10, str_repeat('é', 150)),
            'subject' => str_repeat('a', 300),
        ])));

        $this->assertLessThanOrEqual(FailureNotificationMessage::MAX_LENGTH, \strlen($text));
        $this->assertStringEndsWith('...', $text);
        $this->assertSame(1, preg_match('//u', $text));
    }

    public function testNormalAlertIsNotMarkedAsTest(): void
    {
        $notification = $this->opened(new WP_Error('wp_mail_failed', 'Failed'));

        $this->assertFalse($notification->isTest());
        $this->assertStringNotContainsString('[TEST]', FailureNotificationMessage::plainText($notification));
    }

    /**
     * The opened alert for a fresh incident started by $error.
     */
    private function opened(WP_Error $error): FailureNotification
    {
        return FailureNotification::fromIncident(
            FailureNotification::EVENT_OPENED,
            FailureIncident::start('incident-1', self::STARTED_AT, $error, null)
        );
    }
}
