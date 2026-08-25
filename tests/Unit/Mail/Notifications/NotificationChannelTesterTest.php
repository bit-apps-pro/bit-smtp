<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Notifications\Channels\EmailFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\Channels\WebhookFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\FailureNotificationChannelRegistry;
use BitApps\SMTP\Mail\Notifications\NotificationChannelTester;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
final class NotificationChannelTesterTest extends BaseUnitTestCase
{
    public function testItSendsATestNotificationUsingSavedDecryptedChannelSettings(): void
    {
        $settings = ['enabled' => true, 'bot_token' => '123456:decrypted-bot-token-value', 'chat_id' => '-1001234567890'];
        $channel  = $this->channel('telegram');
        $channel->shouldReceive('send')
            ->once()
            ->with(Mockery::on(static fn (FailureNotification $notification): bool => $notification->isTest()), $settings)
            ->andReturn(true);

        $tester = new NotificationChannelTester(
            $this->config(['telegram' => $settings]),
            new FailureNotificationChannelRegistry([$channel])
        );

        $this->assertTrue($tester->send('telegram'));
    }

    public function testItSendsATestNotificationThroughTheDiscordChannel(): void
    {
        $settings = ['enabled' => true, 'webhook_url' => 'https://discord.com/api/webhooks/123/decrypted-token'];
        $channel  = $this->channel('discord');
        $channel->shouldReceive('send')
            ->once()
            ->with(Mockery::on(static fn (FailureNotification $notification): bool => $notification->isTest()), $settings)
            ->andReturn(true);

        $tester = new NotificationChannelTester(
            $this->config(['discord' => $settings]),
            new FailureNotificationChannelRegistry([$channel])
        );

        $this->assertTrue($tester->send('discord'));
    }

    public function testItSendsATestNotificationThroughTheEmailChannel(): void
    {
        $settings = ['enabled' => true, 'recipients' => ['ops@example.test']];
        $channel  = $this->channel('email');
        $channel->shouldReceive('send')
            ->once()
            ->with(Mockery::on(static fn (FailureNotification $notification): bool => $notification->isTest()), $settings)
            ->andReturn(true);

        $tester = new NotificationChannelTester(
            $this->config(['email' => $settings]),
            new FailureNotificationChannelRegistry([$channel])
        );

        $this->assertTrue($tester->send('email'));
    }

    public function testItSendsATestNotificationThroughTheWebhookChannel(): void
    {
        $settings = ['enabled' => true, 'url' => 'https://example.test/hooks/bit-smtp', 'signing_secret' => 'whsec_decrypted-secret'];
        $channel  = $this->channel('webhook');
        $channel->shouldReceive('send')
            ->once()
            ->with(Mockery::on(static fn (FailureNotification $notification): bool => $notification->isTest()), $settings)
            ->andReturn(true);

        $tester = new NotificationChannelTester(
            $this->config(['webhook' => $settings]),
            new FailureNotificationChannelRegistry([$channel])
        );

        $this->assertTrue($tester->send('webhook'));
    }

    public function testItRejectsAnUnconfiguredEmailChannel(): void
    {
        $tester = new NotificationChannelTester(
            $this->config(['email' => ['enabled' => true, 'recipients' => []]]),
            new FailureNotificationChannelRegistry([new EmailFailureNotificationChannel()])
        );

        $this->assertFalse($tester->send('email'));
    }

    public function testItRejectsAnUnconfiguredWebhookChannel(): void
    {
        $tester = new NotificationChannelTester(
            $this->config(['webhook' => ['enabled' => true, 'url' => '', 'signing_secret' => '']]),
            new FailureNotificationChannelRegistry([new WebhookFailureNotificationChannel()])
        );

        $this->assertFalse($tester->send('webhook'));
    }

    #[DataProvider('unsupportedChannelProvider')]
    public function testItRejectsChannelsThatCannotBeTested(string $channel): void
    {
        $adapter = $this->channel('slack');
        $adapter->shouldNotReceive('send');

        $tester = new NotificationChannelTester(
            $this->config(['slack' => ['enabled' => true, 'webhook_url' => 'https://hooks.slack.com/services/T000/B000/secret']]),
            new FailureNotificationChannelRegistry([$adapter])
        );

        $this->assertFalse($tester->send($channel));
    }

    public static function unsupportedChannelProvider(): array
    {
        return [
            'unknown' => ['unknown'],
            'sms'     => ['sms'],
        ];
    }

    private function config(array $alerts): MailConfigService
    {
        $config = Mockery::mock(MailConfigService::class);
        $config->shouldReceive('load')->andReturn(MailSettings::fromArray([
            'schema_version'          => 2,
            'enabled'                 => false,
            'default_connection_id'   => '',
            'fallback_connection_ids' => [],
            'connections'             => [],
            'features'                => ['alerts' => $alerts],
        ]));

        return $config;
    }

    private function channel(string $key): FailureNotificationChannelInterface
    {
        $channel = Mockery::mock(FailureNotificationChannelInterface::class);
        $channel->shouldReceive('key')->once()->andReturn($key);

        return $channel;
    }
}
