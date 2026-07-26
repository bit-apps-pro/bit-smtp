<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class FailureNotificationTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_bloginfo')->justReturn('Example Site');
        Functions\when('home_url')->justReturn('https://example.test/');
        Functions\when('wp_json_encode')->alias('json_encode');
    }

    public function testPayloadContainsOutcomeMetadataWithoutTheMessageBody(): void
    {
        $error = new WP_Error('wp_mail_failed', 'Connection refused', [
            'to'       => ['recipient@example.com'],
            'subject'  => 'Invoice',
            'message'  => 'sensitive message body',
            'attempts' => [
                ['connection' => 'Primary', 'status' => 'failed', 'error' => 'Connection refused'],
            ],
        ]);
        $connection = Connection::fromArray([
            'id'       => 'conn_1',
            'provider' => 'other_smtp',
            'kind'     => 'smtp',
            'name'     => 'Primary',
        ]);

        $notification = FailureNotification::fromError($error, $connection);
        $payload      = $notification->toArray();

        $this->assertSame('email_send_failed', $payload['event']);
        $this->assertSame('Connection refused', $payload['error']['message']);
        $this->assertSame(['recipient@example.com'], $payload['mail']['recipients']);
        $this->assertSame('conn_1', $payload['connection']['id']);
        $this->assertArrayNotHasKey('message', $payload['mail']);
        $this->assertStringNotContainsString('sensitive message body', (string) wp_json_encode($payload));
    }

    public function testEmailContentIdentifiesTheFailureEpisode(): void
    {
        $notification = FailureNotification::fromError(new WP_Error('wp_mail_failed', 'Timed out', [
            'to'      => 'one@example.com,two@example.com',
            'subject' => 'Report',
        ]));

        $this->assertSame('[Bit SMTP] Email sending failed on Example Site', $notification->emailSubject());
        $this->assertStringContainsString('Error: Timed out', $notification->emailBody());
        $this->assertStringContainsString('WordPress default mailer', $notification->emailBody());
        $this->assertStringContainsString('one@example.com, two@example.com', $notification->emailBody());
    }
}
