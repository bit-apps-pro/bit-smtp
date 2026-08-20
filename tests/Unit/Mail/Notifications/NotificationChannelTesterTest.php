<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\MailSettings;
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
            'email'   => ['email'],
            'webhook' => ['webhook'],
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
