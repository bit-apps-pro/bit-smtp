<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Notifications\FailureIncident;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class FailureNotificationTest extends BaseUnitTestCase
{
    private const STARTED_AT = 1700000000;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_bloginfo')->justReturn('Example Site');
        Functions\when('home_url')->justReturn('https://example.test/');
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_date')->alias(static fn (string $format, int $timestamp): string => (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'))->format($format));
    }

    public function testOpenedPayloadKeepsLegacyFieldsAndAddsTheIncidentWithoutTheMessageBody(): void
    {
        $error = new WP_Error('wp_mail_failed', 'Connection refused', [
            'to'       => ['recipient@example.com'],
            'subject'  => 'Invoice',
            'message'  => 'sensitive message body',
            'attempts' => [
                ['connection' => 'Primary', 'status' => 'failed', 'error' => 'Connection refused', 'response' => 'raw provider body'],
            ],
        ]);
        $connection = Connection::fromArray([
            'id'       => 'conn_1',
            'provider' => 'other_smtp',
            'kind'     => 'smtp',
            'name'     => 'Primary',
        ]);
        $incident = FailureIncident::start('incident-1', self::STARTED_AT, $error, $connection);

        $payload = FailureNotification::fromIncident(FailureNotification::EVENT_OPENED, $incident)->toArray();

        $this->assertSame([
            'event'      => 'send_failure.opened',
            'failed_at'  => '2023-11-14T22:13:20+00:00',
            'site'       => ['name' => 'Example Site', 'url' => 'https://example.test/'],
            'error'      => ['code' => 'wp_mail_failed', 'message' => 'Connection refused'],
            'mail'       => ['subject' => 'Invoice', 'recipients' => ['recipient@example.com']],
            'connection' => ['id' => 'conn_1', 'name' => 'Primary', 'provider' => 'other_smtp'],
            'attempts'   => [['connection' => 'Primary', 'status' => 'failed', 'error' => 'Connection refused']],
            'incident'   => [
                'id'               => 'incident-1',
                'started_at'       => '2023-11-14T22:13:20+00:00',
                'last_failed_at'   => '2023-11-14T22:13:20+00:00',
                'resolved_at'      => null,
                'failed_count'     => 1,
                'duration_seconds' => 0,
            ],
        ], $payload);
        $this->assertStringNotContainsString('sensitive message body', (string) wp_json_encode($payload));
        $this->assertStringNotContainsString('raw provider body', (string) wp_json_encode($payload));
    }

    public function testOpenedAlertReadsAsSendingIsFailing(): void
    {
        $notification = FailureNotification::fromIncident(FailureNotification::EVENT_OPENED, $this->incident(1, 0));

        $this->assertSame('send_failure.opened', $notification->eventType());
        $this->assertSame('[Bit SMTP] Example Site: Sending is failing', $notification->emailSubject());
        $this->assertStringStartsWith("Sending is failing\nSite: Example Site", $notification->emailBody());
    }

    public function testReminderReportsTheRunningCountSinceTheStart(): void
    {
        $notification = FailureNotification::fromIncident(FailureNotification::EVENT_REMINDER, $this->incident(3, 900));

        $this->assertSame('send_failure.reminder', $notification->eventType());
        $this->assertSame('Still failing — 3 emails failed since 22:13 UTC', $notification->headline());
        $this->assertSame('[Bit SMTP] Example Site: Still failing — 3 emails failed since 22:13 UTC', $notification->emailSubject());
        $this->assertSame(900, $notification->toArray()['incident']['duration_seconds']);
    }

    public function testResolvedReportsTheTotalAndDuration(): void
    {
        $incident     = $this->incident(12, 60)->withResolution(self::STARTED_AT + 4380);
        $notification = FailureNotification::fromIncident(FailureNotification::EVENT_RESOLVED, $incident);
        $payload      = $notification->toArray();

        $this->assertSame('Resolved — 12 emails failed over 1h 13m', $notification->headline());
        $this->assertSame('send_failure.resolved', $payload['event']);
        $this->assertSame('2023-11-14T23:26:20+00:00', $payload['incident']['resolved_at']);
        $this->assertSame(4380, $payload['incident']['duration_seconds']);
        $this->assertSame(12, $payload['incident']['failed_count']);
    }

    public function testHeadlinesUseTheSingularForOneEmailAndCompactDurations(): void
    {
        $short = $this->incident(1, 0)->withResolution(self::STARTED_AT + 45);
        $long  = $this->incident(2, 0)->withResolution(self::STARTED_AT + 2 * 86400 + 300);

        $this->assertSame('Resolved — 1 email failed over 45s', FailureNotification::fromIncident(FailureNotification::EVENT_RESOLVED, $short)->headline());
        $this->assertSame('Resolved — 2 emails failed over 2d 5m', FailureNotification::fromIncident(FailureNotification::EVENT_RESOLVED, $long)->headline());
    }

    public function testUnknownEventIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FailureNotification::fromIncident('email_send_failed', $this->incident(1, 0));
    }

    public function testTheChannelTestSampleIsAnOpenedTestAlert(): void
    {
        $notification = FailureNotification::forTest();

        $this->assertTrue($notification->isTest());
        $this->assertSame('send_failure.opened', $notification->eventType());
        $this->assertSame('test_notification', $notification->toArray()['error']['code']);
    }

    /**
     * An incident with the given failure count whose latest failure is $lastOffset seconds after the start.
     */
    private function incident(int $failures, int $lastOffset): FailureIncident
    {
        $error    = new WP_Error('wp_mail_failed', 'Timed out');
        $incident = FailureIncident::start('incident-1', self::STARTED_AT, $error, null);
        for ($i = 1; $i < $failures; ++$i) {
            $incident = $incident->withFailure(self::STARTED_AT + $lastOffset, $error, null);
        }

        return $incident;
    }
}
