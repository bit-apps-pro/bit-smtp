<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Notifications\Channels\SlackFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use Throwable;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class SlackFailureNotificationChannelTest extends BaseUnitTestCase
{
    private const WEBHOOK_URL = 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_bloginfo')->justReturn('Example Site');
        Functions\when('home_url')->justReturn('https://example.test/');
        Functions\when('wp_json_encode')->alias('json_encode');
    }

    public function testPostsPlainTextToAnExactSlackIncomingWebhookUrl(): void
    {
        Functions\expect('wp_safe_remote_post')
            ->once()
            ->with(self::WEBHOOK_URL, Mockery::on(static function (array $args): bool {
                $payload = json_decode($args['body'], true);

                return $args['headers']     === ['Content-Type' => 'application/json']
                    && $args['timeout']     === 5
                    && $args['redirection'] === 0
                    && \is_array($payload)
                    && \is_string($payload['text'] ?? null)
                    && str_starts_with($payload['text'], '[TEST] ');
            }))
            ->andReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);

        $channel = new SlackFailureNotificationChannel();

        $this->assertSame('slack', $channel->key());
        $this->assertTrue($channel->send(FailureNotification::forTest(), ['webhook_url' => self::WEBHOOK_URL]));
    }

    public function testRejectsUrlsOutsideTheExactSlackIncomingWebhookHostAndPath(): void
    {
        Functions\expect('wp_safe_remote_post')->never();
        $channel = new SlackFailureNotificationChannel();

        foreach ([
            'http://hooks.slack.com/services/T/B/X',
            'https://hooks.slack.com/api/services/T/B/X',
            'https://hooks.slack.com.evil.test/services/T/B/X',
            'https://evil.test/services/T/B/X',
            'https://hooks.slack.com/services/',
        ] as $url) {
            $this->assertFalse($channel->send(FailureNotification::forTest(), ['webhook_url' => $url]));
        }
    }

    public function testReturnsFalseForRemoteAndNonSuccessResponsesWithoutLeakingWebhookCredential(): void
    {
        $secretUrl = self::WEBHOOK_URL;
        Functions\when('wp_safe_remote_post')->justReturn(new WP_Error('http_request_failed', 'request failed for ' . $secretUrl));
        Functions\when('is_wp_error')->justReturn(true);

        $channel = new SlackFailureNotificationChannel();

        try {
            $result = $channel->send(FailureNotification::forTest(), ['webhook_url' => $secretUrl]);
            $this->assertFalse($result);
        } catch (Throwable $exception) {
            $this->assertStringNotContainsString($secretUrl, $exception->getMessage());

            throw $exception;
        }
    }

    public function testReturnsFalseForANon2xxResponse(): void
    {
        Functions\when('wp_safe_remote_post')->justReturn(['response' => ['code' => 500]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);

        $channel = new SlackFailureNotificationChannel();

        $this->assertFalse($channel->send(FailureNotification::forTest(), ['webhook_url' => self::WEBHOOK_URL]));
    }
}
