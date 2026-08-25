<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Mail\Notifications\Channels\DiscordFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\HealthNotification;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class DiscordFailureNotificationChannelTest extends BaseUnitTestCase
{
    private const WEBHOOK_URL = 'https://discord.com/api/webhooks/123456789012345678/aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789_abcdefghijklmno';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_bloginfo')->justReturn('Example Site');
        Functions\when('home_url')->justReturn('https://example.test/');
        Functions\when('wp_json_encode')->alias('json_encode');
    }

    public function testPostsContentToAnExactDiscordWebhookUrl(): void
    {
        Functions\expect('wp_safe_remote_post')
            ->once()
            ->with(self::WEBHOOK_URL, Mockery::on(static function (array $args): bool {
                $payload = json_decode($args['body'], true);

                return $args['headers']     === ['Content-Type' => 'application/json']
                    && $args['timeout']     === 5
                    && $args['redirection'] === 0
                    && \is_array($payload)
                    && \is_string($payload['content'] ?? null)
                    && str_starts_with($payload['content'], '[TEST] ');
            }))
            ->andReturn(['response' => ['code' => 204]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(204);

        $channel = new DiscordFailureNotificationChannel();

        $this->assertSame('discord', $channel->key());
        $this->assertTrue($channel->send(FailureNotification::forTest(), ['webhook_url' => self::WEBHOOK_URL]));
    }

    public function testAcceptsTheDiscordappComHostAlias(): void
    {
        $url = 'https://discordapp.com/api/webhooks/123456789012345678/aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789_abcdefghijklmno';
        Functions\expect('wp_safe_remote_post')->once()->with($url, Mockery::any())->andReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);

        $this->assertTrue((new DiscordFailureNotificationChannel())->send(FailureNotification::forTest(), ['webhook_url' => $url]));
    }

    public function testRejectsUrlsOutsideTheExactDiscordHostAndPath(): void
    {
        Functions\expect('wp_safe_remote_post')->never();
        $channel = new DiscordFailureNotificationChannel();

        foreach ([
            'http://discord.com/api/webhooks/123/token',
            'https://discord.com/webhooks/123/token',
            'https://discord.com.evil.test/api/webhooks/123/token',
            'https://evil.test/api/webhooks/123/token',
            'https://canary.discord.com/api/webhooks/123/token',
            'https://discord.com/api/webhooks/',
        ] as $url) {
            $this->assertFalse($channel->send(FailureNotification::forTest(), ['webhook_url' => $url]));
        }
    }

    #[DataProvider('disallowedWebhookUrlComponents')]
    public function testRejectsWebhookUrlsWithDisallowedComponents(string $url): void
    {
        Functions\expect('wp_safe_remote_post')->never();

        $this->assertFalse((new DiscordFailureNotificationChannel())->send(FailureNotification::forTest(), ['webhook_url' => $url]));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function disallowedWebhookUrlComponents(): array
    {
        return [
            'explicit port' => ['https://discord.com:443/api/webhooks/123/token'],
            'userinfo'      => ['https://user@discord.com/api/webhooks/123/token'],
            'query'         => ['https://discord.com/api/webhooks/123/token?wait=true'],
            'fragment'      => ['https://discord.com/api/webhooks/123/token#secret'],
        ];
    }

    public function testReturnsFalseWhenTheWebhookUrlIsMissing(): void
    {
        Functions\expect('wp_safe_remote_post')->never();

        $this->assertFalse((new DiscordFailureNotificationChannel())->send(FailureNotification::forTest(), []));
    }

    public function testTruncatesContentToDiscordsTwoThousandCharacterCeiling(): void
    {
        $notification = Mockery::mock(NotificationMessage::class);
        $notification->shouldReceive('chatText')->andReturn(str_repeat('x', 2500));

        Functions\expect('wp_safe_remote_post')
            ->once()
            ->with(self::WEBHOOK_URL, Mockery::on(static function (array $args): bool {
                $payload = json_decode($args['body'], true);

                return \is_array($payload) && \strlen((string) ($payload['content'] ?? '')) === 2000;
            }))
            ->andReturn(['response' => ['code' => 204]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(204);

        $this->assertTrue((new DiscordFailureNotificationChannel())->send($notification, ['webhook_url' => self::WEBHOOK_URL]));
    }

    public function testRendersFailureAndHealthNotificationsViaChatText(): void
    {
        foreach ([FailureNotification::forTest(), $this->healthNotification()] as $notification) {
            $expected = $notification->chatText();
            Functions\expect('wp_safe_remote_post')
                ->once()
                ->with(self::WEBHOOK_URL, Mockery::on(static function (array $args) use ($expected): bool {
                    $payload = json_decode($args['body'], true);

                    return \is_array($payload) && ($payload['content'] ?? null) === $expected;
                }))
                ->andReturn(['response' => ['code' => 204]]);
            Functions\when('is_wp_error')->justReturn(false);
            Functions\when('wp_remote_retrieve_response_code')->justReturn(204);

            $this->assertTrue((new DiscordFailureNotificationChannel())->send($notification, ['webhook_url' => self::WEBHOOK_URL]));
        }
    }

    public function testReturnsFalseForRemoteErrorsWithoutLeakingTheWebhookCredential(): void
    {
        Functions\when('wp_safe_remote_post')->justReturn(new WP_Error('http_request_failed', 'request failed for ' . self::WEBHOOK_URL));
        Functions\when('is_wp_error')->justReturn(true);

        $channel = new DiscordFailureNotificationChannel();

        try {
            $this->assertFalse($channel->send(FailureNotification::forTest(), ['webhook_url' => self::WEBHOOK_URL]));
        } catch (Throwable $exception) {
            $this->assertStringNotContainsString(self::WEBHOOK_URL, $exception->getMessage());

            throw $exception;
        }
    }

    public function testContainsAnExceptionFromTheHttpApiWithoutOutputOrCredentialLeak(): void
    {
        Functions\expect('wp_safe_remote_post')
            ->once()
            ->andThrow(new RuntimeException('request failed for ' . self::WEBHOOK_URL));

        $this->expectOutputString('');
        $this->assertFalse((new DiscordFailureNotificationChannel())->send(FailureNotification::forTest(), ['webhook_url' => self::WEBHOOK_URL]));
    }

    public function testReturnsFalseForANon2xxResponse(): void
    {
        Functions\when('wp_safe_remote_post')->justReturn(['response' => ['code' => 500]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);

        $this->assertFalse((new DiscordFailureNotificationChannel())->send(FailureNotification::forTest(), ['webhook_url' => self::WEBHOOK_URL]));
    }

    private function healthNotification(): HealthNotification
    {
        $connection = Connection::fromArray([
            'id'       => 'conn_1',
            'provider' => 'gmail',
            'kind'     => 'api',
            'name'     => 'My Gmail',
        ]);

        return HealthNotification::unhealthy($connection, ConnectionHealth::fromArray([
            'status'     => HealthStatus::UNHEALTHY,
            'last_error' => 'SMTP connect failed',
        ]));
    }
}
