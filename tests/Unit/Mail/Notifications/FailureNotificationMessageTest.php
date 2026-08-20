<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\FailureNotificationMessage;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class FailureNotificationMessageTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_bloginfo')->justReturn('Example Site');
        Functions\when('home_url')->justReturn('https://example.test/');
    }

    public function testRendersDeterministicBoundedPlainTextWithoutMailBody(): void
    {
        $notification = FailureNotification::fromError(new WP_Error('wp_mail_failed', str_repeat('failure ', 1000), [
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
        $this->assertStringContainsString('Email send failed', $first);
        $this->assertStringContainsString('Site: Example Site', $first);
        $this->assertStringNotContainsString('private email body', $first);
    }

    public function testLabelsAnExplicitTestNotification(): void
    {
        $notification = FailureNotification::forTest();

        $this->assertTrue($notification->isTest());
        $this->assertStringStartsWith('[TEST] ', FailureNotificationMessage::plainText($notification));
    }

    public function testKeepsUtf8ValidAtADynamicFieldByteBoundary(): void
    {
        $notification = FailureNotification::fromError(new WP_Error('wp_mail_failed', 'Failed', [
            'subject' => str_repeat('a', 296) . '€' . 'abcd',
        ]));

        $text = FailureNotificationMessage::plainText($notification);

        $this->assertLessThanOrEqual(FailureNotificationMessage::MAX_LENGTH, \strlen($text));
        $this->assertSame(1, preg_match('//u', $text));
        $this->assertStringContainsString('Subject: ' . str_repeat('a', 296) . '...', $text);
    }

    public function testKeepsUtf8ValidWhenTheTotalByteLimitTruncates(): void
    {
        $notification = FailureNotification::fromError(new WP_Error('wp_mail_failed', 'Failed', [
            'to'      => array_fill(0, 10, str_repeat('é', 150)),
            'subject' => str_repeat('a', 300),
        ]));

        $text = FailureNotificationMessage::plainText($notification);

        $this->assertLessThanOrEqual(FailureNotificationMessage::MAX_LENGTH, \strlen($text));
        $this->assertStringEndsWith('...', $text);
        $this->assertSame(1, preg_match('//u', $text));
    }

    public function testNormalErrorNotificationIsNotMarkedAsTest(): void
    {
        $notification = FailureNotification::fromError(new WP_Error('wp_mail_failed', 'Failed'));

        $this->assertFalse($notification->isTest());
        $this->assertStringNotContainsString('[TEST]', FailureNotificationMessage::plainText($notification));
    }
}
