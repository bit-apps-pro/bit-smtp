<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Notifications\Channels\WebhookFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class WebhookFailureNotificationChannelTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_bloginfo')->justReturn('Example Site');
        Functions\when('home_url')->justReturn('https://example.test/');
        Functions\when('wp_json_encode')->alias('json_encode');
    }

    public function testPostsJsonAndAcceptsA2xxResponse(): void
    {
        $notification = $this->notification();
        $secret       = 'whsec_abcdefghijklmnopqrstuvwxyz012345';
        Functions\expect('wp_safe_remote_post')
            ->once()
            ->with('https://hooks.example.com/failure', Mockery::on(static function (array $args) use ($secret): bool {
                $expectedSignature = hash_hmac('sha256', '1700000000.' . $args['body'], $secret);

                return $args['redirection']                             === 0
                    && $args['timeout']                                 === 5
                    && $args['headers']['Content-Type']                 === 'application/json'
                    && $args['headers']['X-Bit-SMTP-Event']             === 'email_send_failed'
                    && $args['headers']['X-Bit-SMTP-Timestamp']         === '1700000000'
                    && $args['headers']['X-Bit-SMTP-Signature']         === 'v1=' . $expectedSignature
                    && json_decode($args['body'], true)['event']        === 'email_send_failed';
            }))
            ->andReturn(['response' => ['code' => 204]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(204);

        $channel = new WebhookFailureNotificationChannel(static fn (): int => 1700000000);

        $this->assertTrue($channel->send($notification, [
            'url'            => 'https://hooks.example.com/failure',
            'signing_secret' => $secret,
        ]));
    }

    public function testUnsafeRequestFailureReturnsFalse(): void
    {
        $notification = $this->notification();
        Functions\when('wp_safe_remote_post')->justReturn(new WP_Error('http_request_failed', 'unsafe'));
        Functions\when('is_wp_error')->justReturn(true);

        $channel = new WebhookFailureNotificationChannel();

        $this->assertFalse($channel->send($notification, [
            'url'            => 'https://127.0.0.1/internal',
            'signing_secret' => 'whsec_abcdefghijklmnopqrstuvwxyz012345',
        ]));
    }

    public function testMissingSigningSecretDoesNotSendAnUnsignedWebhook(): void
    {
        Functions\expect('wp_safe_remote_post')->never();

        $channel = new WebhookFailureNotificationChannel();

        $this->assertFalse($channel->send($this->notification(), [
            'url' => 'https://hooks.example.com/failure',
        ]));
    }

    private function notification(): FailureNotification
    {
        return FailureNotification::fromError(new WP_Error('wp_mail_failed', 'Failed'));
    }
}
