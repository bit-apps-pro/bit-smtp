<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Notifications\Channels\TelegramFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use RuntimeException;
use Throwable;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class TelegramFailureNotificationChannelTest extends BaseUnitTestCase
{
    private const TOKEN = '123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_bloginfo')->justReturn('Example Site');
        Functions\when('home_url')->justReturn('https://example.test/');
    }

    public function testPostsPlainTextToTelegramSendMessageWithASignedChatId(): void
    {
        $endpoint = 'https://api.telegram.org/bot' . self::TOKEN . '/sendMessage';
        Functions\expect('wp_safe_remote_post')
            ->once()
            ->with($endpoint, Mockery::on(static function (array $args): bool {
                return $args['body']['chat_id'] === '-1001234567890'
                    && \is_string($args['body']['text'] ?? null)
                    && $args['body']['disable_web_page_preview'] === 'true'
                    && $args['timeout']                          === 5
                    && $args['redirection']                      === 0
                    && !isset($args['body']['parse_mode'])
                    && str_starts_with($args['body']['text'], '[TEST] ');
            }))
            ->andReturn(['response' => ['code' => 200], 'body' => '{"ok":true,"result":{"message_id":1}}']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"ok":true,"result":{"message_id":1}}');

        $channel = new TelegramFailureNotificationChannel();

        $this->assertSame('telegram', $channel->key());
        $this->assertTrue($channel->send(FailureNotification::forTest(), [
            'bot_token' => self::TOKEN,
            'chat_id'   => '-1001234567890',
        ]));
    }

    public function testRejectsInvalidTokenAndUnsignedChatIdWithoutARequest(): void
    {
        Functions\expect('wp_safe_remote_post')->never();
        $channel = new TelegramFailureNotificationChannel();

        $this->assertFalse($channel->send(FailureNotification::forTest(), ['bot_token' => 'bad token', 'chat_id' => '-1001234567890']));
        $this->assertFalse($channel->send(FailureNotification::forTest(), ['bot_token' => self::TOKEN, 'chat_id' => 'chat-name']));
    }

    public function testReturnsFalseForRemoteNon2xxAndProviderErrorsWithoutLeakingToken(): void
    {
        $endpoint = 'https://api.telegram.org/bot' . self::TOKEN . '/sendMessage';
        Functions\when('wp_safe_remote_post')->justReturn(new WP_Error('http_request_failed', 'failed ' . $endpoint));
        Functions\when('is_wp_error')->justReturn(true);

        $channel = new TelegramFailureNotificationChannel();

        try {
            $this->assertFalse($channel->send(FailureNotification::forTest(), [
                'bot_token' => self::TOKEN,
                'chat_id'   => '123456789',
            ]));
        } catch (Throwable $exception) {
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());

            throw $exception;
        }
    }

    public function testContainsAnExceptionFromTheHttpApiWithoutOutputOrTokenLeak(): void
    {
        Functions\expect('wp_safe_remote_post')
            ->once()
            ->andThrow(new RuntimeException('request failed for token ' . self::TOKEN));
        $channel = new TelegramFailureNotificationChannel();

        $this->expectOutputString('');
        $this->assertFalse($channel->send(FailureNotification::forTest(), [
            'bot_token' => self::TOKEN,
            'chat_id'   => '123456789',
        ]));
    }

    public function testReturnsFalseForANon2xxOrOkFalseProviderResponse(): void
    {
        Functions\when('wp_safe_remote_post')->justReturn(['response' => ['code' => 429], 'body' => '{"ok":false}']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(429);

        $channel  = new TelegramFailureNotificationChannel();
        $settings = ['bot_token' => self::TOKEN, 'chat_id' => '123456789'];
        $this->assertFalse($channel->send(FailureNotification::forTest(), $settings));

        Functions\when('wp_safe_remote_post')->justReturn(['response' => ['code' => 200], 'body' => '{"ok":false,"description":"denied"}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"ok":false,"description":"denied"}');
        $this->assertFalse($channel->send(FailureNotification::forTest(), $settings));
    }
}
