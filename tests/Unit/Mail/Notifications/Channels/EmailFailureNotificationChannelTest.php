<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Notifications\Channels\EmailFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\FailureIncident;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\NotificationDispatchGuard;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
final class EmailFailureNotificationChannelTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_bloginfo')->justReturn('Example Site');
        Functions\when('home_url')->justReturn('https://example.test/');
    }

    public function testSendsToConfiguredRecipients(): void
    {
        $notification = $this->notification();
        $guardActive  = false;
        Functions\expect('wp_mail')
            ->once()
            ->with(
                ['ops@example.com'],
                '[Bit SMTP] Example Site: Sending is failing',
                $notification->emailBody(),
                ['Content-Type: text/plain; charset=UTF-8']
            )
            ->andReturnUsing(static function () use (&$guardActive): bool {
                $guardActive = NotificationDispatchGuard::isActive();

                return true;
            });

        $channel = new EmailFailureNotificationChannel();

        $this->assertTrue($channel->send($notification, ['recipients' => ['ops@example.com']])->ok());
        $this->assertTrue($guardActive);
        $this->assertFalse(NotificationDispatchGuard::isActive());
    }

    public function testMissingRecipientsSkipsWpMail(): void
    {
        Functions\expect('wp_mail')->never();

        $channel = new EmailFailureNotificationChannel();

        $this->assertFalse($channel->send($this->notification(), [])->ok());
    }

    public function testAFailedWpMailReportsThatPhpMailCouldNotSend(): void
    {
        Functions\when('wp_mail')->justReturn(false);

        $result = (new EmailFailureNotificationChannel())->send($this->notification(), ['recipients' => ['ops@example.com']]);

        $this->assertFalse($result->ok());
        $this->assertSame('PHP mail() could not send the message.', $result->reason());
    }

    private function notification(): FailureNotification
    {
        return FailureNotification::fromIncident(
            FailureNotification::EVENT_OPENED,
            FailureIncident::fromArray(['id' => 'incident-1', 'started_at' => 1700000000])
        );
    }
}
