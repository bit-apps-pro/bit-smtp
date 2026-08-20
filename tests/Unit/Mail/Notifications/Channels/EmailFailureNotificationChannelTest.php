<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Notifications\Channels\EmailFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\NotificationDispatchGuard;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use WP_Error;

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
                '[Bit SMTP] Email sending failed on Example Site',
                $notification->emailBody(),
                ['Content-Type: text/plain; charset=UTF-8']
            )
            ->andReturnUsing(static function () use (&$guardActive): bool {
                $guardActive = NotificationDispatchGuard::isActive();

                return true;
            });

        $channel = new EmailFailureNotificationChannel();

        $this->assertTrue($channel->send($notification, ['recipients' => ['ops@example.com']]));
        $this->assertTrue($guardActive);
        $this->assertFalse(NotificationDispatchGuard::isActive());
    }

    public function testMissingRecipientsSkipsWpMail(): void
    {
        Functions\expect('wp_mail')->never();

        $channel = new EmailFailureNotificationChannel();

        $this->assertFalse($channel->send($this->notification(), []));
    }

    private function notification(): FailureNotification
    {
        return FailureNotification::fromError(new WP_Error('wp_mail_failed', 'Failed'));
    }
}
